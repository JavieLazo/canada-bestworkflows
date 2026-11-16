<?php

declare(strict_types=1);

use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

function pboardUser(bool $allData = true, ?EmployeeProfile $employee = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Board '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach (['PROJECTS', 'TASKS'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
}

function pboardLogin(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
}

function pboardEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'BOARD-'.Str::random(8), 'first_name' => 'Board', 'last_name' => 'Member',
        'employment_status' => 'Regular', 'date_hired' => '2026-01-01', 'is_active' => true,
    ])->save();

    return $employee;
}

function pboardProject(?User $leader = null): Project
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::random(8), 'name' => 'Board project '.Str::random(8),
        'status' => 'ACTIVE', 'start_date' => '2026-01-01', 'manager_id' => $leader?->id,
    ])->save();

    return $project;
}

/** @param array<string, mixed> $attributes
 * @param  list<string>  $crew
 */
function pboardTask(Project $project, array $attributes = [], array $crew = []): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill([
        'project_id' => $project->id, 'code' => 'TSK-'.Str::random(8), 'title' => 'Board task '.Str::random(8),
        'status' => 'PENDING', 'priority' => 'NORMAL', 'assigned_at' => now(), ...$attributes,
    ])->save();
    $task->syncAssignees($crew, null);

    return $task;
}

it('opens Board from the overview and preserves filters when switching to List and back', function () {
    pboardLogin(pboardUser());
    $project = pboardProject();
    $match = pboardTask($project, ['title' => 'Launch checklist']);
    pboardTask($project, ['title' => 'Private planning']);

    $page = Livewire::test(Show::class, ['project' => $project->id]);
    $page
        ->assertSet('taskView', 'overview')
        ->set('taskView', 'board')
        ->assertSeeHtml('aria-label="Task board"');
    $page->set('search', 'Launch')
        ->assertViewHas('visibleCount', 1)
        ->assertSee($match->code);
    $page->set('taskView', 'list')
        ->assertSet('search', 'Launch')
        ->assertViewHas('visibleCount', 1)
        ->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$match->id]);
    $page->set('taskView', 'board')
        ->assertViewHas('visibleCount', 1)
        ->assertSee($match->code);
    $page->set('taskView', 'unsupported')
        ->assertSet('taskView', 'overview');
});

it('uses identical filters and scope for the board and list', function (array $filter) {
    $user = pboardUser();
    $employee = pboardEmployee();
    $project = pboardProject();
    pboardTask($project, ['title' => 'Launch brief', 'due_date' => '2026-10-01', 'priority' => 'HIGH'], [$employee->id]);
    pboardTask($project, ['status' => 'IN_PROGRESS', 'due_date' => '2026-11-01']);
    pboardTask($project, ['status' => 'SUBMITTED', 'due_date' => '2026-10-01']);
    pboardTask(pboardProject(), ['title' => 'Launch from another project']);
    $filters = [
        'q' => $filter['q'] ?? '', 'status' => $filter['status'] ?? 'ALL',
        'assignee' => $filter['assignee'] ?? 'ALL', 'priority' => $filter['priority'] ?? 'ALL',
        'queue' => $filter['queue'] ?? 'ALL',
    ];
    if ($filters['assignee'] === 'MEMBER') {
        $filters['assignee'] = $employee->id;
    }
    $lists = app(WorkLists::class);
    $actor = Access::forUser($user);
    $board = $lists->taskBoard($actor, $project->id, $filters, '2026-10-31');
    $list = $lists->taskPage($actor, $project->id, $filters, 'stack', 'asc', '2026-10-31', 1, 100);

    expect(collect($board)->flatMap(fn ($lane) => $lane->pluck('id'))->sort()->values()->all())
        ->toBe($list->pluck('id')->sort()->values()->all())
        ->and(array_sum(array_map(fn ($lane): int => $lane->total(), $board)))->toBe($list->total());
})->with([
    'all' => [[]], 'search' => [['q' => 'Launch']], 'status' => [['status' => 'IN_PROGRESS']],
    'priority' => [['priority' => 'HIGH']], 'assigned' => [['assignee' => 'MEMBER']],
    'unassigned' => [['assignee' => 'UNASSIGNED']], 'late' => [['queue' => 'OVERDUE']],
    'review' => [['queue' => 'REVIEW']], 'due soon' => [['queue' => 'DUE_SOON']],
]);

it('paginates each lane independently without hiding another status or losing the last page', function () {
    $actor = Access::forUser(pboardUser());
    $project = pboardProject();
    for ($i = 1; $i <= 25; $i++) {
        pboardTask($project, ['code' => sprintf('TSK-%03d', $i)]);
    }
    $active = pboardTask($project, ['status' => 'IN_PROGRESS']);
    $filters = ['q' => '', 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL'];
    $lists = app(WorkLists::class);
    $first = $lists->taskBoard($actor, $project->id, $filters, '2026-10-31');
    $last = $lists->taskBoard($actor, $project->id, $filters, '2026-10-31', ['PENDING' => 999]);

    expect($first['PENDING']->count())->toBe(20)
        ->and($first['PENDING']->total())->toBe(25)
        ->and($first['IN_PROGRESS']->pluck('id')->all())->toBe([$active->id])
        ->and($last['PENDING']->currentPage())->toBe(2)
        ->and($last['PENDING']->count())->toBe(5)
        ->and($last['PENDING']->pluck('id')->intersect($first['PENDING']->pluck('id'))->all())->toBe([]);
});

it('moves work through the existing command and keeps every assignee visible in My Tasks', function () {
    $members = [pboardEmployee(), pboardEmployee()];
    pboardLogin(pboardUser());
    $project = pboardProject();
    $task = pboardTask($project, crew: array_map(fn ($member): string => $member->id, $members));
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('moveBoardTask', $task->id, 'IN_PROGRESS', 'PENDING')
        ->assertReturned(true)
        ->assertSet('reviewOpen', false);

    expect($task->fresh()->status)->toBe('IN_PROGRESS')
        ->and($task->assignments()->count())->toBe(2);
    foreach ($members as $member) {
        pboardLogin(pboardUser(false, $member));
        Livewire::test(MyTasksPage::class)
            ->assertSee($task->code)
            ->assertViewHas('rows', fn ($rows): bool => $rows->first()->status === 'IN_PROGRESS');
    }
});

it('lets a project leader move work while refusing an ordinary assignee and a former leader', function () {
    $member = pboardEmployee();
    $leader = pboardUser(false);
    $project = pboardProject($leader);
    $task = pboardTask($project, crew: [$member->id]);
    pboardLogin(pboardUser(false, $member));
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('moveBoardTask', $task->id, 'IN_PROGRESS', 'PENDING');
    expect($task->fresh()->status)->toBe('PENDING');

    pboardLogin($leader);
    $page = Livewire::test(Show::class, ['project' => $project->id]);
    $page->call('moveBoardTask', $task->id, 'IN_PROGRESS', 'PENDING');
    expect($task->fresh()->status)->toBe('IN_PROGRESS');

    $project->forceFill(['manager_id' => null])->save();
    $page->call('moveBoardTask', $task->id, 'PENDING', 'IN_PROGRESS');
    expect($task->fresh()->status)->toBe('IN_PROGRESS');
});

it('refuses a stale drag, another project and submission without a hand-in', function () {
    pboardLogin(pboardUser());
    $project = pboardProject();
    $task = pboardTask($project);
    $other = pboardTask(pboardProject());
    $page = Livewire::test(Show::class, ['project' => $project->id]);
    $task->forceFill(['status' => 'IN_PROGRESS'])->save();
    $page->call('moveBoardTask', $task->id, 'PENDING', 'PENDING')->assertReturned(false)
        ->call('moveBoardTask', $other->id, 'IN_PROGRESS', 'PENDING')->assertReturned(false)
        ->call('moveBoardTask', $task->id, 'SUBMITTED', 'IN_PROGRESS')->assertReturned(false);

    expect($task->fresh()->status)->toBe('IN_PROGRESS')
        ->and($other->fresh()->status)->toBe('PENDING')
        ->and($task->fresh()->submitted_at)->toBeNull();
});

it('opens the task for a review decision without approving, cancelling or returning work', function (string $target) {
    $member = pboardEmployee();
    pboardLogin(pboardUser());
    $project = pboardProject();
    $task = pboardTask($project, ['status' => 'SUBMITTED', 'submitted_by' => $member->id, 'submitted_at' => now(), 'remarks' => 'Original hand-in'], [$member->id]);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('moveBoardTask', $task->id, $target, 'SUBMITTED')
        ->assertReturned(false)
        ->assertSet('reviewOpen', true)
        ->assertSet('openTaskId', $task->id);

    expect($task->fresh()->status)->toBe('SUBMITTED')
        ->and($task->fresh()->remarks)->toBe('Original hand-in')
        ->and($task->fresh()->reviewed_by)->toBeNull();
})->with(['COMPLETED', 'RETURNED', 'CANCELLED']);

it('keeps individual response approval and private content intact when a task is dragged to Completed', function () {
    $member = pboardEmployee();
    $manager = pboardUser();
    $project = pboardProject();
    $task = pboardTask($project, ['status' => 'IN_PROGRESS', 'submission_mode' => 'INDIVIDUAL'], [$member->id]);
    $response = new ProjectTaskSubmission;
    $response->forceFill(['task_id' => $task->id, 'employee_id' => $member->id, 'status' => 'SUBMITTED', 'remarks' => 'Private response', 'submitted_at' => now(), 'revision' => 1])->save();
    pboardLogin($manager);
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('moveBoardTask', $task->id, 'COMPLETED', 'IN_PROGRESS')
        ->assertSet('reviewOpen', true)
        ->assertSet('manualStatus', '')
        ->call('selectResponse', $member->id)
        ->assertSee('Private response');

    expect($task->fresh()->status)->toBe('IN_PROGRESS')
        ->and($response->fresh()->status)->toBe('SUBMITTED')
        ->and($response->fresh()->revision)->toBe(1);

    $peer = pboardEmployee();
    $task->syncAssignees([$member->id, $peer->id], null);
    pboardLogin(pboardUser(false, $peer));
    Livewire::test(Show::class, ['project' => $project->id])
        ->assertDontSee('Private response')
        ->call('openTask', $task->id)
        ->assertDontSee('Private response');
});

it('shows only authorized task cards and keeps revocation effective on the next action', function () {
    $member = pboardEmployee();
    $project = pboardProject();
    $visible = pboardTask($project, crew: [$member->id]);
    $hidden = pboardTask($project);
    pboardLogin(pboardUser(false, $member));
    Livewire::test(Show::class, ['project' => $project->id])
        ->set('taskView', 'board')
        ->assertViewHas('visibleCount', 1)
        ->assertSee($visible->code)
        ->assertDontSee($hidden->code);

    $leader = pboardUser(false);
    $project->forceFill(['manager_id' => $leader->id])->save();
    pboardLogin($leader);
    $page = Livewire::test(Show::class, ['project' => $project->id]);
    RoleModuleGrant::query()->where('role_id', $leader->role_id)->where('module', 'PROJECTS')->delete();
    pboardLogin($leader->fresh());
    $page->call('moveBoardTask', $visible->id, 'IN_PROGRESS', 'PENDING');
    expect($visible->fresh()->status)->toBe('PENDING');
    $this->getJson(route('projects.show', $project->id))->assertForbidden();
});
