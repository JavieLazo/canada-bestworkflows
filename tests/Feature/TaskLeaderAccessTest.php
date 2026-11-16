<?php

declare(strict_types=1);

use App\Livewire\Projects\Index;
use App\Livewire\Projects\Show;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\ChangeProjectStatus;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\Commands\CommentOnProjectTask;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\ScheduleProject;
use App\Modules\WorkManagement\Application\Commands\TaskBriefs;
use App\Modules\WorkManagement\Application\Commands\UpdateProject;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskFileView;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Task Leader — `projects.manager_id`, an ACCOUNT — runs their own project
 * even from a role without all_data and from no crew at all: they see the
 * whole project (every task, the whole-project totals), change its status by
 * hand, plan its start and its tasks' starts, and review its tasks — GROUP
 * work accepted or returned, INDIVIDUAL responses approved with every
 * member's private content and evidence in view — and they speak for
 * MANAGEMENT on its threads.
 *
 * Nothing more. The widening is keyed on the account holding PROJECTS: a
 * guest, an account without PROJECTS, a PROJECTS holder who leads nothing and
 * a leader named away get nothing from it, and no scope ever compiles to
 * `manager_id IS NULL` (which would hand over every leaderless project). An
 * all-data manager is never narrowed to what they lead, and My Tasks stays
 * pinned to the actor's own crew places. The leader never renames, re-dates,
 * re-leads, deletes or adds to the project, never attaches a brief, never
 * acts on a project they do not lead, and has NO authority over a task they
 * are assigned to — no decision, no Cancel, no status or start date by hand,
 * the ASSIGNEE side of its thread, and no view of a peer's INDIVIDUAL content.
 * Only an account whose role holds PROJECTS may be NAMED leader, and the edit
 * pickers offer only those (plus the leader already named).
 */

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function tleadAccount(array $modules, bool $allData, ?string $employeeId = null, bool $active = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Tlead '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId, 'is_active' => $active]);
}

/** Signs `$user` in for what follows, with a fresh Access for them. */
function tleadLogin(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
}

function tleadEmployee(string $first): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => $first,
        'last_name' => 'Tlead',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/** @param array<string, mixed> $attributes column overrides */
function tleadProject(string $code, string $name, ?User $leader, array $attributes = []): Project
{
    $project = new Project;
    $project->forceFill(array_merge([
        'code' => $code,
        'name' => $name,
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
        'due_date' => '2026-12-31',
        'manager_id' => $leader?->getKey(),
    ], $attributes))->save();

    return $project;
}

/**
 * @param  array<string, mixed>  $attributes  column overrides
 * @param  list<EmployeeProfile>  $crew  attached through the pivot, as the screens do
 */
function tleadTask(Project $project, string $code, array $attributes = [], array $crew = []): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => $code,
        'project_id' => $project->id,
        'title' => 'Task '.$code,
        'status' => 'PENDING',
        'priority' => 'NORMAL',
        'submission_mode' => 'GROUP',
        'assigned_at' => CarbonImmutable::now(),
    ], $attributes))->save();
    if ($crew !== []) {
        $task->syncAssignees(array_map(static fn (EmployeeProfile $e): string => $e->id, $crew), null);
    }

    return $task;
}

/**
 * A GROUP task handed in by `$by`, waiting on its reviewer.
 *
 * @param  list<EmployeeProfile>  $crew
 */
function tleadHandedIn(Project $project, string $code, EmployeeProfile $by, array $crew): ProjectTask
{
    return tleadTask($project, $code, [
        'status' => 'SUBMITTED',
        'submitted_at' => CarbonImmutable::now(),
        'submitted_by' => $by->id,
        'remarks' => 'Done po.',
    ], $crew);
}

/** A member's own response to an INDIVIDUAL task. */
function tleadResponse(ProjectTask $task, EmployeeProfile $member, string $remarks): ProjectTaskSubmission
{
    $row = new ProjectTaskSubmission;
    $row->forceFill([
        'task_id' => $task->id,
        'employee_id' => $member->id,
        'status' => 'SUBMITTED',
        'remarks' => $remarks,
        'submitted_at' => CarbonImmutable::now()->utc(),
        'revision' => 1,
    ])->save();

    return $row;
}

/** A stored file on the task: a BRIEF, or `$member`'s SUBMISSION evidence. */
function tleadFile(ProjectTask $task, string $kind, ?EmployeeProfile $member, string $name): ProjectTaskFile
{
    $path = UploadedFile::fake()->create($name, 4, 'application/pdf')->store('task-files', 'local');
    $file = new ProjectTaskFile;
    $file->fill(['task_id' => $task->id, 'kind' => $kind, 'file_name' => $name, 'mime' => 'application/pdf', 'size' => 4096, 'path' => (string) $path]);
    $file->employee_id = $member?->id;
    $file->save();

    return $file;
}

/**
 * Lea leads Harbour Survey (PRJ-801) from an account in a team-lead role —
 * PROJECTS and TASKS, no all_data — and works on none of its tasks: TSK-801
 * is Ana's, TSK-802 (completed) is Ben's, TSK-803 has nobody. Canal Works
 * (PRJ-802) is led by another team lead and holds Ana's handed-in TSK-804.
 * Orphan Plan (PRJ-803) has no leader and holds Ben's TSK-805.
 *
 * @return array{leader: User, other: User, lea: EmployeeProfile, ana: EmployeeProfile, ben: EmployeeProfile, anaUser: User, benUser: User, project: Project, foreign: Project, orphan: Project, t1: ProjectTask, t2: ProjectTask, t3: ProjectTask, foreignTask: ProjectTask, orphanTask: ProjectTask}
 */
function tleadFixture(bool $linkedLeader = true): array
{
    $lea = tleadEmployee('Lea');
    $ana = tleadEmployee('Ana');
    $ben = tleadEmployee('Ben');
    $leader = tleadAccount(['PROJECTS', 'TASKS'], false, $linkedLeader ? $lea->id : null);
    $other = tleadAccount(['PROJECTS', 'TASKS'], false, null);
    $project = tleadProject('PRJ-801', 'Harbour Survey', $leader);
    $foreign = tleadProject('PRJ-802', 'Canal Works', $other);
    $orphan = tleadProject('PRJ-803', 'Orphan Plan', null, ['status' => 'PLANNING']);

    return [
        'leader' => $leader,
        'other' => $other,
        'lea' => $lea,
        'ana' => $ana,
        'ben' => $ben,
        'anaUser' => tleadAccount(['TASKS'], false, $ana->id),
        'benUser' => tleadAccount(['TASKS'], false, $ben->id),
        'project' => $project,
        'foreign' => $foreign,
        'orphan' => $orphan,
        't1' => tleadTask($project, 'TSK-801', ['status' => 'IN_PROGRESS', 'due_date' => '2026-06-30'], [$ana]),
        't2' => tleadTask($project, 'TSK-802', ['status' => 'COMPLETED', 'due_date' => '2026-05-31', 'completed_at' => CarbonImmutable::now()], [$ben]),
        't3' => tleadTask($project, 'TSK-803'),
        'foreignTask' => tleadHandedIn($foreign, 'TSK-804', $ana, [$ana]),
        'orphanTask' => tleadTask($orphan, 'TSK-805', [], [$ben]),
    ];
}

/** @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query */
function tleadSql(Builder $query): string
{
    return $query->toSql();
}

/** The `IS NULL` a leader arm fed a null id would compile to, in any quoting. */
function tleadNullLeaderPattern(): string
{
    return '/manager_id["`\]]?\s+is\s+null/i';
}

/**
 * @param  iterable<int, ProjectView|ProjectTaskView>  $rows
 * @return list<string>
 */
function tleadCodes(iterable $rows): array
{
    $codes = [];
    foreach ($rows as $row) {
        $codes[] = $row->code;
    }
    sort($codes);

    return $codes;
}

/**
 * @param  iterable<int, ProjectTaskFileView|ProjectTaskFile>  $files
 * @return list<string>
 */
function tleadFileIds(iterable $files): array
{
    $ids = [];
    foreach ($files as $file) {
        $ids[] = $file->id;
    }
    sort($ids);

    return $ids;
}

/**
 * The options of ONE native select in rendered HTML: from its aria-label to
 * its closing tag, so a name elsewhere on the page (a filter, a summary
 * card) cannot satisfy the assertion.
 */
function tleadPicker(string $html, string $ariaLabel): string
{
    $start = strpos($html, 'aria-label="'.$ariaLabel.'"');
    expect($start)->not->toBeFalse();
    assert(is_int($start));
    $end = strpos($html, '</select>', $start);
    expect($end)->not->toBeFalse();
    assert(is_int($end));

    return substr($html, $start, $end - $start);
}

/** How many audit rows name this entity. */
function tleadAuditCount(string $entityId, ?string $action = null): int
{
    return AuditLog::query()->where('entity_id', $entityId)
        ->when($action !== null, static fn ($q) => $q->where('action', $action))->count();
}

/*
|--------------------------------------------------------------------------
| (a) Visibility — the leader arm, and only for whoever it belongs to
|--------------------------------------------------------------------------
*/

it('shows a leader on no crew their whole project, every task in it and its whole-project totals', function () {
    $f = tleadFixture();
    $actor = Access::forActor($f['leader']);
    $records = app(WorkRecords::class);

    expect(tleadCodes($records->projects($actor)))->toBe(['PRJ-801'])
        ->and(tleadCodes($records->tasks($actor, $f['project']->id)))->toBe(['TSK-801', 'TSK-802', 'TSK-803'])
        ->and(tleadCodes($records->tasks($actor)))->toBe(['TSK-801', 'TSK-802', 'TSK-803']);
    $totals = app(WorkSummary::class)->projects($actor, [$f['project']->id], '2026-01-15')[$f['project']->id];
    expect($totals->total)->toBe(3)->and($totals->completed)->toBe(1)->and($totals->unassigned)->toBe(1);

    tleadLogin($f['leader']);
    Livewire::test(Index::class)->set('section', 'projects')
        ->assertViewHas('visibleCount', 1)
        ->assertSee('Harbour Survey')
        ->assertDontSee('Canal Works')
        ->assertDontSee('Orphan Plan');

    Livewire::test(Show::class, ['project' => $f['project']->id])->set('taskView', 'board')
        ->assertSee('Harbour Survey')
        ->assertDontSee('Project not found.')
        ->assertViewHas('taskCount', 3)
        ->assertViewHas('visibleCount', 3)
        ->assertViewHas('runsProject', true)
        ->assertViewHas('canManage', false)
        ->assertSee('TSK-801')
        ->assertSee('TSK-802')
        ->assertSee('TSK-803')
        ->assertDontSee('TSK-804')
        ->assertDontSee('Delete Project');

    $this->get(route('projects.show', $f['project']->id))->assertOk()
        ->assertSee('Harbour Survey')->assertDontSee('Project not found.');
});

it('opens the led project to a leader account with no employee link', function () {
    $f = tleadFixture(linkedLeader: false);
    $actor = Access::forActor($f['leader']);
    expect($actor->rowVisibility('PROJECTS'))->toBe(Access::ROWS_NONE);

    expect(tleadCodes(app(WorkRecords::class)->projects($actor)))->toBe(['PRJ-801'])
        ->and(tleadCodes(app(WorkRecords::class)->tasks($actor, $f['project']->id)))->toBe(['TSK-801', 'TSK-802', 'TSK-803'])
        ->and(WorkScope::ledProjectIds($actor))->toBe([$f['project']->id]);

    tleadLogin($f['leader']);
    Livewire::test(Show::class, ['project' => $f['project']->id])->set('taskView', 'board')
        ->assertSee('Harbour Survey')
        ->assertDontSee('Project not found.')
        ->assertViewHas('taskCount', 3)
        ->assertSee('TSK-801')
        ->assertSee('TSK-803');
});

it('hides the project from a PROJECTS holder who neither leads it nor works on it', function () {
    $f = tleadFixture();
    $linked = tleadAccount(['PROJECTS', 'TASKS'], false, tleadEmployee('Sid')->id);
    $unlinked = tleadAccount(['PROJECTS'], false, null);

    foreach ([$linked, $unlinked] as $stranger) {
        $actor = Access::forActor($stranger);
        expect(app(WorkRecords::class)->projects($actor))->toBeEmpty()
            ->and(app(WorkRecords::class)->tasks($actor))->toBeEmpty()
            ->and(WorkScope::ledProjectIds($actor))->toBe([]);

        tleadLogin($stranger);
        Livewire::test(Show::class, ['project' => $f['project']->id])
            ->assertSee('Project not found.')
            ->assertDontSee('Harbour Survey');
        Livewire::test(Index::class)->assertViewHas('visibleCount', 0);
    }
});

it('gives nothing to a named leader whose role lacks PROJECTS', function () {
    $f = tleadFixture();
    foreach ([tleadAccount(['TASKS'], false, tleadEmployee('Tom')->id), tleadAccount(['TASKS'], false)] as $tasksOnly) {
        $f['project']->forceFill(['manager_id' => $tasksOnly->getKey()])->save();
        $actor = Access::forActor($tasksOnly);

        expect(WorkScope::projects($actor)->count())->toBe(0)
            ->and(WorkScope::tasks($actor)->count())->toBe(0)
            ->and(WorkScope::ledProjectIds($actor))->toBe([])
            ->and(app(WorkRecords::class)->projects($actor))->toBeEmpty()
            ->and(WorkRules::leadsProject($actor, $f['project']->fresh()))->toBeFalse();
        expect(fn () => app(ScheduleProject::class)->handle($actor, $f['project']->id, '2026-02-01'))
            ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());
    }
    expect($f['project']->fresh()?->start_date?->format('Y-m-d'))->toBe('2026-01-01');
});

it('takes the project away from a former leader once manager_id moves to another account', function () {
    $f = tleadFixture();
    expect(app(WorkRecords::class)->projects(Access::forActor($f['leader'])))->toHaveCount(1);

    $f['project']->forceFill(['manager_id' => $f['other']->getKey()])->save();
    $actor = Access::forActor($f['leader']);

    expect(app(WorkRecords::class)->projects($actor))->toBeEmpty()
        ->and(app(WorkRecords::class)->tasks($actor))->toBeEmpty()
        ->and(WorkScope::ledProjectIds($actor))->toBe([])
        ->and(app(ChangeProjectStatus::class)->handle($actor, $f['project']->id, 'ON_HOLD', 'ACTIVE'))->toBeFalse();
    expect(fn () => app(ScheduleProject::class)->handle($actor, $f['project']->id, '2026-02-01'))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());
    expect(fn () => app(ReviewTask::class)->handle($actor, $f['project']->id, $f['t1']->id, 'CANCELLED', ''))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());

    tleadLogin($f['leader']);
    Livewire::test(Show::class, ['project' => $f['project']->id])->assertSee('Project not found.');

    $project = $f['project']->fresh();
    expect($project?->status)->toBe('ACTIVE')
        ->and($project?->start_date?->format('Y-m-d'))->toBe('2026-01-01')
        ->and($f['t1']->fresh()?->status)->toBe('IN_PROGRESS')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('never narrows an all-data manager to the projects they lead', function () {
    $f = tleadFixture();
    $admin = tleadAccount(['PROJECTS'], true, tleadEmployee('Ada')->id);
    // The admin leads one project; every other one — led by others or by
    // nobody — must still be theirs to see.
    $f['foreign']->forceFill(['manager_id' => $admin->getKey()])->save();
    $actor = Access::forActor($admin);

    expect(tleadCodes(app(WorkRecords::class)->projects($actor)))->toBe(['PRJ-801', 'PRJ-802', 'PRJ-803'])
        ->and(WorkScope::tasks($actor)->count())->toBe(5)
        // ROWS_ALL adds no condition at all — no leader arm to narrow it.
        ->and(tleadSql(WorkScope::projects($actor)))->not->toContain('manager_id')
        ->and(tleadSql(WorkScope::tasks($actor)))->not->toContain('manager_id');

    tleadLogin($admin);
    Livewire::test(Index::class)->set('section', 'projects')
        ->assertViewHas('visibleCount', 3)
        ->assertSee('Harbour Survey')
        ->assertSee('Canal Works')
        ->assertSee('Orphan Plan');
});

it('never compiles a leader arm to manager_id IS NULL and gives a guest or a PROJECTS-less account no rows', function () {
    $f = tleadFixture();
    // A second leaderless project with no tasks at all.
    tleadProject('PRJ-804', 'Empty Orphan', null, ['status' => 'PLANNING']);
    $actors = [
        'guest' => Access::forActor(null),
        'forGuest' => Access::forGuest(),
        'tasks-only linked' => Access::forActor(tleadAccount(['TASKS'], false, tleadEmployee('Tia')->id)),
        'tasks-only unlinked' => Access::forActor(tleadAccount(['TASKS'], false)),
        'no modules' => Access::forActor(tleadAccount([], false)),
    ];

    foreach ($actors as $who => $actor) {
        foreach (['projects' => WorkScope::projects($actor), 'tasks' => WorkScope::tasks($actor)] as $what => $query) {
            expect(tleadSql($query))->not->toMatch(tleadNullLeaderPattern(), "{$who} {$what}");
            expect($query->count())->toBe(0, "{$who} {$what}");
        }
        expect(WorkScope::ledProjectIds($actor))->toBe([])
            ->and(app(WorkRecords::class)->projects($actor))->toBeEmpty();
    }

    // A PROJECTS holder who leads nothing gets a leader arm on their OWN id —
    // never IS NULL — and so still nothing.
    $nobody = Access::forActor(tleadAccount(['PROJECTS'], false));
    expect(tleadSql(WorkScope::projects($nobody)))->not->toMatch(tleadNullLeaderPattern())
        ->and(tleadSql(WorkScope::tasks($nobody)))->not->toMatch(tleadNullLeaderPattern())
        ->and(WorkScope::projects($nobody)->count())->toBe(0)
        ->and(WorkScope::tasks($nobody)->count())->toBe(0);
    expect(Project::query()->whereNull('manager_id')->count())->toBe(2);
});

it('keeps My Tasks pinned to the leader own crew places', function () {
    $f = tleadFixture();
    tleadTask($f['project'], 'TSK-806', [], [$f['lea']]);
    $actor = Access::forActor($f['leader']);
    $records = app(WorkRecords::class);

    expect(WorkScope::tasks($actor, own: true)->pluck('code')->all())->toBe(['TSK-806'])
        ->and(tleadCodes($records->tasks($actor, own: true)))->toBe(['TSK-806'])
        ->and($records->ownSummary($actor)['total'])->toBe(1)
        // …while the project view is the whole project.
        ->and(WorkScope::tasks($actor)->count())->toBe(4);
});

/*
|--------------------------------------------------------------------------
| (b) Authority — what the leader does off the crew
|--------------------------------------------------------------------------
*/

it('lets a leader off every crew change the project status from the header and through the command', function () {
    $f = tleadFixture();
    tleadLogin($f['leader']);

    Livewire::test(Show::class, ['project' => $f['project']->id])
        ->call('changeStatus', 'ON_HOLD', 'ACTIVE')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Project marked on hold.');
    expect($f['project']->fresh()?->status)->toBe('ON_HOLD')
        ->and(AuditLog::query()->where('action', 'PROJECT_STATUS_CHANGED')->where('entity_id', $f['project']->id)
            ->where('actor_id', $f['leader']->getKey())->count())->toBe(1);

    expect(app(ChangeProjectStatus::class)->handle(Access::forActor($f['leader']), $f['project']->id, 'PLANNING', 'ON_HOLD'))->toBeTrue()
        ->and($f['project']->fresh()?->status)->toBe('PLANNING')
        ->and(tleadAuditCount($f['project']->id, 'PROJECT_STATUS_CHANGED'))->toBe(2);
});

it('lets a leader plan the project start through ScheduleProject and the reduced edit dialog', function () {
    $f = tleadFixture();
    $actor = Access::forActor($f['leader']);

    expect(app(ScheduleProject::class)->handle($actor, $f['project']->id, '2026-02-01'))->toBe('PRJ-801')
        ->and($f['project']->fresh()?->start_date?->format('Y-m-d'))->toBe('2026-02-01');
    $audit = AuditLog::query()->where('action', 'PROJECT_UPDATED')->where('entity_id', $f['project']->id)->sole();
    expect($audit->details)->toContain('(start_date)')
        ->and($audit->getAttribute('actor_id'))->toBe($f['leader']->getKey());

    // The dialog a leader who is not a manager gets holds the start date
    // alone; anything else posted with it is ignored.
    tleadLogin($f['leader']);
    Livewire::test(Show::class, ['project' => $f['project']->id])
        ->call('openEdit')
        ->assertSet('editing', true)
        ->assertSee('Plan Project Start')
        ->assertDontSee('Save Changes')
        ->set('editName', 'Hijacked')
        ->set('editDueDate', '2027-06-30')
        ->set('editManagerId', (string) $f['other']->getKey())
        ->set('editStatus', 'CANCELLED')
        ->set('editStartDate', '2026-03-01')
        ->call('saveProject')
        ->assertSet('editing', false)
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Project PRJ-801 updated.');

    $project = $f['project']->fresh();
    expect($project?->start_date?->format('Y-m-d'))->toBe('2026-03-01')
        ->and($project?->name)->toBe('Harbour Survey')
        ->and($project?->due_date?->format('Y-m-d'))->toBe('2026-12-31')
        ->and($project?->status)->toBe('ACTIVE')
        ->and((int) $project?->manager_id)->toBe($f['leader']->getKey());
});

it('lets a leader plan a task start date and ignores every other field they post', function () {
    $f = tleadFixture();
    $actor = Access::forActor($f['leader']);

    $code = app(UpdateTaskDetails::class)->handle($actor, $f['project']->id, $f['t1']->id,
        new TaskDetails('URGENT', '2026-07-15', [$f['ben']->id], 'INDIVIDUAL', '2026-02-15'));

    expect($code)->toBe('TSK-801');
    $task = $f['t1']->fresh();
    expect($task?->start_date?->format('Y-m-d'))->toBe('2026-02-15')
        ->and($task?->priority)->toBe('NORMAL')
        ->and($task?->due_date?->format('Y-m-d'))->toBe('2026-06-30')
        ->and($task?->assigneeIds())->toBe([$f['ana']->id])
        ->and($task?->submission_mode)->toBe('GROUP');
    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $f['t1']->id)->sole();
    expect($audit->details)->toContain('updated (start_date)')
        ->and(Notification::query()->where('user_id', $f['benUser']->getKey())->count())->toBe(0);

    tleadLogin($f['leader']);
    Livewire::test(Show::class, ['project' => $f['project']->id])
        ->call('openTask', $f['t1']->id)
        ->assertSet('openTaskId', $f['t1']->id)
        ->call('toggleEditingDetails')
        ->assertSet('draftStartDate', '2026-02-15')
        ->set('draftPriority', 'LOW')
        ->set('draftStartDate', '2026-03-10')
        ->call('saveDetails')
        ->assertSet('editingDetails', false);
    expect($f['t1']->fresh()?->start_date?->format('Y-m-d'))->toBe('2026-03-10')
        ->and($f['t1']->fresh()?->priority)->toBe('NORMAL');
});

it('lets a leader accept and return handed-in GROUP work off the crew', function () {
    $f = tleadFixture();
    $accept = tleadHandedIn($f['project'], 'TSK-811', $f['ana'], [$f['ana']]);
    $return = tleadHandedIn($f['project'], 'TSK-812', $f['ben'], [$f['ben']]);

    app(ReviewTask::class)->handle(Access::forActor($f['leader']), $f['project']->id, $accept->id, 'COMPLETED', 'Well done.');

    $accept->refresh();
    expect($accept->status)->toBe('COMPLETED')
        ->and($accept->getAttribute('reviewed_by'))->toBe($f['leader']->getKey())
        ->and($accept->completed_at)->not->toBeNull()
        ->and($accept->review_remarks)->toBe('Well done.');
    $comment = TaskComment::query()->where('task_id', $accept->id)->sole();
    expect($comment->side)->toBe('MANAGEMENT')
        ->and($comment->event)->toBe('COMPLETED')
        ->and($comment->getAttribute('author_id'))->toBe($f['leader']->getKey())
        ->and(tleadAuditCount($accept->id, 'TASK_COMPLETED'))->toBe(1)
        ->and(Notification::query()->where('user_id', $f['anaUser']->getKey())->where('kind', 'TASK_COMPLETED')->count())->toBe(1);

    tleadLogin($f['leader']);
    $show = Livewire::test(Show::class, ['project' => $f['project']->id])->call('openTask', $return->id);
    $show->assertViewHas('openTaskMayReview', true)->assertViewHas('openTaskRunnable', true);
    $show->set('remarks', 'Add the site photos.')
        ->call('review', 'RETURNED')
        ->assertSet('reviewOpen', false);

    $return->refresh();
    expect($return->status)->toBe('RETURNED')
        ->and($return->getAttribute('reviewed_by'))->toBe($f['leader']->getKey())
        ->and($return->submitted_at)->toBeNull()
        ->and(Notification::query()->where('user_id', $f['benUser']->getKey())->where('kind', 'TASK_RETURNED')->count())->toBe(1);
});

it('lets a leader off the crew approve an INDIVIDUAL response and read every member content and evidence', function () {
    Storage::fake('local');
    $f = tleadFixture();
    $task = tleadTask($f['project'], 'TSK-821', ['status' => 'IN_PROGRESS', 'submission_mode' => 'INDIVIDUAL'], [$f['ana'], $f['ben']]);
    $anaRow = tleadResponse($task, $f['ana'], 'Ana: tallies attached.');
    tleadResponse($task, $f['ben'], 'Ben: photos attached.');
    $brief = tleadFile($task, 'BRIEF', null, 'brief.pdf');
    $anaFile = tleadFile($task, 'SUBMISSION', $f['ana'], 'ana.pdf');
    $benFile = tleadFile($task, 'SUBMISSION', $f['ben'], 'ben.pdf');
    $actor = Access::forActor($f['leader']);
    $records = app(WorkRecords::class);

    $responses = $records->responses($actor, $task->id);
    expect($responses)->toHaveCount(2);
    foreach ($responses as $entry) {
        expect($entry->content_visible)->toBeTrue()
            ->and($entry->is_self)->toBeFalse();
    }
    expect($responses->get($f['ana']->id)?->remarks)->toBe('Ana: tallies attached.')
        ->and($responses->get($f['ben']->id)?->remarks)->toBe('Ben: photos attached.')
        ->and(tleadFileIds($records->files($actor, [$task->id])))->toBe(tleadFileIds([$brief, $anaFile, $benFile]))
        ->and($records->counts($actor, [$task->id])['files']->all())->toEqual([$task->id => 3]);

    tleadLogin($f['leader']);
    foreach (['projects.task-files.download', 'tasks.files.download'] as $route) {
        foreach ([$anaFile, $benFile] as $file) {
            $response = $this->get(route($route, $file->id))->assertOk();
            expect($response->streamedContent())->toBe(Storage::disk('local')->get($file->path));
        }
    }

    $show = Livewire::test(Show::class, ['project' => $f['project']->id])->call('openTask', $task->id);
    $show->assertViewHas('openTaskMayReview', true);
    $show->call('selectResponse', $f['ana']->id)->assertSee('Ana: tallies attached.');

    app(ReviewTaskResponse::class)->handle($actor, $f['project']->id, $task->id, $anaRow->id, 'APPROVED', 'Clear tallies.', 1);

    $anaRow->refresh();
    expect($anaRow->status)->toBe('APPROVED')
        ->and($anaRow->getAttribute('reviewed_by'))->toBe($f['leader']->getKey())
        ->and($anaRow->review_remarks)->toBe('Clear tallies.')
        ->and($anaRow->revision)->toBe(2)
        ->and(tleadAuditCount($anaRow->id, 'TASK_RESPONSE_APPROVED'))->toBe(1)
        ->and(Notification::query()->where('user_id', $f['anaUser']->getKey())->where('kind', 'TASK_RESPONSE_APPROVED')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $f['benUser']->getKey())->count())->toBe(0);
});

it('lets a leader off the crew speak for MANAGEMENT on a task thread', function () {
    $f = tleadFixture();

    expect(app(CommentOnProjectTask::class)->handle(Access::forActor($f['leader']), $f['project']->id, $f['t1']->id, 'How far along is this?'))->toBeTrue();

    $comment = TaskComment::query()->where('task_id', $f['t1']->id)->sole();
    expect($comment->side)->toBe('MANAGEMENT')
        ->and($comment->getAttribute('author_id'))->toBe($f['leader']->getKey())
        ->and(WorkRules::commentSide(Access::forActor($f['leader']), $f['t1']->fresh(['assignments', 'project'])))->toBe('MANAGEMENT')
        ->and(Notification::query()->where('user_id', $f['anaUser']->getKey())->where('kind', 'TASK_COMMENT')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| (c) Limits — everything else stays with the Projects managers
|--------------------------------------------------------------------------
*/

it('refuses the leader every other project edit through UpdateProject', function () {
    $f = tleadFixture();

    expect(fn () => app(UpdateProject::class)->handle(Access::forActor($f['leader']), $f['project']->id,
        new ProjectDraft('Renamed', 'New brief', 'NONE', null, null, '2027-03-31', (string) $f['other']->getKey())))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());

    $project = $f['project']->fresh();
    expect($project?->name)->toBe('Harbour Survey')
        ->and($project?->description)->toBeNull()
        ->and($project?->due_date?->format('Y-m-d'))->toBe('2026-12-31')
        ->and((int) $project?->manager_id)->toBe($f['leader']->getKey())
        ->and(tleadAuditCount($f['project']->id))->toBe(0);
});

it('refuses the leader deleting the project, adding a task or attaching a brief', function () {
    Storage::fake('local');
    $f = tleadFixture();
    $actor = Access::forActor($f['leader']);

    expect(fn () => app(DeleteProject::class)->handle($actor, $f['project']->id))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());
    expect(fn () => app(CreateTask::class)->handle($actor, $f['project']->id, new TaskDraft('Sneaky task', '', 'NORMAL', '', [$f['ana']->id])))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());
    expect(fn () => app(TaskBriefs::class)->attach($actor, $f['project']->id, $f['t1']->id, UploadedFile::fake()->create('brief.pdf', 4, 'application/pdf')))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());

    tleadLogin($f['leader']);
    Livewire::test(Show::class, ['project' => $f['project']->id])
        ->call('deleteProject')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === WorkRules::forbiddenMessage())
        ->set('taskTitle', 'Another sneaky task')
        ->call('saveTask')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === WorkRules::forbiddenMessage());

    expect(Project::query()->whereKey($f['project']->id)->exists())->toBeTrue()
        ->and(ProjectTask::query()->where('project_id', $f['project']->id)->count())->toBe(3)
        ->and(ProjectTaskFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('gives the leader no authority over a project they do not lead', function () {
    $f = tleadFixture();
    $actor = Access::forActor($f['leader']);
    $foreign = $f['foreign'];
    $task = $f['foreignTask'];

    expect(app(ChangeProjectStatus::class)->handle($actor, $foreign->id, 'ON_HOLD', 'ACTIVE'))->toBeFalse()
        ->and(app(CommentOnProjectTask::class)->handle($actor, $foreign->id, $task->id, 'Me too'))->toBeFalse();
    foreach ([
        fn () => app(ScheduleProject::class)->handle($actor, $foreign->id, '2026-02-01'),
        fn () => app(UpdateTaskDetails::class)->handle($actor, $foreign->id, $task->id, new TaskDetails('NORMAL', '', [], null, '2026-02-01')),
        fn () => app(ReviewTask::class)->handle($actor, $foreign->id, $task->id, 'COMPLETED', ''),
        fn () => app(ChangeTaskStatus::class)->handle($actor, $foreign->id, $task->id, 'CANCELLED', '', 'SUBMITTED'),
        // A foreign task smuggled under the project they DO lead.
        fn () => app(ReviewTask::class)->handle($actor, $f['project']->id, $task->id, 'COMPLETED', ''),
        fn () => app(UpdateTaskDetails::class)->handle($actor, $f['project']->id, $task->id, new TaskDetails('NORMAL', '', [], null, '2026-02-01')),
    ] as $attempt) {
        expect($attempt)->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());
    }

    // On the crew of one of its tasks the leader sees that project, and
    // still runs nothing in it.
    $task->syncAssignees([$f['ana']->id, $f['lea']->id], null);
    $onCrew = Access::forActor($f['leader']);
    expect(app(WorkRecords::class)->project($onCrew, $foreign->id))->not->toBeNull();
    expect(fn () => app(ChangeProjectStatus::class)->handle($onCrew, $foreign->id, 'ON_HOLD', 'ACTIVE'))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());
    expect(fn () => app(ScheduleProject::class)->handle($onCrew, $foreign->id, '2026-02-01'))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());

    $foreign->refresh();
    $task->refresh();
    expect($foreign->status)->toBe('ACTIVE')
        ->and($foreign->start_date->format('Y-m-d'))->toBe('2026-01-01')
        ->and($task->status)->toBe('SUBMITTED')
        ->and($task->start_date)->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBeNull()
        ->and(TaskComment::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('strips the leader of all task authority on a task they are assigned to', function () {
    $f = tleadFixture();
    $own = tleadHandedIn($f['project'], 'TSK-831', $f['ana'], [$f['lea'], $f['ana']]);
    $actor = Access::forActor($f['leader']);

    foreach (['COMPLETED', 'CANCELLED'] as $decision) {
        expect(fn () => app(ReviewTask::class)->handle($actor, $f['project']->id, $own->id, $decision, 'Fine.'))
            ->toThrow(RuntimeException::class, WorkRules::selfReviewMessage());
    }
    foreach (['CANCELLED', 'COMPLETED'] as $to) {
        expect(fn () => app(ChangeTaskStatus::class)->handle($actor, $f['project']->id, $own->id, $to, '', 'SUBMITTED'))
            ->toThrow(RuntimeException::class, WorkRules::selfReviewMessage());
    }
    expect(fn () => app(UpdateTaskDetails::class)->handle($actor, $f['project']->id, $own->id, new TaskDetails('NORMAL', '', [], null, '2026-02-01')))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());

    tleadLogin($f['leader']);
    // assertViewHas() hands back a TestResponse (PHPStan), so the component
    // is driven from its own variable.
    $show = Livewire::test(Show::class, ['project' => $f['project']->id])->call('openTask', $own->id);
    $show->assertViewHas('openTaskMayReview', false)
        ->assertViewHas('openTaskRunnable', false)
        ->assertViewHas('openTaskManualMoves', []);
    $show->call('review', 'CANCELLED')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === WorkRules::selfReviewMessage());

    $own->refresh();
    expect($own->status)->toBe('SUBMITTED')
        ->and($own->getAttribute('reviewed_by'))->toBeNull()
        ->and($own->start_date)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);

    // Their word on it is an assignee's.
    expect(app(CommentOnProjectTask::class)->handle(Access::forActor($f['leader']), $f['project']->id, $own->id, 'My part is in.'))->toBeTrue();
    expect(TaskComment::query()->where('task_id', $own->id)->sole()->side)->toBe('ASSIGNEE');
});

it('keeps a peer INDIVIDUAL content hidden from a leader who is on the crew', function () {
    Storage::fake('local');
    $f = tleadFixture();
    $task = tleadTask($f['project'], 'TSK-841', ['status' => 'IN_PROGRESS', 'submission_mode' => 'INDIVIDUAL'], [$f['lea'], $f['ana']]);
    $anaRow = tleadResponse($task, $f['ana'], 'Ana: private note.');
    tleadResponse($task, $f['lea'], 'Lea: my own part.');
    $brief = tleadFile($task, 'BRIEF', null, 'brief.pdf');
    $anaFile = tleadFile($task, 'SUBMISSION', $f['ana'], 'ana.pdf');
    $leaFile = tleadFile($task, 'SUBMISSION', $f['lea'], 'lea.pdf');
    $actor = Access::forActor($f['leader']);
    $records = app(WorkRecords::class);

    $responses = $records->responses($actor, $task->id);
    expect($responses->get($f['ana']->id)?->content_visible)->toBeFalse()
        ->and($responses->get($f['ana']->id)?->remarks)->toBeNull()
        ->and($responses->get($f['lea']->id)?->content_visible)->toBeTrue()
        ->and($responses->get($f['lea']->id)?->remarks)->toBe('Lea: my own part.')
        ->and(tleadFileIds($records->files($actor, [$task->id])))->toBe(tleadFileIds([$brief, $leaFile]))
        ->and($records->counts($actor, [$task->id])['files']->all())->toEqual([$task->id => 2]);

    tleadLogin($f['leader']);
    foreach (['projects.task-files.download', 'tasks.files.download'] as $route) {
        $this->get(route($route, $anaFile->id))->assertForbidden();
    }
    Livewire::test(Show::class, ['project' => $f['project']->id])
        ->call('openTask', $task->id)
        ->call('selectResponse', $f['ana']->id)
        ->assertDontSee('Ana: private note.');

    expect(fn () => app(ReviewTaskResponse::class)->handle($actor, $f['project']->id, $task->id, $anaRow->id, 'APPROVED', '', 1))
        ->toThrow(RuntimeException::class, WorkRules::selfReviewMessage());
    $anaRow->refresh();
    expect($anaRow->status)->toBe('SUBMITTED')
        ->and($anaRow->revision)->toBe(1)
        ->and($anaRow->getAttribute('reviewed_by'))->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| (d) Naming leaders — only an account that can open Project Management
|--------------------------------------------------------------------------
*/

it('refuses a TASKS-only or inactive account as a NEW leader and accepts a PROJECTS role', function () {
    $admin = Access::forActor(tleadAccount(['PROJECTS'], true, null));
    $tasksOnly = tleadAccount(['TASKS'], false, null);
    $inactive = tleadAccount(['PROJECTS', 'TASKS'], false, null, active: false);
    $teamLead = tleadAccount(['PROJECTS', 'TASKS'], false, null);
    $draft = static fn (string $name, User $leader): ProjectDraft => new ProjectDraft($name, '', 'NONE', 'PLANNING', '2026-01-01', '', (string) $leader->getKey());

    foreach ([$tasksOnly, $inactive] as $refused) {
        expect(fn () => app(CreateProject::class)->handle($admin, $draft('Ferry Terminal', $refused)))
            ->toThrow(RuntimeException::class, WorkRules::leaderRefusal());
    }
    expect(Project::query()->where('name', 'Ferry Terminal')->exists())->toBeFalse();

    $created = app(CreateProject::class)->handle($admin, $draft('Ferry Terminal', $teamLead));
    expect((int) Project::query()->whereKey($created->id)->value('manager_id'))->toBe($teamLead->getKey());

    $project = tleadProject('PRJ-851', 'Pier Repairs', null, ['status' => 'PLANNING']);
    $edit = static fn (User $leader): ProjectDraft => new ProjectDraft('Pier Repairs', '', 'NONE', null, null, '2026-12-31', (string) $leader->getKey());
    foreach ([$tasksOnly, $inactive] as $refused) {
        expect(fn () => app(UpdateProject::class)->handle($admin, $project->id, $edit($refused)))
            ->toThrow(RuntimeException::class, WorkRules::leaderRefusal());
    }
    expect($project->fresh()?->manager_id)->toBeNull()
        ->and(tleadAuditCount($project->id))->toBe(0);

    expect(app(UpdateProject::class)->handle($admin, $project->id, $edit($teamLead)))->toBe('PRJ-851')
        ->and((int) $project->fresh()?->manager_id)->toBe($teamLead->getKey());
});

it('keeps an already-named TASKS-only leader on a details-only save', function () {
    $admin = Access::forActor(tleadAccount(['PROJECTS'], true, null));
    $tasksOnly = tleadAccount(['TASKS'], false, null);
    $project = tleadProject('PRJ-861', 'Old Wharf', $tasksOnly);

    $code = app(UpdateProject::class)->handle($admin, $project->id,
        new ProjectDraft('Old Wharf Phase 2', 'Second phase.', 'NONE', null, null, '2026-12-31', (string) $tasksOnly->getKey()));

    expect($code)->toBe('PRJ-861');
    $project->refresh();
    expect($project->name)->toBe('Old Wharf Phase 2')
        ->and($project->description)->toBe('Second phase.')
        ->and((int) $project->manager_id)->toBe($tasksOnly->getKey())
        ->and($project->status)->toBe('ACTIVE');
});

it('offers only accounts that can lead in the create picker, and the current leader too in the edit picker', function () {
    $admin = tleadAccount(['PROJECTS'], true, null);
    $lead = tleadAccount(['PROJECTS', 'TASKS'], false, null);
    $tasksOnly = tleadAccount(['TASKS'], false, null);
    $other = tleadAccount(['TASKS'], false, null);
    $inactive = tleadAccount(['PROJECTS', 'TASKS'], false, null, active: false);
    $project = tleadProject('PRJ-871', 'Dry Dock', $tasksOnly);

    tleadLogin($admin);
    $create = tleadPicker(Livewire::test(Index::class)->html(), 'Project lead');
    expect($create)->toContain('draftManagerId')
        ->toContain($lead->name)
        ->toContain($admin->name)
        ->not->toContain($tasksOnly->name)
        ->not->toContain($other->name)
        ->not->toContain($inactive->name);

    $edit = tleadPicker(Livewire::test(Show::class, ['project' => $project->id])->call('openEdit')->html(), 'Project lead');
    expect($edit)->toContain('editManagerId')
        ->toContain($lead->name)
        ->toContain($tasksOnly->name)
        ->not->toContain($other->name)
        ->not->toContain($inactive->name);
});
