<?php

declare(strict_types=1);

use App\Livewire\Tasks\MyTasksPage;
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
use App\Support\Access;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * TASKS module — My Tasks (the assignee's queue).
 *
 * Covers the three non-negotiables: routes gate on the module grant, scoped
 * queries return nothing for an employee-less actor, and the happy-path flow
 * (open → start → submit) moves through TaskPolicy with the audit rows, the
 * thread comment and the notification the reference server wrote. Then the
 * shared-task rules: any assignee may move the task, every fan-out lands once
 * per ACCOUNT and never on the actor, and the evidence is the crew's to see
 * but only its uploader's to withdraw. Evidence names the member who sent it,
 * and a member's own response row leaves the task with their membership.
 */

/** @param list<string> $modules ModuleCode constants to grant */
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

/** @param list<string> $modules ModuleCode constants to grant */
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

/**
 * One task, assigned to none, one or several employees through the
 * project_task_assignees pivot (ProjectTask::syncAssignees — the only writer
 * that mints the pivot's ULID).
 *
 * @param  string|list<string>|null  $assignees  employee id(s); null = unassigned
 */
function tasksTask(
    Project $project,
    string|array|null $assignees,
    string $status = 'PENDING',
    string $code = 'TSK-001',
    ?int $assignedBy = null,
): ProjectTask {
    $task = new ProjectTask;
    $task->forceFill([
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Task '.$code,
        'status' => $status,
        'priority' => 'NORMAL',
        'assigned_by' => $assignedBy,
    ])->save();

    $ids = $assignees === null ? [] : (array) $assignees;
    if ($ids !== []) {
        $task->syncAssignees($ids, $assignedBy);
    }

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
    expect($bell->title)->toBe('Task TSK-001 submitted for review');
    expect($bell->href)->toBe('/projects');

    // Working alone: submitted_by is the submitter, and there is nobody
    // else to tell — no second TASK_SUBMITTED row anywhere.
    expect($task->submitted_by)->toBe($employee->getKey());
    expect(Notification::query()->where('kind', 'TASK_SUBMITTED')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| A shared task — several assignees on one pivot
|--------------------------------------------------------------------------
*/

it('lists and opens a shared task for its second assignee', function () {
    $first = tasksEmployee('EMP-001');
    $second = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    $project = tasksProject();
    $task = tasksTask($project, [$first->getKey(), $second->getKey()], code: 'TSK-SHARED');
    tasksActor(['TASKS'], employeeId: $second->getKey());

    Livewire::test(MyTasksPage::class)
        ->assertSee('TSK-SHARED')
        ->call('openTask', $task->getKey())
        ->assertSet('openId', $task->getKey())
        // The dialog names the peer, not the actor.
        ->assertSee('Working with:')
        ->assertSee('Liza Domingo');
});

it('submits a shared task for everyone, records who did, and tells the co-assignee', function () {
    $manager = User::factory()->create();
    $me = tasksEmployee('EMP-001');
    $peer = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    $peerLogin = User::factory()->create(['employee_id' => $peer->getKey(), 'is_active' => true]);
    $project = tasksProject();
    $task = tasksTask($project, [$me->getKey(), $peer->getKey()], status: 'IN_PROGRESS', assignedBy: (int) $manager->getKey());
    tasksActor(['TASKS'], employeeId: $me->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('remarks', 'Both halves are done.')
        ->call('submitTask');

    $task->refresh();
    expect($task->status)->toBe('SUBMITTED');
    expect($task->submitted_by)->toBe($me->getKey());
    // Membership is untouched by a submission.
    expect($task->assigneeIds())->toBe(collect([$me->getKey(), $peer->getKey()])->sort()->values()->all());

    // The assigner is told...
    expect(Notification::query()->where('user_id', $manager->getKey())->where('kind', 'TASK_SUBMITTED')->exists())->toBeTrue();

    // ...and so is the peer, by name, pointed at their own queue.
    $bell = Notification::query()->where('user_id', $peerLogin->getKey())->where('kind', 'TASK_SUBMITTED')->first();
    expect($bell)->not->toBeNull();
    expect($bell->title)->toBe('Liza Domingo submitted Task TSK-001 for review');
    expect($bell->body)->toBe('Both halves are done.');
    expect($bell->href)->toBe('/my-tasks');

    // The submitter is not told about their own submission.
    expect(Notification::query()->where('kind', 'TASK_SUBMITTED')->count())->toBe(2);

    // Reopened by the submitter, the note reads as their own.
    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->assertSee('Your last submission')
        ->assertSee('Both halves are done.');
});

it('tells the co-assignee about a peer\'s comment, and every assignee about a management note', function () {
    $manager = User::factory()->create();
    $me = tasksEmployee('EMP-001');
    $peer = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    $peerLogin = User::factory()->create(['employee_id' => $peer->getKey(), 'is_active' => true]);
    $project = tasksProject();
    $task = tasksTask($project, [$me->getKey(), $peer->getKey()], status: 'IN_PROGRESS', assignedBy: (int) $manager->getKey());
    tasksActor(['TASKS'], employeeId: $me->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('commentBody', 'I will take the second half.')
        ->call('postComment');

    expect(Notification::query()->where('user_id', $manager->getKey())->where('kind', 'TASK_COMMENT')->exists())->toBeTrue();
    $bell = Notification::query()->where('user_id', $peerLogin->getKey())->where('kind', 'TASK_COMMENT')->first();
    expect($bell)->not->toBeNull();
    expect($bell->title)->toBe('New note on Task TSK-001');
    expect($bell->href)->toBe('/my-tasks');
    expect(Notification::query()->where('kind', 'TASK_COMMENT')->count())->toBe(2);
});

it('tells an assigner who is also on the crew once, as the assigner, and the actor never', function () {
    $me = tasksEmployee('EMP-001');
    $lead = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    // The task leader put themselves on the crew, so their login is reachable
    // twice in one fan-out: as assigned_by and through the pivot.
    $leadLogin = User::factory()->create(['employee_id' => $lead->getKey(), 'is_active' => true]);
    $project = tasksProject();
    $task = tasksTask($project, [$me->getKey(), $lead->getKey()], status: 'IN_PROGRESS', assignedBy: (int) $leadLogin->getKey());
    $myLogin = tasksActor(['TASKS'], employeeId: $me->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('remarks', 'Both halves are done.')
        ->call('submitTask');

    $submitted = Notification::query()->where('kind', 'TASK_SUBMITTED')->get();
    expect($submitted)->toHaveCount(1)
        ->and((int) $submitted[0]->user_id)->toBe((int) $leadLogin->getKey())
        // Heard once, as the assigner, through the review notice that links to
        // the Projects screen. An assigner on the crew cannot decide it there
        // (ReviewTask refuses a self-review); another reviewer does.
        ->and($submitted[0]->title)->toBe('Task TSK-001 submitted for review')
        ->and($submitted[0]->href)->toBe('/projects');

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('commentBody', 'The photos are in the shared folder.')
        ->call('postComment');

    $noted = Notification::query()->where('kind', 'TASK_COMMENT')->get();
    expect($noted)->toHaveCount(1)
        ->and((int) $noted[0]->user_id)->toBe((int) $leadLogin->getKey())
        ->and($noted[0]->href)->toBe('/projects');

    // The actor is never told about their own actions.
    expect(Notification::query()->where('user_id', $myLogin->getKey())->count())->toBe(0);
});

it('never tells the actor about their own action, from either side of the thread', function () {
    $me = tasksEmployee('EMP-001');
    $peer = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    $peerLogin = User::factory()->create(['employee_id' => $peer->getKey(), 'is_active' => true]);
    $project = tasksProject();
    // A reviewer (all_data + PROJECTS) who handed the task out AND put
    // themselves on the crew: reachable as assigned_by, through the pivot,
    // and as the management side of the thread — three ways to be told nothing.
    $login = tasksActor(['PROJECTS', 'TASKS'], allData: true, employeeId: $me->getKey());
    $task = tasksTask($project, [$me->getKey(), $peer->getKey()], status: 'IN_PROGRESS', assignedBy: (int) $login->getKey());

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->getKey())
        ->set('commentBody', 'Starting on the east wall.')
        ->call('postComment')
        ->set('remarks', 'Both walls are done.')
        ->call('submitTask');

    // The management branch is the one that ran.
    expect(TaskComment::query()->where('task_id', $task->getKey())->where('side', 'MANAGEMENT')->exists())->toBeTrue();

    expect(Notification::query()->where('user_id', $login->getKey())->count())->toBe(0);
    // The peer still hears both, once each.
    expect(Notification::query()->where('user_id', $peerLogin->getKey())->where('kind', 'TASK_COMMENT')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $peerLogin->getKey())->where('kind', 'TASK_SUBMITTED')->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(2);
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
    expect($bell->title)->toBe('New note on Task TSK-001');
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

it('names the uploading member on submission evidence, never another crew member', function () {
    Storage::fake('local');
    $ana = tasksEmployee('EMP-001', 'Ana', 'Garcia');
    $ben = tasksEmployee('EMP-002', 'Ben', 'Cruz');
    $task = tasksTask(tasksProject(), [$ana->getKey(), $ben->getKey()], status: 'IN_PROGRESS');
    // GROUP too: the stamp does not wait for an INDIVIDUAL task.
    expect($task->fresh()?->submission_mode)->toBe('GROUP');

    // The member whose id sorts LAST uploads first, then the other one, so a
    // writer that stamped the crew's first or last id (assigneeIds() is
    // sorted) instead of the session's own link fails one of the two.
    $crew = [$ana, $ben];
    usort($crew, static fn (EmployeeProfile $a, EmployeeProfile $b): int => strcmp((string) $a->getKey(), (string) $b->getKey()));
    [$sortsFirst, $sortsLast] = $crew;

    foreach ([$sortsLast, $sortsFirst] as $uploader) {
        tasksActor(['TASKS'], employeeId: $uploader->getKey());
        app()->forgetInstance(Access::class);

        Livewire::test(MyTasksPage::class)
            ->call('openTask', $task->getKey())
            ->set('upload', UploadedFile::fake()->create($uploader->employee_code.'.pdf', 10, 'application/pdf'));
    }

    expect(ProjectTaskFile::query()->where('task_id', $task->getKey())->pluck('employee_id', 'file_name')->all())->toEqual([
        $sortsLast->employee_code.'.pdf' => $sortsLast->getKey(),
        $sortsFirst->employee_code.'.pdf' => $sortsFirst->getKey(),
    ]);
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
 * One stored SUBMISSION file on EMP-001's task — shared with `$peers` when
 * given, and stamped with `$uploadedBy` (a users id) when given.
 *
 * @param  list<string>  $peers
 * @return array{0: EmployeeProfile, 1: ProjectTaskFile}
 */
function tasksStoredFile(array $peers = [], ?int $uploadedBy = null): array
{
    Storage::fake('local');
    $mine = tasksEmployee('EMP-001');
    $task = tasksTask(tasksProject(), [$mine->getKey(), ...$peers], status: 'IN_PROGRESS');

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
    // Actor column — set the way updatedUpload() sets it, never mass-assigned.
    $file->uploaded_by = $uploadedBy;
    $file->save();

    return [$mine, $file];
}

/**
 * The download is the stored bytes, under the stored name and type, as an
 * attachment the browser must not sniff. A 200 alone would also pass for the
 * wrong file, an inline render or a bare octet-stream, so every successful
 * download on EITHER route is checked with this.
 *
 * @param  TestResponse<Response|StreamedResponse>  $response
 */
function tasksAssertDownloaded(TestResponse $response, ProjectTaskFile $file): void
{
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename=evidence.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe(Storage::disk('local')->get($file->path));
}

/**
 * Both routes answer a missing row, and stored-but-gone bytes, with one
 * sentence: the page that follows a stale link should read the same whether
 * it came from My Tasks or from the project dialog.
 *
 * @param  TestResponse<Response|StreamedResponse>  $response
 */
function tasksAssertFileNotFound(TestResponse $response): void
{
    $response->assertNotFound();
    $exception = $response->exception;
    assert($exception instanceof NotFoundHttpException);
    expect($exception->getMessage())->toBe('That file was not found.');
}

// One actor per test on purpose: Access is a scoped container binding, and a
// second in-test request would otherwise read the first actor's instance.

it('lets the assignee download their own evidence', function () {
    [$mine, $file] = tasksStoredFile();
    tasksActor(['TASKS'], employeeId: $mine->getKey());

    tasksAssertDownloaded($this->get(route('tasks.files.download', $file->getKey())), $file);
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

    tasksAssertDownloaded($this->get(route('tasks.files.download', $file->getKey())), $file);
});

it('lets a co-assignee download a peer\'s evidence on the shared task', function () {
    $peer = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    [, $file] = tasksStoredFile(peers: [$peer->getKey()]);
    tasksActor(['TASKS'], employeeId: $peer->getKey());

    tasksAssertDownloaded($this->get(route('tasks.files.download', $file->getKey())), $file);
});

it('refuses the download to an employee-less TASKS holder even on an unassigned task', function () {
    [, $file] = tasksStoredFile();
    // Strip the pivot so the task is unassigned — the shape a match on
    // `assignee_id IS NULL` would leak.
    $file->task?->syncAssignees([], null);
    tasksActor(['TASKS']);

    $this->get(route('tasks.files.download', $file->getKey()))->assertForbidden();
});

// The same rows are served a second time under /projects/task-files/{file}
// (`projects.task-files.download`, linked from the project dialog). The two
// routes stay separate — different URLs, both live in Blade — but one
// controller serves both, so the same actors get the same answers.

it('serves the projects-side download to the assignee', function () {
    [$mine, $file] = tasksStoredFile();
    tasksActor(['TASKS'], employeeId: $mine->getKey());

    tasksAssertDownloaded($this->get(route('projects.task-files.download', $file->getKey())), $file);
});

it('serves the projects-side download to a reviewer', function () {
    [, $file] = tasksStoredFile();
    tasksActor(['PROJECTS'], allData: true);

    tasksAssertDownloaded($this->get(route('projects.task-files.download', $file->getKey())), $file);
});

it('refuses the projects-side download to a different employee holding all_data under TASKS', function () {
    [, $file] = tasksStoredFile();
    $other = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    tasksActor(['TASKS'], allData: true, employeeId: $other->getKey());

    $this->get(route('projects.task-files.download', $file->getKey()))->assertForbidden();
});

it('refuses the projects-side download to an employee-less TASKS holder on an unassigned task', function () {
    [, $file] = tasksStoredFile();
    $file->task?->syncAssignees([], null);
    tasksActor(['TASKS']);

    $this->get(route('projects.task-files.download', $file->getKey()))->assertForbidden();
});

it('answers a missing file row with one 404 sentence on both routes', function () {
    tasksActor(['PROJECTS'], allData: true);

    tasksAssertFileNotFound($this->get(route('tasks.files.download', 'no-such-file')));
    tasksAssertFileNotFound($this->get(route('projects.task-files.download', 'no-such-file')));
});

it('answers a row whose bytes are gone with the same 404 sentence on both routes', function () {
    [, $file] = tasksStoredFile();
    Storage::disk('local')->delete($file->path);
    tasksActor(['PROJECTS'], allData: true);

    tasksAssertFileNotFound($this->get(route('tasks.files.download', $file->getKey())));
    tasksAssertFileNotFound($this->get(route('projects.task-files.download', $file->getKey())));
});

it('lets only the uploader withdraw a submission file, never a co-assignee', function () {
    $peer = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    $uploaderEmployee = tasksEmployee('EMP-003', 'Nora', 'Reyes');
    $uploader = User::factory()->create(['employee_id' => $uploaderEmployee->id]);
    [, $file] = tasksStoredFile(peers: [$peer->getKey()], uploadedBy: (int) $uploader->getKey());
    tasksActor(['TASKS'], employeeId: $peer->getKey());

    // The peer is on the task but did not upload the file: refused, row kept.
    Livewire::test(MyTasksPage::class)
        ->call('openTask', $file->task_id)
        ->assertSet('openId', $file->task_id)
        // The list is the crew's, and somebody else's file says whose it is...
        ->assertSee('Submission files')
        ->assertDontSee('Your files')
        ->assertSee('by Nora Reyes')
        // ...but the trash button is not even offered on a peer's file...
        ->assertDontSee('Remove evidence.pdf')
        ->call('deleteFile', $file->getKey())
        ->assertForbidden();

    expect(ProjectTaskFile::query()->whereKey($file->getKey())->exists())->toBeTrue();
    Storage::disk('local')->assertExists($file->path);
});

it('lets the uploader withdraw their own submission file on a shared task', function () {
    $peer = tasksEmployee('EMP-002', 'Ramon', 'Cruz');
    $uploaderEmployee = tasksEmployee('EMP-003', 'Nora', 'Reyes');
    [, $file] = tasksStoredFile(peers: [$peer->getKey(), $uploaderEmployee->getKey()]);
    // The uploader is EMP-003's account, on the task as a third assignee.
    $login = tasksActor(['TASKS'], employeeId: $uploaderEmployee->getKey());
    $file->uploaded_by = (int) $login->getKey();
    $file->save();

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $file->task_id)
        // Their own file: the trash button, and no "by" line — the uploader
        // would be named by their employee record's spelling.
        ->assertSee('Remove evidence.pdf')
        ->assertDontSee('by Nora Reyes')
        ->call('deleteFile', $file->getKey());

    expect(ProjectTaskFile::query()->whereKey($file->getKey())->exists())->toBeFalse();
    expect(AuditLog::query()->where('action', 'TASK_FILE_REMOVED')->where('entity_id', $file->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Individual responses follow membership
|--------------------------------------------------------------------------
*/

/** One member's own response row on `$task`, written straight to the table. */
function tasksResponse(ProjectTask $task, EmployeeProfile $member, string $status = 'SUBMITTED'): ProjectTaskSubmission
{
    $row = new ProjectTaskSubmission;
    $row->fill([
        'task_id' => $task->getKey(),
        'employee_id' => $member->getKey(),
        'status' => $status,
        'remarks' => 'My part.',
        'submitted_at' => '2026-09-24 01:00:00.000',
        'revision' => 1,
    ])->save();

    return $row;
}

it('deletes a removed member\'s own response with their membership, and nobody else\'s', function () {
    $ana = tasksEmployee('EMP-001', 'Ana', 'Garcia');
    $ben = tasksEmployee('EMP-002', 'Ben', 'Cruz');
    $project = tasksProject();
    $task = tasksTask($project, [$ana->getKey(), $ben->getKey()], status: 'IN_PROGRESS');
    $task->forceFill(['submission_mode' => 'INDIVIDUAL'])->save();
    // Ana also answers on another task, which this crew edit must not touch.
    $other = tasksTask($project, [$ana->getKey()], status: 'IN_PROGRESS', code: 'TSK-002');

    $anaRow = tasksResponse($task, $ana);
    $benRow = tasksResponse($task, $ben, 'APPROVED');
    $anaElsewhere = tasksResponse($other, $ana);

    $evidence = new ProjectTaskFile;
    $evidence->fill([
        'task_id' => $task->getKey(),
        'kind' => 'SUBMISSION',
        'file_name' => 'ana.pdf',
        'mime' => 'application/pdf',
        'size' => 10,
        'path' => 'task-files/ana.pdf',
    ]);
    $evidence->employee_id = $ana->getKey();
    $evidence->save();

    $diff = $task->syncAssignees([$ben->getKey()], null);

    // The diff callers notify from is unchanged.
    expect($diff)->toBe(['added' => [], 'removed' => [$ana->getKey()]]);
    expect(ProjectTaskSubmission::query()->whereKey($anaRow->getKey())->exists())->toBeFalse();
    expect(ProjectTaskSubmission::query()->whereKey($benRow->getKey())->value('status'))->toBe('APPROVED');
    expect(ProjectTaskSubmission::query()->whereKey($anaElsewhere->getKey())->exists())->toBeTrue();
    // Her evidence stays with the task, still hers.
    expect(ProjectTaskFile::query()->whereKey($evidence->getKey())->value('employee_id'))->toBe($ana->getKey());

    // Re-added, she starts again with no response at all.
    $task->syncAssignees([$ben->getKey(), $ana->getKey()], null);

    expect($task->submissions()->pluck('employee_id')->all())->toBe([$ben->getKey()]);
});
