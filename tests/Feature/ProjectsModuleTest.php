<?php

declare(strict_types=1);

use App\Livewire\Projects\Index;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Projects\Show;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskFile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * PROJECTS module: the route gate, the row scoping that must return NOTHING
 * for an employee-less actor, one full happy-path flow (create project → hand
 * out a task → accept the submission), the multi-assignee pivot (its
 * re-validation, its notification fan-out, the CSV cell it prints, and a
 * reviewer on the crew who may cancel but never accept), the
 * transition maps that the reference API famously skipped, and the
 * delete-project confirm this port adds on top of the reference.
 */

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function projRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Proj '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param list<string> $modules module codes for the actor's fresh role */
function projActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => projRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function projEmployee(string $code, string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/** @param array<string, mixed> $attributes column overrides, merged over the defaults */
function projProject(array $attributes = []): Project
{
    static $n = 100;
    $n++;

    $project = new Project;
    $project->forceFill(array_merge([
        'code' => 'PRJ-'.$n,
        'name' => 'Project '.Str::random(8),
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ], $attributes))->save();

    return $project;
}

/**
 * `assignee_ids` (list<string>) is the fixture's one non-column key: the
 * employees are attached through the pivot after the row exists, exactly as
 * the page does it (ProjectTask::syncAssignees()).
 *
 * @param  array{assignee_ids?: list<string>, ...<string, mixed>}  $attributes  column overrides plus the optional pivot key
 */
function projTask(Project $project, array $attributes = []): ProjectTask
{
    static $n = 100;
    $n++;

    $assigneeIds = $attributes['assignee_ids'] ?? [];
    unset($attributes['assignee_ids']);

    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => 'TSK-'.$n,
        'project_id' => $project->id,
        'title' => 'Task '.Str::random(8),
        'status' => 'PENDING',
        'priority' => 'NORMAL',
        'assigned_at' => CarbonImmutable::now(),
    ], $attributes))->save();

    if ($assigneeIds !== []) {
        $task->syncAssignees($assigneeIds, null);
    }

    return $task;
}

/** An active login for an employee, in a role that only holds TASKS. */
function projAccountFor(EmployeeProfile $employee): User
{
    return User::factory()->create([
        'role_id' => projRole(['TASKS'], false)->getKey(),
        'employee_id' => $employee->id,
    ]);
}

/**
 * A task shared by Ben and a lead who is also its assigner and an all-data
 * PROJECTS reviewer, with a second active login on the lead's employee record.
 * A SUBMITTED task is recorded as handed in by Ben.
 *
 * @return array{project: Project, task: ProjectTask, ben: EmployeeProfile, lead: User, leadAlt: User, benAccount: User}
 */
function projLeadOnCrew(string $status): array
{
    $lead = projEmployee('COP-0471', 'Mia', 'Lopez');
    $ben = projEmployee('COP-0472', 'Ben', 'Reyes');
    $leadAccount = User::factory()->create([
        'role_id' => projRole(['PROJECTS'], true)->getKey(),
        'employee_id' => $lead->id,
    ]);
    $leadAltAccount = User::factory()->create([
        'email' => 'mia.alt@example.com',
        'employee_id' => $lead->id,
        'is_active' => true,
    ]);
    $project = projProject();

    return [
        'project' => $project,
        'task' => projTask($project, array_merge([
            'status' => $status,
            'assignee_ids' => [$lead->id, $ben->id],
            'assigned_by' => $leadAccount->getKey(),
        ], $status === 'SUBMITTED' ? ['submitted_at' => CarbonImmutable::now(), 'submitted_by' => $ben->id] : [])),
        'ben' => $ben,
        'lead' => $leadAccount,
        'leadAlt' => $leadAltAccount,
        'benAccount' => projAccountFor($ben),
    ];
}

/*
|--------------------------------------------------------------------------
| Route gating — module:PROJECTS
|--------------------------------------------------------------------------
*/

it('turns a signed-in account without the PROJECTS grant away from every projects route', function () {
    $project = projProject();
    projActor(['EDTR'], allData: true);

    // A browser is redirected to what it may see, never shown the page.
    $this->get('/projects')->assertRedirect();
    $this->get('/projects/export')->assertRedirect();
    $this->get('/projects/'.$project->id)->assertRedirect();
    $this->get('/projects/'.$project->id.'/tasks/export')->assertRedirect();
});

it('lets a PROJECTS holder open the list and the detail page', function () {
    $project = projProject(['name' => 'Website Revamp']);
    projActor(['PROJECTS'], allData: true);

    $this->get('/projects')->assertOk()->assertSee('Project Management');
    $this->get('/projects/'.$project->id)->assertOk()->assertSee('Website Revamp');
});

it('gates task-file downloads on PROJECTS or TASKS', function () {
    projActor(['ROSTER'], allData: false);

    $this->get('/projects/task-files/some-file-id')->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| Row scoping — an employee-less actor gets NOTHING
|--------------------------------------------------------------------------
*/

it('returns zero rows for an employee-less PROJECTS holder even when unassigned rows exist', function () {
    $project = projProject();
    projTask($project); // zero pivot rows — what an IS NULL predicate would leak

    $user = projActor(['PROJECTS'], allData: false, employeeId: null);
    $actor = Access::forActor($user);

    expect(ProjectsData::scopedProjects($actor)->count())->toBe(0);
    expect(ProjectsData::scopedTasks($actor)->count())->toBe(0);

    // And the page reflects the same emptiness rather than leaking the row.
    $this->get('/projects')->assertOk()->assertDontSee($project->code);
});

it('narrows a linked non-manager to the projects holding their own tasks', function () {
    $mine = projEmployee('COP-0101', 'Liza', 'Domingo');
    $visible = projProject(['name' => 'Visible Project']);
    $hidden = projProject(['name' => 'Hidden Project']);
    projTask($visible, ['assignee_ids' => [$mine->id]]);
    projTask($hidden);

    $user = projActor(['PROJECTS'], allData: false, employeeId: $mine->id);
    $actor = Access::forActor($user);

    expect(ProjectsData::scopedProjects($actor)->pluck('id')->all())->toBe([$visible->id]);
    expect(ProjectsData::scopedTasks($actor)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Happy path — create a project, hand out a task, accept the submission
|--------------------------------------------------------------------------
*/

it('creates a project, assigns a task, and accepts the submitted work', function () {
    $employee = projEmployee('COP-0201', 'Ana', 'Garcia');
    $assigneeAccount = User::factory()->create([
        'role_id' => projRole(['TASKS'], false)->getKey(),
        'employee_id' => $employee->id,
    ]);

    $manager = projActor(['PROJECTS'], allData: true);

    // 1. New project (new-project-dialog flow).
    Livewire::test(Index::class)
        ->set('draftName', 'Website Revamp')
        ->set('draftStartDate', '2026-08-01')
        ->set('draftStatus', 'PLANNING')
        ->call('createProject')
        ->assertSet('adding', false);

    $project = Project::query()->where('name', 'Website Revamp')->firstOrFail();
    expect($project->code)->toBe('PRJ-001');
    expect($project->status)->toBe('PLANNING');
    expect(AuditLog::query()->where('action', 'PROJECT_CREATED')->where('entity_id', $project->id)->exists())->toBeTrue();

    // 2. Hand out a task (add-task-dialog flow). assigned_by must come from
    //    the session, never the request.
    Livewire::test(Show::class, ['project' => $project->id])
        ->set('taskTitle', 'Draft the homepage')
        ->set('taskAssigneeIds', [$employee->id])
        ->set('taskPriority', 'HIGH')
        ->call('saveTask')
        ->assertSet('adding', false);

    $task = ProjectTask::query()->where('title', 'Draft the homepage')->firstOrFail();
    expect($task->code)->toBe('TSK-001');
    expect($task->status)->toBe('PENDING');
    expect($task->getAttribute('assigned_by'))->toBe($manager->getKey());
    expect($task->assigneeIds())->toBe([$employee->id]);
    expect(AuditLog::query()->where('action', 'TASK_ASSIGNED')->where('entity_id', $task->id)->exists())->toBeTrue();
    expect(
        Notification::query()
            ->where('user_id', $assigneeAccount->getKey())
            ->where('kind', 'TASK_ASSIGNED')
            ->exists(),
    )->toBeTrue();

    // 3. The assignee submits (their side lives in the TASKS module; the row
    //    state is what this module reviews).
    $task->forceFill(['status' => 'SUBMITTED', 'submitted_at' => CarbonImmutable::now(), 'remarks' => 'Done po.'])->save();

    // 4. Review — accept as complete, with a remark that must land on the thread.
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->assertSet('openTaskId', $task->id)
        ->set('remarks', 'Great work')
        ->call('review', 'COMPLETED')
        ->assertSet('reviewOpen', false);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED');
    expect($task->getAttribute('reviewed_by'))->toBe($manager->getKey());
    expect($task->completed_at)->not->toBeNull();
    expect($task->review_remarks)->toBe('Great work');

    $comment = TaskComment::query()->where('task_id', $task->id)->firstOrFail();
    expect($comment->side)->toBe('MANAGEMENT');
    expect($comment->event)->toBe('COMPLETED');
    expect($comment->body)->toBe('Great work');

    expect(AuditLog::query()->where('action', 'TASK_COMPLETED')->where('entity_id', $task->id)->exists())->toBeTrue();
    expect(
        Notification::query()
            ->where('user_id', $assigneeAccount->getKey())
            ->where('kind', 'TASK_COMPLETED')
            ->exists(),
    )->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Multi-assignee — one shared task, 0..N employees on the pivot
|--------------------------------------------------------------------------
*/

it('creates a task with two assignees: two pivot rows, one TASK_ASSIGNED per active account', function () {
    $ana = projEmployee('COP-0401', 'Ana', 'Garcia');
    $ben = projEmployee('COP-0402', 'Ben', 'Reyes');
    $anaAccount = projAccountFor($ana);
    $benAccount = projAccountFor($ben);
    $project = projProject();
    $manager = projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->set('taskTitle', 'Shared deliverable')
        ->set('taskAssigneeIds', [$ben->id, $ana->id, $ana->id]) // a duplicate is folded
        ->call('saveTask')
        ->assertSet('adding', false)
        ->assertSet('taskAssigneeIds', []);

    $task = ProjectTask::query()->where('title', 'Shared deliverable')->firstOrFail();
    expect($task->assigneeIds())->toBe(collect([$ana->id, $ben->id])->sort()->values()->all());
    expect(ProjectTaskAssignee::query()->where('task_id', $task->id)->count())->toBe(2);
    expect(ProjectTaskAssignee::query()->where('task_id', $task->id)->pluck('assigned_by')->unique()->all())
        ->toBe([$manager->getKey()]);

    foreach ([$anaAccount, $benAccount] as $account) {
        expect(Notification::query()->where('user_id', $account->getKey())->where('kind', 'TASK_ASSIGNED')->count())->toBe(1);
    }

    $audit = AuditLog::query()->where('action', 'TASK_ASSIGNED')->where('entity_id', $task->id)->get();
    expect($audit)->toHaveCount(1);
    expect($audit->first()?->details)->toContain('Ana Garcia')->toContain('Ben Reyes');
});

it('refuses to hand a task to an inactive employee, naming the plural when several were ticked', function () {
    $active = projEmployee('COP-0411', 'Ana', 'Garcia');
    $gone = projEmployee('COP-0412', 'Gone', 'Person');
    $gone->forceFill(['is_active' => false])->save();
    $project = projProject();
    projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->set('adding', true)
        ->set('taskTitle', 'Should not exist')
        ->set('taskAssigneeIds', [$active->id, $gone->id])
        ->call('saveTask')
        ->assertSet('adding', true); // the dialog stays open on the refusal

    expect(ProjectTask::query()->where('title', 'Should not exist')->exists())->toBeFalse();
    expect(ProjectTaskAssignee::query()->count())->toBe(0);
});

it('reassigning [A, B] to [B, C] notifies C (assigned), A (reassigned) and B not at all', function () {
    $a = projEmployee('COP-0421', 'Ana', 'Garcia');
    $b = projEmployee('COP-0422', 'Ben', 'Reyes');
    $c = projEmployee('COP-0423', 'Cai', 'Santos');
    $aAccount = projAccountFor($a);
    $bAccount = projAccountFor($b);
    $cAccount = projAccountFor($c);

    $project = projProject();
    $task = projTask($project, ['status' => 'IN_PROGRESS', 'assignee_ids' => [$a->id, $b->id]]);
    $bRow = ProjectTaskAssignee::query()->where('task_id', $task->id)->where('employee_id', $b->id)->firstOrFail();

    $manager = projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->assertSet('draftAssigneeIds', $task->assigneeIds())
        ->call('toggleEditingDetails')
        ->set('draftAssigneeIds', [$b->id, $c->id])
        ->call('saveDetails')
        ->assertSet('editingDetails', false);

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    expect($task->assigneeIds())->toBe(collect([$b->id, $c->id])->sort()->values()->all());

    // B's row was retained untouched; C's is new and stamped with the reviewer.
    expect(ProjectTaskAssignee::query()->whereKey($bRow->getKey())->exists())->toBeTrue();
    expect(ProjectTaskAssignee::query()->where('task_id', $task->id)->where('employee_id', $c->id)->value('assigned_by'))
        ->toBe($manager->getKey());

    expect(Notification::query()->where('user_id', $cAccount->getKey())->where('kind', 'TASK_ASSIGNED')->count())->toBe(1);
    expect(Notification::query()->where('user_id', $aAccount->getKey())->where('kind', 'TASK_REASSIGNED')->count())->toBe(1);
    expect(Notification::query()->where('user_id', $bAccount->getKey())->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->firstOrFail();
    expect($audit->details)->toContain('assignees');
});

it('counts a zero-assignee task under the UNASSIGNED filter and queue', function () {
    $ana = projEmployee('COP-0431', 'Ana', 'Garcia');
    $project = projProject();
    projTask($project, ['title' => 'Nobody yet', 'code' => 'TSK-NOBODY']);
    projTask($project, ['title' => 'Taken', 'code' => 'TSK-TAKEN', 'assignee_ids' => [$ana->id]]);
    projActor(['PROJECTS'], allData: true);

    // One component, driven in steps: Testable has no assertViewHas of its
    // own — it forwards to TestResponse through `@mixin`, so PHPStan types
    // the chain as TestResponse from there on and TestResponse has no
    // set(). Each ->set() therefore starts from the Testable itself.
    $show = Livewire::test(Show::class, ['project' => $project->id]);
    $show->assertViewHas('unassignedCount', 1);
    $show->set('queue', 'UNASSIGNED')
        ->assertViewHas('visibleCount', 1)
        ->assertSee('TSK-NOBODY')
        ->assertDontSee('TSK-TAKEN');
    $show->set('queue', 'ALL')
        ->set('assignee', 'UNASSIGNED')
        ->assertViewHas('visibleCount', 1)
        ->assertSee('TSK-NOBODY')
        ->assertDontSee('TSK-TAKEN');
    $show->set('assignee', $ana->id)
        ->assertViewHas('visibleCount', 1)
        ->assertSee('TSK-TAKEN')
        ->assertDontSee('TSK-NOBODY');

    Livewire::test(Index::class)->assertViewHas('unassignedCount', 1);
});

it('shows a project to the SECOND assignee of one of its tasks and hides it from a stranger', function () {
    $first = projEmployee('COP-0441', 'Ana', 'Garcia');
    $second = projEmployee('COP-0442', 'Ben', 'Reyes');
    $stranger = projEmployee('COP-0443', 'Cai', 'Santos');
    $project = projProject(['name' => 'Shared Project']);
    $task = projTask($project, ['code' => 'TSK-SHARED', 'assignee_ids' => [$first->id, $second->id]]);

    projActor(['PROJECTS'], allData: false, employeeId: $second->id);
    $this->get('/projects/'.$project->id)->assertOk()->assertSee('Shared Project')->assertSee('TSK-SHARED');
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->assertSet('openTaskId', $task->id);

    projActor(['PROJECTS'], allData: false, employeeId: $stranger->id);
    $this->get('/projects/'.$project->id)->assertOk()->assertDontSee('Shared Project')->assertSee('Project not found.');
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->assertSet('openTaskId', null);
});

it('lets the second assignee download a task file and refuses an unrelated employee', function () {
    Storage::fake('local');

    $first = projEmployee('COP-0451', 'Ana', 'Garcia');
    $second = projEmployee('COP-0452', 'Ben', 'Reyes');
    $stranger = projEmployee('COP-0453', 'Cai', 'Santos');
    $project = projProject();
    $task = projTask($project, ['assignee_ids' => [$first->id, $second->id]]);

    $path = UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf')->store('task-files', 'local');
    $file = new ProjectTaskFile;
    $file->fill([
        'task_id' => $task->id,
        'kind' => 'BRIEF',
        'file_name' => 'brief.pdf',
        'mime' => 'application/pdf',
        'size' => 10240,
        'path' => (string) $path,
    ]);
    $file->save();

    projActor(['TASKS'], allData: false, employeeId: $second->id);
    $this->get('/projects/task-files/'.$file->id)->assertOk();

    projActor(['TASKS'], allData: false, employeeId: $stranger->id);
    $this->get('/projects/task-files/'.$file->id)->assertForbidden();

    // An employee-less TASKS holder is not "one of the assignees" either.
    projActor(['TASKS'], allData: false, employeeId: null);
    $this->get('/projects/task-files/'.$file->id)->assertForbidden();
});

it('fans a review outcome and a management comment out to every assignee', function () {
    $a = projEmployee('COP-0461', 'Ana', 'Garcia');
    $b = projEmployee('COP-0462', 'Ben', 'Reyes');
    $aAccount = projAccountFor($a);
    $bAccount = projAccountFor($b);
    $project = projProject();
    $task = projTask($project, [
        'status' => 'SUBMITTED',
        'assignee_ids' => [$a->id, $b->id],
        'submitted_at' => CarbonImmutable::now(),
        'submitted_by' => $b->id,
    ]);
    projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->assertSee('Ben Reyes')
        ->set('commentBody', 'Looks close.')
        ->call('postComment')
        ->set('remarks', 'Tighten the intro')
        ->call('review', 'RETURNED')
        ->assertSet('reviewOpen', false);

    expect($task->refresh()->status)->toBe('RETURNED');

    foreach ([$aAccount, $bAccount] as $account) {
        expect(Notification::query()->where('user_id', $account->getKey())->where('kind', 'TASK_COMMENT')->count())->toBe(1);
        expect(Notification::query()->where('user_id', $account->getKey())->where('kind', 'TASK_RETURNED')->count())->toBe(1);
    }
});

it('never tells the actor about their own comment, and tells an assigner on the crew only once', function () {
    [
        'project' => $project, 'task' => $task, 'ben' => $ben,
        'lead' => $leadAccount, 'leadAlt' => $leadAltAccount, 'benAccount' => $benAccount,
    ] = projLeadOnCrew('SUBMITTED');

    // Ben — an assignee, not a manager — comments: the assigner is told ONCE,
    // through their account, not again through the employee fan-out that
    // would resolve their employee record back to the same account; Ben
    // hears nothing about his own note.
    projActor(['PROJECTS'], allData: false, employeeId: $ben->id);
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->set('commentBody', 'Ready for a look.')
        ->call('postComment');

    expect(Notification::query()->where('user_id', $leadAccount->getKey())->where('kind', 'TASK_COMMENT')->count())->toBe(1);
    expect(Notification::query()->where('user_id', $leadAltAccount->getKey())->where('kind', 'TASK_COMMENT')->count())->toBe(1);
    expect(Notification::query()->where('user_id', $benAccount->getKey())->count())->toBe(0);

    // The reviewer comments: Ben is told, the reviewer is not — nobody is
    // notified of their own action.
    test()->actingAs($leadAccount);
    app()->forgetInstance(Access::class);
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->set('commentBody', 'Looks good.')
        ->call('postComment');

    expect(Notification::query()->where('user_id', $benAccount->getKey())->where('kind', 'TASK_COMMENT')->count())->toBe(1);
    expect(Notification::query()->where('user_id', $leadAccount->getKey())->where('kind', 'TASK_COMMENT')->count())->toBe(1);
    expect(Notification::query()->where('user_id', $leadAltAccount->getKey())->where('kind', 'TASK_COMMENT')->count())->toBe(1);
});

it('refuses an accept from a reviewer who is one of the task assignees', function () {
    ['project' => $project, 'task' => $task, 'lead' => $leadAccount] = projLeadOnCrew('SUBMITTED');
    test()->actingAs($leadAccount);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->set('remarks', 'Accepted')
        ->call('review', 'COMPLETED')
        ->assertSet('reviewOpen', true);

    $task->refresh();
    expect($task->status)->toBe('SUBMITTED');
    expect($task->getAttribute('reviewed_by'))->toBeNull();
    expect(TaskComment::query()->where('task_id', $task->id)->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'TASK_COMPLETED')->where('entity_id', $task->id)->count())->toBe(0);
    expect(Notification::query()->where('kind', 'TASK_COMPLETED')->count())->toBe(0);
});

it('lets a reviewer on the crew cancel a shared task, telling everyone else but none of their own accounts', function () {
    [
        'project' => $project, 'task' => $task,
        'lead' => $leadAccount, 'leadAlt' => $leadAltAccount, 'benAccount' => $benAccount,
    ] = projLeadOnCrew('IN_PROGRESS');
    test()->actingAs($leadAccount);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('review', 'CANCELLED')
        ->assertSet('reviewOpen', false);

    expect($task->refresh()->status)->toBe('CANCELLED');
    expect(Notification::query()->where('user_id', $benAccount->getKey())->where('kind', 'TASK_CANCELLED')->count())->toBe(1);
    expect(Notification::query()->where('user_id', $leadAccount->getKey())->where('kind', 'TASK_CANCELLED')->count())->toBe(0);
    expect(Notification::query()->where('user_id', $leadAltAccount->getKey())->where('kind', 'TASK_CANCELLED')->count())->toBe(0);
});

it('tells a removed employee the task is no longer theirs when nobody is left on it', function () {
    $ana = projEmployee('COP-0481', 'Ana', 'Garcia');
    $anaAccount = projAccountFor($ana);
    $project = projProject();
    $task = projTask($project, ['code' => 'TSK-SOLO', 'assignee_ids' => [$ana->id]]);
    projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('toggleEditingDetails')
        ->set('draftAssigneeIds', [])
        ->call('saveDetails')
        ->assertSet('editingDetails', false);

    expect($task->refresh()->assigneeIds())->toBe([]);

    $notice = Notification::query()
        ->where('user_id', $anaAccount->getKey())
        ->where('kind', 'TASK_REASSIGNED')
        ->firstOrFail();
    expect($notice->body)->toContain('You were removed from Task TSK-SOLO');
    expect($notice->body)->not->toContain('another employee');
});

it('refuses an unknown or inactive newcomer on the review dialog and leaves the pivot alone', function () {
    $ana = projEmployee('COP-0491', 'Ana', 'Garcia');
    $gone = projEmployee('COP-0492', 'Gone', 'Person');
    $gone->forceFill(['is_active' => false])->save();
    $project = projProject();
    $task = projTask($project, ['assignee_ids' => [$ana->id]]);
    projActor(['PROJECTS'], allData: true);

    // The entangled draft is client-writable, so an id that never went
    // through the checklist's toggle() must still be refused here.
    foreach ([[$ana->id, 'not-a-real-id'], [$ana->id, $gone->id]] as $ids) {
        Livewire::test(Show::class, ['project' => $project->id])
            ->call('openTask', $task->id)
            ->call('toggleEditingDetails')
            ->set('draftAssigneeIds', $ids)
            ->call('saveDetails')
            ->assertSet('editingDetails', true) // the editor stays open on a refusal
            ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
                && ($params['slots']['text'] ?? null) === 'Choose an active employee for the task.');

        expect(ProjectTaskAssignee::query()->where('task_id', $task->id)->pluck('employee_id')->all())->toBe([$ana->id]);
    }

    expect(AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->exists())->toBeFalse();
});

it('exports the tasks CSV with an Assignees header and a "; "-joined, surname-ordered cell', function () {
    $ben = projEmployee('COP-0501', 'Ben', 'Reyes');
    $ana = projEmployee('COP-0502', 'Ana', 'Garcia');
    $project = projProject();
    projTask($project, ['code' => 'TSK-BOTH', 'assignee_ids' => [$ben->id, $ana->id]]);
    projTask($project, ['code' => 'TSK-NONE']);
    projActor(['PROJECTS'], allData: true);

    $csv = $this->get('/projects/'.$project->id.'/tasks/export')->assertOk()->streamedContent();

    // The exporter prepends a UTF-8 BOM, so match the quoted cells rather
    // than a line start. A shared task lists every name — Garcia before
    // Reyes — "; "-joined so two people never read as one "Last, First"
    // name; an unassigned task prints the em dash.
    // One BOM, first bytes — App\Support\Csv puts it inside the builder, so a
    // caller that prepends its own would give Excel a stray ï»¿ in cell A1.
    expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue()
        ->and(substr_count($csv, "\u{FEFF}"))->toBe(1);
    expect($csv)->toContain('"Code","Task","Assignees","Priority","Start date","Due date","Status"');
    expect($csv)->toContain('"TSK-BOTH"');
    expect($csv)->toContain('"Ana Garcia; Ben Reyes"');
    expect($csv)->not->toContain('Ben Reyes; Ana Garcia');
    expect($csv)->toContain('"TSK-NONE"');
    expect($csv)->toContain('"—"');
});

/*
|--------------------------------------------------------------------------
| Transition maps — enforced here, unlike the reference API
|--------------------------------------------------------------------------
*/

it('refuses a review decision the manager transition map does not allow', function () {
    $project = projProject();
    $task = projTask($project, ['status' => 'PENDING']);
    projActor(['PROJECTS'], allData: true);

    // MANAGER_TRANSITIONS[PENDING] is [CANCELLED] — completing pending work
    // is the reference API's flat-list bug; the map closes it.
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('review', 'COMPLETED');

    expect($task->refresh()->status)->toBe('PENDING');
});

it('reopens a completed project through the status select, but never to Active without a task leader', function () {
    // DELIBERATE DIVERGENCE (ManualProjectStatus): the reference keeps
    // COMPLETED terminal; a status set by hand may reopen it. The facts
    // still stand — Active needs a Task Leader.
    $project = projProject(['status' => 'COMPLETED']);
    projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('changeStatus', 'ACTIVE', 'COMPLETED')
        ->assertDispatched('toast-show', fn ($name, $p) => ($p['slots']['text'] ?? null) === 'Assign a task leader before activating this project.');
    expect($project->refresh()->status)->toBe('COMPLETED');

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('changeStatus', 'PLANNING', 'COMPLETED');
    expect($project->refresh()->status)->toBe('PLANNING')
        ->and(AuditLog::query()->where('action', 'PROJECT_STATUS_CHANGED')->where('entity_id', $project->id)->count())->toBe(1);
});

it('requires a remark before returning a task', function () {
    $employee = projEmployee('COP-0301', 'Ben', 'Reyes');
    $project = projProject();
    $task = projTask($project, [
        'status' => 'SUBMITTED',
        'assignee_ids' => [$employee->id],
        'submitted_at' => CarbonImmutable::now(),
    ]);
    projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('review', 'RETURNED');

    expect($task->refresh()->status)->toBe('SUBMITTED');
});

it('blocks project writes for a PROJECTS holder without all_data', function () {
    projActor(['PROJECTS'], allData: false);

    Livewire::test(Index::class)
        ->set('draftName', 'Should Not Exist')
        ->set('draftStartDate', '2026-08-01')
        ->call('createProject');

    expect(Project::query()->where('name', 'Should Not Exist')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Delete project — a port-only action, gated like every other write
|--------------------------------------------------------------------------
*/

it('blocks deleting a project for a PROJECTS holder without all_data, even one who can see it', function () {
    $emp = projEmployee('COP-0511', 'Non', 'Manager');
    $project = projProject(['name' => 'Must Survive']);
    projTask($project, ['assignee_ids' => [$emp->id]]);
    $user = projActor(['PROJECTS'], allData: false, employeeId: $emp->id);

    // Precondition: the actor CAN see the project, so only the manage gate —
    // not row scoping, which would hide it from an employee-less account —
    // stands between them and the delete.
    expect(ProjectsData::scopedProjects(Access::forActor($user))->contains('id', $project->id))->toBeTrue();

    Livewire::test(Show::class, ['project' => $project->id])
        ->assertSee('Must Survive')
        ->assertDontSee('Delete project') // the control is not even offered
        ->call('deleteProject')
        ->assertNoRedirect()
        ->assertSet('deleteConfirmOpen', false)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === ProjectsData::forbiddenMessage());

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
    expect(ProjectTask::query()->where('project_id', $project->id)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'PROJECT_DELETED')->exists())->toBeFalse();
});

it('deletes a project with its tasks, files and comments, then returns to the list', function () {
    Storage::fake('local');

    $project = projProject(['name' => 'Doomed Project']);
    $task = projTask($project, ['status' => 'IN_PROGRESS']);

    $path = UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf')->store('task-files', 'local');
    $file = new ProjectTaskFile;
    $file->fill([
        'task_id' => $task->id,
        'kind' => 'BRIEF',
        'file_name' => 'brief.pdf',
        'mime' => 'application/pdf',
        'size' => 10240,
        'path' => (string) $path,
    ]);
    $file->save();
    Storage::disk('local')->assertExists((string) $path);

    $comment = new TaskComment;
    $comment->fill(['task_id' => $task->id, 'side' => 'MANAGEMENT', 'body' => 'Kickoff notes', 'event' => null]);
    $comment->setAttribute('author_name', 'Someone');
    $comment->save();

    projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->assertSee('Delete project') // a manager is offered the control
        ->call('deleteProject')
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse();
    expect(ProjectTask::query()->whereKey($task->id)->exists())->toBeFalse();
    expect(ProjectTaskFile::query()->whereKey($file->id)->exists())->toBeFalse();
    expect(TaskComment::query()->whereKey($comment->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing((string) $path);

    $entry = AuditLog::query()->where('action', 'PROJECT_DELETED')->where('entity_id', $project->id)->first();
    expect($entry)->not->toBeNull();
    expect($entry?->details)->toContain('Doomed Project')->toContain('1 task');
});

/*
|--------------------------------------------------------------------------
| Which identifier the task tables show
|--------------------------------------------------------------------------
*/

it('lists assignee Employee IDs POSITIONALLY, so an unnumbered one holds its place', function () {
    $ana = projEmployee('COP-0501', 'Ana', 'Garcia');
    $ana->forceFill(['employee_id_no' => '2024-201'])->save();
    $ben = projEmployee('COP-0502', 'Ben', 'Reyes');
    $cara = projEmployee('COP-0503', 'Cara', 'Lim');
    $cara->forceFill(['employee_id_no' => '2024-203'])->save();

    $project = projProject();
    projTask($project, ['assignee_ids' => [$ana->id, $ben->id, $cara->id]]);
    projActor(['PROJECTS'], allData: true);

    // Assignees are listed by surname — Garcia, Lim, Reyes — so the id line
    // must read 2024-201 · 2024-203 · — to stay under its own name. Dropping
    // the unnumbered Ben instead would sit two ids under three names and pair
    // Cara's number with him.
    Livewire::test(Show::class, ['project' => $project->id])
        ->assertSee('Ana Garcia, Cara Lim, Ben Reyes')
        ->assertSee('2024-201 · 2024-203 · —')
        ->assertDontSee('COP-0501')
        ->assertDontSee('COP-0503');
});

it('shows one em dash, not a blank line, when no assignee on a task has a company number', function () {
    $ben = projEmployee('COP-0504', 'Ben', 'Torres');
    $cara = projEmployee('COP-0505', 'Cara', 'Villar');
    $project = projProject();
    projTask($project, ['assignee_ids' => [$ben->id, $cara->id]]);
    projActor(['PROJECTS'], allData: true);

    // Two unnumbered assignees collapse to ONE em dash, not to "— · —" and not
    // to an empty line: with nothing to align, there is nothing to hold a place
    // for. The names still render in full.
    $page = Livewire::test(Show::class, ['project' => $project->id])
        ->assertSee('Ben Torres, Cara Villar')
        ->assertDontSee('COP-0504')
        ->assertDontSee('— · —');

    // The id line itself, not just the absence of a wrong one: an empty <p>
    // would satisfy every assertion above.
    expect($page->html())->toMatch('/<p class="num break-words text-xs text-muted-foreground">\s*—\s*<\/p>/');
});
