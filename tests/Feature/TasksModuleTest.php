<?php

declare(strict_types=1);

use App\Livewire\Tasks\MyTasksPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * TASKS module — My Tasks (the assignee's queue).
 *
 * Covers the three non-negotiables: routes gate on the module grant, scoped
 * queries return nothing for an employee-less actor, and the happy-path flow
 * (open → start → submit) moves through TaskPolicy with the audit rows, the
 * thread comment and the notification the reference server wrote.
 */
function tasksRole(array $modules, bool $allData = false): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Tasks '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

function tasksActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => tasksRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function tasksEmployee(string $code, string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Clerk',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

function tasksProject(string $code = 'PRJ-001'): Project
{
    $project = new Project;
    $project->forceFill([
        'code' => $code,
        'name' => 'Project '.$code,
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    return $project;
}

function tasksTask(
    Project $project,
    ?string $assigneeId,
    string $status = 'PENDING',
    string $code = 'TSK-001',
    ?int $assignedBy = null,
): ProjectTask {
    $task = new ProjectTask;
    $task->forceFill([
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Task '.$code,
        'assignee_id' => $assigneeId,
        'status' => $status,
        'priority' => 'NORMAL',
        'assigned_by' => $assignedBy,
    ])->save();

    return $task;
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('redirects a guest to login', function () {
    $this->get('/my-tasks')->assertRedirect(route('login'));
});

it('redirects an account whose role lacks the TASKS module to its landing page', function () {
    tasksActor(['EDTR']);

    // A browser is REDIRECTED to whatever it may see (EnsureModuleAccess);
    // only an API-shaped caller gets the bare 403.
    $this->get('/my-tasks')->assertRedirect('/edtr');
});

it('renders for a TASKS holder', function () {
    $employee = tasksEmployee('EMP-001');
    tasksActor(['TASKS'], employeeId: $employee->getKey());

    $this->get('/my-tasks')
        ->assertOk()
        ->assertSee('My Tasks');
});

/*
|--------------------------------------------------------------------------
| Scoping — an employee-less account owns no rows anywhere
|--------------------------------------------------------------------------
*/

it('shows the unlinked card, and no queue, to an account with no employee link', function () {
    $project = tasksProject();
    tasksTask($project, null, code: 'TSK-777'); // the unassigned row IS NULL would leak
    tasksActor(['TASKS']);

    $this->get('/my-tasks')
        ->assertOk()
        ->assertSee('linked to an employee record yet')
        ->assertDontSee('TSK-777');
});

it('refuses to open any task for an employee-less actor', function () {
    $project = tasksProject();
    $task = tasksTask($project, null);
    tasksActor(['TASKS']);

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->assertSet('openId', null);
});

it('lists only the tasks assigned to the actor', function () {
    $mine = tasksEmployee('EMP-001');
    $other = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    $project = tasksProject();
    tasksTask($project, $mine->getKey(), code: 'TSK-MINE');
    tasksTask($project, $other->getKey(), code: 'TSK-THEIRS', status: 'PENDING');
    tasksActor(['TASKS'], employeeId: $mine->getKey());

    Livewire::test(MyTasksPage::class)
        ->assertSee('TSK-MINE')
        ->assertDontSee('TSK-THEIRS');
});

it('will not open another employee\'s task even with the id in hand', function () {
    $mine = tasksEmployee('EMP-001');
    $other = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    $project = tasksProject();
    $theirs = tasksTask($project, $other->getKey(), code: 'TSK-THEIRS');
    tasksActor(['TASKS'], employeeId: $mine->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $theirs->getKey())
        ->assertSet('openId', null);
});

/*
|--------------------------------------------------------------------------
| The happy path — open → start → submit, through TaskPolicy
|--------------------------------------------------------------------------
*/

it('walks a task from pending to submitted with audit, thread and notification', function () {
    $manager = User::factory()->create();
    $employee = tasksEmployee('EMP-001');
    $project = tasksProject();
    $task = tasksTask($project, $employee->getKey(), assignedBy: (int) $manager->getKey());
    tasksActor(['TASKS'], employeeId: $employee->getKey());

    // Start: PENDING → IN_PROGRESS.
    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->assertSet('openId', $task->getKey())
        ->call('startTask');

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    expect($task->submitted_at)->toBeNull();
    expect(AuditLog::query()->where('action', 'TASK_IN_PROGRESS')->where('entity_id', $task->getKey())->exists())->toBeTrue();

    // Submit: IN_PROGRESS → SUBMITTED, with the note.
    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('remarks', 'Painted the fence and photographed it.')
        ->call('submitTask');

    $task->refresh();
    expect($task->status)->toBe('SUBMITTED');
    expect($task->submitted_at)->not->toBeNull();
    expect($task->remarks)->toBe('Painted the fence and photographed it.');

    // The thread keeps what `remarks` will later overwrite.
    $comment = TaskComment::query()->where('task_id', $task->getKey())->first();
    expect($comment)->not->toBeNull();
    expect($comment->side)->toBe('ASSIGNEE');
    expect($comment->event)->toBe('SUBMITTED');
    expect($comment->body)->toBe('Painted the fence and photographed it.');

    expect(AuditLog::query()->where('action', 'TASK_SUBMITTED')->where('entity_id', $task->getKey())->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'TASK_COMMENTED')->exists())->toBeTrue();

    // A submission is news to whoever handed the task out.
    $bell = Notification::query()->where('user_id', $manager->getKey())->where('kind', 'TASK_SUBMITTED')->first();
    expect($bell)->not->toBeNull();
    expect($bell->title)->toBe('TSK-001 submitted for review');
    expect($bell->href)->toBe('/projects');
});

it('refuses to submit without a note', function () {
    $employee = tasksEmployee('EMP-001');
    $project = tasksProject();
    $task = tasksTask($project, $employee->getKey(), status: 'IN_PROGRESS');
    tasksActor(['TASKS'], employeeId: $employee->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('remarks', '   ')
        ->call('submitTask');

    expect($task->refresh()->status)->toBe('IN_PROGRESS');
});

it('refuses a transition the policy does not allow', function () {
    $employee = tasksEmployee('EMP-001');
    $project = tasksProject();
    $task = tasksTask($project, $employee->getKey(), status: 'SUBMITTED');
    tasksActor(['TASKS'], employeeId: $employee->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->call('startTask');

    expect($task->refresh()->status)->toBe('SUBMITTED');
});

it('posts a standalone comment to the thread', function () {
    $manager = User::factory()->create();
    $employee = tasksEmployee('EMP-001');
    $project = tasksProject();
    $task = tasksTask($project, $employee->getKey(), status: 'SUBMITTED', assignedBy: (int) $manager->getKey());
    tasksActor(['TASKS'], employeeId: $employee->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('commentBody', 'Is the second coat needed?')
        ->call('postComment')
        ->assertSet('commentBody', '');

    $comment = TaskComment::query()->where('task_id', $task->getKey())->first();
    expect($comment)->not->toBeNull();
    expect($comment->side)->toBe('ASSIGNEE');
    expect($comment->event)->toBeNull();

    $bell = Notification::query()->where('user_id', $manager->getKey())->where('kind', 'TASK_COMMENT')->first();
    expect($bell)->not->toBeNull();
    expect($bell->title)->toBe('New note on TSK-001');
});

/*
|--------------------------------------------------------------------------
| Attachments — filesystem storage, status locks, download gate
|--------------------------------------------------------------------------
*/

it('stores a submission file while the task is in progress', function () {
    Storage::fake('local');
    $employee = tasksEmployee('EMP-001');
    $project = tasksProject();
    $task = tasksTask($project, $employee->getKey(), status: 'IN_PROGRESS');
    tasksActor(['TASKS'], employeeId: $employee->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('upload', UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'));

    $file = ProjectTaskFile::query()->where('task_id', $task->getKey())->first();
    expect($file)->not->toBeNull();
    expect($file->kind)->toBe('SUBMISSION');
    expect($file->file_name)->toBe('evidence.pdf');
    Storage::disk('local')->assertExists($file->path);
    expect(AuditLog::query()->where('action', 'TASK_FILE_ATTACHED')->exists())->toBeTrue();
});

it('locks submission evidence once the task is under review', function () {
    Storage::fake('local');
    $employee = tasksEmployee('EMP-001');
    $project = tasksProject();
    $task = tasksTask($project, $employee->getKey(), status: 'SUBMITTED');
    tasksActor(['TASKS'], employeeId: $employee->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('upload', UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'));

    expect(ProjectTaskFile::query()->where('task_id', $task->getKey())->exists())->toBeFalse();
});

/**
 * One stored SUBMISSION file on EMP-001's task.
 *
 * @return array{0: EmployeeProfile, 1: ProjectTaskFile}
 */
function tasksStoredFile(): array
{
    Storage::fake('local');
    $mine = tasksEmployee('EMP-001');
    $task = tasksTask(tasksProject(), $mine->getKey(), status: 'IN_PROGRESS');

    $path = UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf')->store('task-files', 'local');
    $file = new ProjectTaskFile;
    $file->fill([
        'task_id' => $task->getKey(),
        'kind' => 'SUBMISSION',
        'file_name' => 'evidence.pdf',
        'mime' => 'application/pdf',
        'size' => 10240,
        'path' => (string) $path,
    ]);
    $file->save();

    return [$mine, $file];
}

// One actor per test on purpose: Access is a scoped container binding, and a
// second in-test request would otherwise read the first actor's instance.

it('lets the assignee download their own evidence', function () {
    [$mine, $file] = tasksStoredFile();
    tasksActor(['TASKS'], employeeId: $mine->getKey());

    $this->get(route('tasks.files.download', $file->getKey()))->assertOk();
});

it('refuses the download to a different employee — all_data widens under PROJECTS alone', function () {
    [, $file] = tasksStoredFile();
    $other = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    tasksActor(['TASKS'], allData: true, employeeId: $other->getKey());

    $this->get(route('tasks.files.download', $file->getKey()))->assertForbidden();
});

it('lets a reviewer download the evidence', function () {
    [, $file] = tasksStoredFile();
    tasksActor(['PROJECTS'], allData: true);

    $this->get(route('tasks.files.download', $file->getKey()))->assertOk();
});
