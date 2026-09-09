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
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * PROJECTS module: the route gate, the row scoping that must return NOTHING
 * for an employee-less actor, one full happy-path flow (create project → hand
 * out a task → accept the submission), and the transition maps that the
 * reference API famously skipped.
 */
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

function projTask(Project $project, array $attributes = []): ProjectTask
{
    static $n = 100;
    $n++;

    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => 'TSK-'.$n,
        'project_id' => $project->id,
        'title' => 'Task '.Str::random(8),
        'status' => 'PENDING',
        'priority' => 'NORMAL',
        'assigned_at' => CarbonImmutable::now(),
    ], $attributes))->save();

    return $task;
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
    projTask($project, ['assignee_id' => null]); // the row IS NULL would leak

    $user = projActor(['PROJECTS'], allData: false, employeeId: null);
    $actor = Access::forActor($user);

    expect(ProjectsData::scopedProjects($actor)->count())->toBe(0);
    expect(ProjectsData::scopedTasks($actor)->count())->toBe(0);

    // And the page reflects the same emptiness rather than leaking the row.
    $this->get('/projects')->assertOk()->assertDontSee($project->code);
});

it('narrows a linked non-manager to the projects holding their own tasks', function () {
    $mine = projEmployee('BWF-0101', 'Liza', 'Domingo');
    $visible = projProject(['name' => 'Visible Project']);
    $hidden = projProject(['name' => 'Hidden Project']);
    projTask($visible, ['assignee_id' => $mine->id]);
    projTask($hidden, ['assignee_id' => null]);

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
    $employee = projEmployee('BWF-0201', 'Ana', 'Garcia');
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
        ->set('taskAssigneeId', $employee->id)
        ->set('taskPriority', 'HIGH')
        ->call('saveTask')
        ->assertSet('adding', false);

    $task = ProjectTask::query()->where('title', 'Draft the homepage')->firstOrFail();
    expect($task->code)->toBe('TSK-001');
    expect($task->status)->toBe('PENDING');
    expect($task->getAttribute('assigned_by'))->toBe($manager->getKey());
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
| Transition maps — enforced here, unlike the reference API
|--------------------------------------------------------------------------
*/

it('refuses a review decision the manager transition map does not allow', function () {
    $project = projProject();
    $task = projTask($project, ['status' => 'PENDING']);
    projActor(['PROJECTS'], allData: true);

    // MANAGER_TRANSITIONS[PENDING] is [CANCELLED] — completing pending work
    // was the reference API's flat-list bug; the map closes it.
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('review', 'COMPLETED');

    expect($task->refresh()->status)->toBe('PENDING');
});

it('refuses reopening a completed project through the status select', function () {
    $project = projProject(['status' => 'COMPLETED']);
    projActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('changeStatus', 'ACTIVE');

    expect($project->refresh()->status)->toBe('COMPLETED');
});

it('requires a remark before returning a task', function () {
    $employee = projEmployee('BWF-0301', 'Ben', 'Reyes');
    $project = projProject();
    $task = projTask($project, [
        'status' => 'SUBMITTED',
        'assignee_id' => $employee->id,
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
