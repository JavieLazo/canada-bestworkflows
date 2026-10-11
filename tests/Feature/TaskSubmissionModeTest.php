<?php

declare(strict_types=1);

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
use App\Modules\WorkManagement\Application\Commands\AttachSubmission;
use App\Modules\WorkManagement\Application\Commands\ChangeProjectStatus;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\DeleteSubmission;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\TaskEvidenceLocked;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An INDIVIDUAL task is answered member by member. Each assignee hands in
 * their own response, a reviewer approves or returns each one on its own, and
 * the task completes only once every CURRENT assignee's response is approved
 * — re-checked by the command, whatever a screen showed. The mode is chosen
 * for a crew of two or more and locks once anything has been handed in. A
 * hand-in or an upload that read the task before a switch or another hand-in
 * landed is refused, in either mode. A member's evidence follows their own
 * response, a member's note never reaches the thread, the audit trail or a
 * peer's bell, and every refusal leaves no trace: no row, no audit line, no
 * notice.
 */

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function tsubRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Tsub '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

function tsubEmployee(string $first): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(5)),
        'first_name' => $first,
        'last_name' => 'Tan',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * An active employee with `$accounts` active TASKS-only logins.
 *
 * @return array{0: EmployeeProfile, 1: User}
 */
function tsubMember(string $first, int $accounts = 1): array
{
    $employee = tsubEmployee($first);
    $role = tsubRole(['TASKS'], false);
    $logins = [];
    for ($i = 0; $i < $accounts; $i++) {
        $logins[] = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employee->getKey()]);
    }

    return [$employee, $logins[0]];
}

/** An all-data PROJECTS reviewer, linked to an employee who is on no crew. */
function tsubReviewer(): User
{
    return User::factory()->create(['role_id' => tsubRole(['PROJECTS'], true)->getKey(), 'employee_id' => tsubEmployee('Rita')->getKey()]);
}

/** `$user` as the signed-in actor, with a fresh Access for them. */
function tsubAs(User $user): Access
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function tsubProject(): Project
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::upper(Str::random(4)),
        'name' => 'Project '.Str::random(6),
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    return $project;
}

/**
 * A task shared by `$crew`, handed out by `$assigner`.
 *
 * @param  list<EmployeeProfile>  $crew
 * @param  array<string, mixed>  $extra
 */
function tsubTask(array $crew, ?User $assigner = null, string $status = 'IN_PROGRESS', string $mode = 'INDIVIDUAL', array $extra = []): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => 'TSK-'.Str::upper(Str::random(5)),
        'project_id' => tsubProject()->getKey(),
        'title' => 'Site survey',
        'status' => $status,
        'priority' => 'NORMAL',
        'submission_mode' => $mode,
        'assigned_at' => CarbonImmutable::now(),
        'assigned_by' => $assigner?->getKey(),
    ], $extra))->save();
    $task->syncAssignees(array_map(static fn (EmployeeProfile $e): string => $e->id, $crew), null);

    return $task->refresh();
}

/** A member's response row as a fixture, written directly. */
function tsubResponse(ProjectTask $task, EmployeeProfile $member, string $status, int $revision = 1): ProjectTaskSubmission
{
    $row = new ProjectTaskSubmission;
    $row->fill(['task_id' => $task->id, 'employee_id' => $member->id, 'status' => $status, 'remarks' => 'Fixture note', 'submitted_at' => CarbonImmutable::now()->utc(), 'revision' => $revision]);
    $row->save();

    return $row;
}

/** `$member`'s SUBMISSION evidence, uploaded by `$login`, with its bytes on the fake disk. */
function tsubFile(ProjectTask $task, EmployeeProfile $member, User $login, string $name): ProjectTaskFile
{
    $path = UploadedFile::fake()->create($name, 4, 'application/pdf')->store('task-files', 'local');
    $file = new ProjectTaskFile;
    $file->fill(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => $name, 'mime' => 'application/pdf', 'size' => 4096, 'path' => (string) $path]);
    $file->uploaded_by = (int) $login->getKey();
    $file->employee_id = $member->id;
    $file->save();

    return $file;
}

/**
 * The reviewer's details edit: `$crew` as the new assignee set, the rest as stored unless given.
 *
 * @param  list<EmployeeProfile>  $crew
 */
function tsubEdit(User $reviewer, ProjectTask $task, array $crew, ?string $mode = null, ?string $priority = null): ?string
{
    $task->refresh();

    return app(UpdateTaskDetails::class)->handle(tsubAs($reviewer), $task->project_id, $task->id, new TaskDetails(
        $priority ?? $task->priority,
        WorkRules::dateKey($task->due_date) ?? '',
        array_map(static fn (EmployeeProfile $e): string => $e->id, $crew),
        $mode,
    ));
}

function tsubSubmit(User $login, ProjectTask $task, string $note): bool
{
    return app(SubmitTask::class)->handle(tsubAs($login), $task->id, $note);
}

/** `$member`'s row as it stands, or null when they have none. */
function tsubRow(ProjectTask $task, EmployeeProfile $member): ?ProjectTaskSubmission
{
    return ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $member->id)->first();
}

/** A reviewer's decision on the row and revision `$member` has right now. */
function tsubDecide(User $reviewer, ProjectTask $task, EmployeeProfile $member, string $decision, string $note = ''): void
{
    $row = tsubRow($task, $member) ?? throw new RuntimeException('no response row to decide');
    app(ReviewTaskResponse::class)->handle(tsubAs($reviewer), $task->project_id, $task->id, $row->id, $decision, $note, $row->revision);
}

function tsubComplete(User $reviewer, ProjectTask $task, string $remark = ''): void
{
    app(ReviewTask::class)->handle(tsubAs($reviewer), $task->project_id, $task->id, 'COMPLETED', $remark);
}

/*
|--------------------------------------------------------------------------
| Choosing the mode, and the mode lock
|--------------------------------------------------------------------------
*/

it('stores INDIVIDUAL only for a crew of two or more, and GROUP when nobody chose', function () {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $access = tsubAs(tsubReviewer());
    $project = tsubProject();

    $pair = app(CreateTask::class)->handle($access, $project->id, new TaskDraft('Pair', '', 'NORMAL', '', [$ana->id, $ben->id], 'INDIVIDUAL'));
    $solo = app(CreateTask::class)->handle($access, $project->id, new TaskDraft('Solo', '', 'NORMAL', '', [$ana->id], 'INDIVIDUAL'));
    $unchosen = app(CreateTask::class)->handle($access, $project->id, new TaskDraft('Unchosen', '', 'NORMAL', '', [$ana->id, $ben->id]));

    expect(ProjectTask::query()->whereKey($pair?->id)->value('submission_mode'))->toBe('INDIVIDUAL');
    expect(ProjectTask::query()->whereKey($solo?->id)->value('submission_mode'))->toBe('GROUP');
    expect(ProjectTask::query()->whereKey($unchosen?->id)->value('submission_mode'))->toBe('GROUP');
    expect(AuditLog::query()->where('entity_id', $pair?->id)->value('details'))->toContain('(individual responses)');
    expect(AuditLog::query()->where('entity_id', $solo?->id)->value('details'))->not->toContain('individual');
});

it('refuses an unknown submission mode on create and writes nothing', function () {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $project = tsubProject();

    expect(fn () => app(CreateTask::class)->handle(tsubAs(tsubReviewer()), $project->id, new TaskDraft('Odd', '', 'NORMAL', '', [$ana->id, $ben->id], 'SOLO')))
        ->toThrow(RuntimeException::class, 'submission_mode: is not a supported value');

    expect(ProjectTask::query()->where('project_id', $project->id)->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
});

it('switches the mode while nothing is handed in, and audits the switch as a changed field', function () {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben], mode: 'GROUP');

    expect(tsubEdit($reviewer, $task, [$ana, $ben], 'INDIVIDUAL'))->toBe($task->code);

    expect($task->refresh()->submission_mode)->toBe('INDIVIDUAL');
    $audit = AuditLog::query()->where('entity_id', $task->id)->where('action', 'TASK_UPDATED')->sole();
    expect($audit->details)->toContain('(submission_mode)');
});

it('locks the mode once anything is handed in, and refuses the switch without a trace', function (string $handIn, string $from, string $to) {
    Storage::fake('local');
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben], mode: $from);
    match ($handIn) {
        'response' => tsubResponse($task, $ana, 'RETURNED'),
        'file' => tsubFile($task, $ana, $anaLogin, 'proof.pdf'),
        // Returned and restarted: submitted_at is cleared, the submitter kept.
        'returned' => $task->forceFill(['submitted_by' => $ana->id, 'remarks' => 'Done po.'])->save(),
        // A legacy hand-in: the note and nothing else.
        default => $task->forceFill(['remarks' => 'Done po.'])->save(),
    };

    expect(fn () => tsubEdit($reviewer, $task, [$ana, $ben], $to))
        ->toThrow(RuntimeException::class, 'The submission type is locked once anyone has handed in work or attached evidence.');

    expect($task->refresh()->submission_mode)->toBe($from);
    expect(AuditLog::query()->where('entity_id', $task->id)->count())->toBe(0);
})->with([
    'a member response' => ['response', 'INDIVIDUAL', 'GROUP'],
    'a SUBMISSION file' => ['file', 'GROUP', 'INDIVIDUAL'],
    'a returned group hand-in' => ['returned', 'GROUP', 'INDIVIDUAL'],
    'a legacy remarks-only hand-in' => ['legacy', 'GROUP', 'INDIVIDUAL'],
]);

it('never touches the mode when none is posted', function () {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $locked = tsubTask([$ana, $ben]);
    tsubResponse($locked, $ana, 'SUBMITTED');

    // A priority-only edit on a mode-locked task goes through untouched.
    expect(tsubEdit($reviewer, $locked, [$ana, $ben], null, 'HIGH'))->toBe($locked->code);
    expect($locked->refresh()->priority)->toBe('HIGH')
        ->and($locked->submission_mode)->toBe('INDIVIDUAL');

    // Another reviewer switched the mode first; this edit, which never
    // touched it, leaves their switch in place.
    $open = tsubTask([$ana, $ben], mode: 'GROUP');
    tsubEdit(tsubReviewer(), $open, [$ana, $ben], 'INDIVIDUAL');
    tsubEdit($reviewer, $open, [$ana, $ben], null, 'URGENT');
    expect($open->refresh()->submission_mode)->toBe('INDIVIDUAL')
        ->and($open->priority)->toBe('URGENT');
});

it('keeps the stored mode when the crew drops below two, and never refuses the assignee edit over it', function (?string $posted) {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubResponse($task, $ana, 'SUBMITTED');

    expect(tsubEdit($reviewer, $task, [$ana], $posted))->toBe($task->code);

    expect($task->refresh()->submission_mode)->toBe('INDIVIDUAL');
    expect($task->assigneeIds())->toBe([$ana->id]);
    expect(tsubRow($task, $ana)?->status)->toBe('SUBMITTED');
})->with(['no mode posted' => null, 'GROUP posted' => 'GROUP']);

it('counts the responses a removal discards in the audit sentence, never their content', function () {
    Storage::fake('local');
    [$ana] = tsubMember('Ana');
    [$ben, $benLogin] = tsubMember('Ben');
    [$cara] = tsubMember('Cara');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben, $cara]);
    tsubResponse($task, $ben, 'RETURNED');
    $evidence = tsubFile($task, $ben, $benLogin, 'ben.pdf');

    tsubEdit($reviewer, $task, [$ana, $cara]);

    expect(tsubRow($task, $ben))->toBeNull();
    // The evidence stays with the task.
    expect(ProjectTaskFile::query()->whereKey($evidence->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($evidence->path);
    $details = AuditLog::query()->where('entity_id', $task->id)->where('action', 'TASK_UPDATED')->sole()->details;
    expect($details)->toContain('(assignees) (1 response discarded)')
        ->not->toContain('Fixture note');
});

it('refuses a group hand-in on a task switched to individual after it was read, without a trace', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $task = tsubTask([$ana, $ben], tsubReviewer(), mode: 'GROUP');
    // The switch lands between SubmitTask's read of the task and its guarded
    // UPDATE: it is written just before that UPDATE runs. The MariaDB race in
    // TaskSubmissionConcurrencyTest takes the same turn on real connections.
    $switch = new class
    {
        public bool $done = false;
    };
    DB::connection()->beforeExecuting(static function (string $sql, array $bindings, Connection $connection) use ($switch, $task): void {
        if (! $switch->done && str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'project_tasks')) {
            $switch->done = true;
            $connection->table('project_tasks')->where('id', $task->id)->update(['submission_mode' => 'INDIVIDUAL']);
        }
    });

    expect(fn () => tsubSubmit($anaLogin, $task, 'Done po.'))
        ->toThrow(RuntimeException::class, 'That task changed before this action completed.');

    expect($switch->done)->toBeTrue();
    $task->refresh();
    expect($task->submission_mode)->toBe('INDIVIDUAL')
        ->and($task->status)->toBe('IN_PROGRESS')
        ->and($task->submitted_by)->toBeNull()
        ->and($task->remarks)->toBeNull();
    expect(ProjectTaskSubmission::query()->count())->toBe(0);
    expect(TaskComment::query()->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A member hands in their own response
|--------------------------------------------------------------------------
*/

it('stores an individual hand-in as the member\'s own row and tells only the assigner, without the note', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $assigner = tsubReviewer();
    $task = tsubTask([$ana, $ben], $assigner, status: 'PENDING');
    expect(app(StartTask::class)->handle(tsubAs($anaLogin), $task->id))->toBeTrue();

    expect(tsubSubmit($anaLogin, $task, 'Surveyed the north wing.'))->toBeTrue();

    $row = tsubRow($task, $ana);
    expect($row?->status)->toBe('SUBMITTED')
        ->and($row?->revision)->toBe(1)
        ->and($row?->remarks)->toBe('Surveyed the north wing.')
        ->and($row?->submitted_at)->not->toBeNull();
    // The task itself does not move, and keeps no group response.
    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->remarks)->toBeNull()
        ->and($task->submitted_by)->toBeNull()
        ->and($task->submitted_at)->toBeNull();
    // Nothing reaches the shared thread.
    expect(TaskComment::query()->where('task_id', $task->id)->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'TASK_RESPONSE_SUBMITTED')->sole();
    expect($audit->entity)->toBe('project_task_submissions')
        ->and($audit->entity_id)->toBe($row?->id)
        ->and($audit->details)->not->toContain('north wing');
    $notice = Notification::query()->sole();
    expect((int) $notice->user_id)->toBe((int) $assigner->getKey())
        ->and($notice->kind)->toBe('TASK_RESPONSE_SUBMITTED')
        ->and($notice->body)->toBe($task->title)
        ->and($notice->href)->toBe('/projects/'.$task->project_id);
});

it('refuses a hand-in that is not the member\'s to make, leaving no row', function (string $status, ?string $existing) {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $task = tsubTask([$ana, $ben], tsubReviewer(), status: $status);
    if ($existing !== null) {
        tsubResponse($task, $ana, $existing);
    }
    $before = ProjectTaskSubmission::query()->get()->toArray();

    expect(fn () => tsubSubmit($anaLogin, $task, 'Again.'))
        ->toThrow(RuntimeException::class, TaskSubmissionPolicy::transitionError());

    expect(ProjectTaskSubmission::query()->get()->toArray())->toBe($before);
    expect(AuditLog::query()->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
})->with([
    'the task is still pending' => ['PENDING', null],
    'already submitted' => ['IN_PROGRESS', 'SUBMITTED'],
    'already approved' => ['IN_PROGRESS', 'APPROVED'],
]);

it('refuses a second hand-in straight after the first', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $task = tsubTask([$ana, $ben]);
    tsubSubmit($anaLogin, $task, 'First.');

    expect(fn () => tsubSubmit($anaLogin, $task, 'Second.'))
        ->toThrow(RuntimeException::class, TaskSubmissionPolicy::transitionError());

    expect(tsubRow($task, $ana)?->revision)->toBe(1)
        ->and(tsubRow($task, $ana)?->remarks)->toBe('First.');
    expect(AuditLog::query()->where('action', 'TASK_RESPONSE_SUBMITTED')->count())->toBe(1);
});

it('lets a returned member resubmit, bumping the revision and keeping the last review', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubSubmit($anaLogin, $task, 'First.');
    tsubDecide($reviewer, $task, $ana, 'RETURNED', 'Add the photos.');
    expect(tsubRow($task, $ana)?->revision)->toBe(2);

    tsubSubmit($anaLogin, $task, 'With photos.');

    $row = tsubRow($task, $ana);
    expect($row?->status)->toBe('SUBMITTED')
        ->and($row?->revision)->toBe(3)
        ->and($row?->remarks)->toBe('With photos.')
        ->and($row?->review_remarks)->toBe('Add the photos.')
        ->and($row?->reviewed_by)->toBe((int) $reviewer->getKey());
    expect(ProjectTaskSubmission::query()->where('task_id', $task->id)->count())->toBe(1);
});

it('refuses a hand-in from anyone not on the crew', function (string $who) {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    [$cara, $caraLogin] = tsubMember('Cara');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben, $cara]);
    $login = match ($who) {
        'unlinked' => User::factory()->create(['role_id' => tsubRole(['TASKS'], false)->getKey()]),
        'non-member' => tsubMember('Dan')[1],
        default => $caraLogin,
    };
    if ($who === 'just unassigned') {
        tsubEdit($reviewer, $task, [$ana, $ben]);
    }
    AuditLog::query()->delete();

    expect(tsubSubmit($login, $task, 'Mine.'))->toBeFalse();

    expect(ProjectTaskSubmission::query()->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
})->with(['unlinked', 'non-member', 'just unassigned']);

/*
|--------------------------------------------------------------------------
| A reviewer decides one response at a time
|--------------------------------------------------------------------------
*/

it('approves one member\'s response with the reviewer from the session, telling only that member on every account', function () {
    [$ana, $anaLogin] = tsubMember('Ana', accounts: 2);
    [$ben, $benLogin] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben], $reviewer);
    tsubSubmit($anaLogin, $task, 'Done.');
    tsubSubmit($benLogin, $task, 'Done too.');
    Notification::query()->delete();

    tsubDecide($reviewer, $task, $ana, 'APPROVED');

    $row = tsubRow($task, $ana);
    expect($row?->status)->toBe('APPROVED')
        ->and($row?->reviewed_by)->toBe((int) $reviewer->getKey())
        ->and($row?->reviewed_at)->not->toBeNull()
        ->and($row?->review_remarks)->toBeNull()
        ->and($row?->revision)->toBe(2);
    expect(tsubRow($task, $ben)?->status)->toBe('SUBMITTED');
    expect($task->refresh()->status)->toBe('IN_PROGRESS');
    expect(AuditLog::query()->where('action', 'TASK_RESPONSE_APPROVED')->where('entity_id', $row?->id)->count())->toBe(1);
    expect(TaskComment::query()->count())->toBe(0);
    $anaAccounts = User::query()->where('employee_id', $ana->id)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    expect(Notification::query()->orderBy('user_id')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all())->toBe($anaAccounts);
    expect(Notification::query()->pluck('kind')->unique()->all())->toBe(['TASK_RESPONSE_APPROVED']);
});

it('returns a response only with a note, and hands that note to the member', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubSubmit($anaLogin, $task, 'Done.');
    Notification::query()->delete();

    expect(fn () => tsubDecide($reviewer, $task, $ana, 'RETURNED', '   '))
        ->toThrow(RuntimeException::class, 'Say what needs reworking before returning this response.');
    expect(tsubRow($task, $ana)?->status)->toBe('SUBMITTED');
    expect(Notification::query()->count())->toBe(0);

    tsubDecide($reviewer, $task, $ana, 'RETURNED', 'Add the photos.');

    expect(tsubRow($task, $ana)?->status)->toBe('RETURNED')
        ->and(tsubRow($task, $ana)?->review_remarks)->toBe('Add the photos.');
    expect(AuditLog::query()->where('action', 'TASK_RESPONSE_RETURNED')->count())->toBe(1);
    $notice = Notification::query()->sole();
    expect((int) $notice->user_id)->toBe((int) $anaLogin->getKey())
        ->and($notice->kind)->toBe('TASK_RESPONSE_RETURNED')
        ->and($notice->body)->toBe('Add the photos.');
});

it('takes an approval back while the task is in progress, but not once it is completed', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben, $benLogin] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubSubmit($anaLogin, $task, 'Done.');
    tsubDecide($reviewer, $task, $ana, 'APPROVED');

    tsubDecide($reviewer, $task, $ana, 'RETURNED', 'Missed a room.');
    expect(tsubRow($task, $ana)?->status)->toBe('RETURNED');

    tsubSubmit($anaLogin, $task, 'Every room.');
    tsubSubmit($benLogin, $task, 'Done.');
    tsubDecide($reviewer, $task, $ana, 'APPROVED');
    tsubDecide($reviewer, $task, $ben, 'APPROVED');
    tsubComplete($reviewer, $task);
    $before = tsubRow($task, $ben)?->toArray();

    expect(fn () => tsubDecide($reviewer, $task, $ben, 'RETURNED', 'Too late.'))
        ->toThrow(RuntimeException::class, TaskSubmissionPolicy::transitionError());
    expect(tsubRow($task, $ben)?->toArray())->toBe($before);
});

it('refuses a decision on a revision the reviewer never saw', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubSubmit($anaLogin, $task, 'First.');
    $seen = tsubRow($task, $ana);
    tsubDecide($reviewer, $task, $ana, 'RETURNED', 'Redo.');
    tsubSubmit($anaLogin, $task, 'Second.');
    AuditLog::query()->delete();

    expect(fn () => app(ReviewTaskResponse::class)->handle(tsubAs($reviewer), $task->project_id, $task->id, (string) $seen?->id, 'APPROVED', '', (int) $seen?->revision))
        ->toThrow(RuntimeException::class, 'That response changed since you opened it');

    expect(tsubRow($task, $ana)?->status)->toBe('SUBMITTED')
        ->and(tsubRow($task, $ana)?->revision)->toBe(3);
    expect(AuditLog::query()->count())->toBe(0);
});

it('refuses a decision on a response replaced by a remove, re-add and resubmit', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubSubmit($anaLogin, $task, 'First.');
    $seen = tsubRow($task, $ana);

    tsubEdit($reviewer, $task, [$ben]);
    tsubEdit($reviewer, $task, [$ana, $ben]);
    tsubSubmit($anaLogin, $task, 'Back again.');
    $fresh = tsubRow($task, $ana);
    // Same revision as the one on screen — only the row id tells them apart.
    expect($fresh?->id)->not->toBe($seen?->id)
        ->and($fresh?->revision)->toBe($seen?->revision);

    expect(fn () => app(ReviewTaskResponse::class)->handle(tsubAs($reviewer), $task->project_id, $task->id, (string) $seen?->id, 'APPROVED', '', (int) $seen?->revision))
        ->toThrow(RuntimeException::class, 'That response changed since you opened it');

    expect(tsubRow($task, $ana)?->status)->toBe('SUBMITTED');
    expect(AuditLog::query()->where('action', 'TASK_RESPONSE_APPROVED')->count())->toBe(0);
});

it('refuses a response id that belongs to another task', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    $other = tsubTask([$ana, $ben]);
    tsubSubmit($anaLogin, $other, 'Elsewhere.');
    $row = tsubRow($other, $ana);

    expect(fn () => app(ReviewTaskResponse::class)->handle(tsubAs($reviewer), $task->project_id, $task->id, (string) $row?->id, 'APPROVED', '', (int) $row?->revision))
        ->toThrow(RuntimeException::class, 'That response changed since you opened it');

    expect(tsubRow($other, $ana)?->status)->toBe('SUBMITTED');
});

it('refuses a response decision from an actor without all-data PROJECTS', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $task = tsubTask([$ana, $ben]);
    tsubSubmit($anaLogin, $task, 'Done.');
    $row = tsubRow($task, $ana);
    AuditLog::query()->delete();
    Notification::query()->delete();
    $viewer = User::factory()->create(['role_id' => tsubRole(['PROJECTS'], false)->getKey(), 'employee_id' => tsubEmployee('Vic')->getKey()]);

    expect(fn () => app(ReviewTaskResponse::class)->handle(tsubAs($viewer), $task->project_id, $task->id, (string) $row?->id, 'APPROVED', '', (int) $row?->revision))
        ->toThrow(RuntimeException::class, 'You do not have access to that.');

    expect(tsubRow($task, $ana)?->status)->toBe('SUBMITTED');
    expect(AuditLog::query()->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The completion gate
|--------------------------------------------------------------------------
*/

it('refuses to complete while any current assignee is not approved', function (string $benState) {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubResponse($task, $ana, 'APPROVED');
    if ($benState !== 'NONE') {
        tsubResponse($task, $ben, $benState);
    }

    expect(fn () => tsubComplete($reviewer, $task))
        ->toThrow(RuntimeException::class, "Every assignee's response has to be approved before the task can be completed.");

    expect($task->refresh()->status)->toBe('IN_PROGRESS')
        ->and($task->completed_at)->toBeNull();
    expect(AuditLog::query()->where('entity_id', $task->id)->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
})->with(['NONE', 'SUBMITTED', 'RETURNED']);

it('refuses to complete an individual task with nobody on it', function () {
    $task = tsubTask([]);

    expect(fn () => tsubComplete(tsubReviewer(), $task))
        ->toThrow(RuntimeException::class, 'Assign someone before completing this task.');

    expect($task->refresh()->status)->toBe('IN_PROGRESS');
});

it('lets a newcomer block completion until they are approved or removed', function () {
    Storage::fake('local');
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    [$cara, $caraLogin] = tsubMember('Cara');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubResponse($task, $ana, 'APPROVED');
    tsubResponse($task, $ben, 'APPROVED');

    tsubEdit($reviewer, $task, [$ana, $ben, $cara]);
    expect(fn () => tsubComplete($reviewer, $task))
        ->toThrow(RuntimeException::class, "Every assignee's response has to be approved before the task can be completed.");

    // Cara attaches and hands in; she is then taken off the task.
    $caraFile = tsubFile($task, $cara, $caraLogin, 'cara.pdf');
    tsubSubmit($caraLogin, $task, 'Mine.');
    tsubEdit($reviewer, $task, [$ana, $ben]);

    // Her row goes with her place on the crew; her evidence stays.
    expect(tsubRow($task, $cara))->toBeNull();
    expect(ProjectTaskFile::query()->whereKey($caraFile->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($caraFile->path);

    tsubComplete($reviewer, $task);
    expect($task->refresh()->status)->toBe('COMPLETED');
});

it('completes once every response is approved, tells the crew, and lets the project close', function () {
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben, $benLogin] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben], $reviewer);
    tsubSubmit($anaLogin, $task, 'Done.');
    tsubSubmit($benLogin, $task, 'Done too.');
    tsubDecide($reviewer, $task, $ana, 'APPROVED');
    tsubDecide($reviewer, $task, $ben, 'APPROVED');
    expect(fn () => app(ChangeProjectStatus::class)->handle(tsubAs($reviewer), $task->project_id, 'COMPLETED'))
        ->toThrow(RuntimeException::class, 'Complete or cancel every task before completing the project.');
    Notification::query()->delete();

    tsubComplete($reviewer, $task);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBe((int) $reviewer->getKey());
    expect(AuditLog::query()->where('entity_id', $task->id)->where('action', 'TASK_COMPLETED')->count())->toBe(1);
    $crew = [(int) $anaLogin->getKey(), (int) $benLogin->getKey()];
    sort($crew);
    expect(Notification::query()->where('kind', 'TASK_COMPLETED')->orderBy('user_id')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all())->toBe($crew);

    expect(app(ChangeProjectStatus::class)->handle(tsubAs($reviewer), $task->project_id, 'COMPLETED'))->toBeTrue();
    expect(Project::query()->whereKey($task->project_id)->value('status'))->toBe('COMPLETED');
});

it('rolls an individual completion back when its comment cannot be stored', function () {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubResponse($task, $ana, 'APPROVED');
    tsubResponse($task, $ben, 'APPROVED');
    TaskComment::creating(static function (): void {
        throw new RuntimeException('completion comment failed');
    });

    expect(fn () => tsubComplete($reviewer, $task, 'Great work.'))
        ->toThrow(RuntimeException::class, 'completion comment failed');

    expect($task->refresh()->status)->toBe('IN_PROGRESS')
        ->and($task->completed_at)->toBeNull();
    expect(TaskComment::query()->count())->toBe(0);
    expect(AuditLog::query()->where('entity_id', $task->id)->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
});

it('keeps partial approvals when an individual task is cancelled', function () {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubResponse($task, $ana, 'APPROVED');
    tsubResponse($task, $ben, 'SUBMITTED');

    app(ReviewTask::class)->handle(tsubAs($reviewer), $task->project_id, $task->id, 'CANCELLED', '');

    expect($task->refresh()->status)->toBe('CANCELLED');
    expect(ProjectTaskSubmission::query()->where('task_id', $task->id)->orderBy('status')->pluck('status')->all())->toBe(['APPROVED', 'SUBMITTED']);
});

/*
|--------------------------------------------------------------------------
| Evidence follows the member's own response
|--------------------------------------------------------------------------
*/

it('opens or locks a member\'s evidence by their own response state', function (?string $state, bool $editable) {
    Storage::fake('local');
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $task = tsubTask([$ana, $ben]);
    if ($state !== null) {
        tsubResponse($task, $ana, $state);
    }
    $existing = tsubFile($task, $ana, $anaLogin, 'first.pdf');
    $message = 'Your evidence is locked while your response is under review or approved.';

    $attach = fn () => app(AttachSubmission::class)->handle(tsubAs($anaLogin), $task->id, UploadedFile::fake()->create('second.pdf', 4, 'application/pdf'));
    $delete = fn () => app(DeleteSubmission::class)->handle(tsubAs($anaLogin), $existing->id);

    if ($editable) {
        expect($attach())->toBe('second.pdf');
        expect(ProjectTaskFile::query()->where('file_name', 'second.pdf')->value('employee_id'))->toBe($ana->id);
        expect($delete())->toBe('first.pdf');
        expect(ProjectTaskFile::query()->whereKey($existing->id)->exists())->toBeFalse();
        Storage::disk('local')->assertMissing($existing->path);

        return;
    }
    expect($attach)->toThrow(RuntimeException::class, $message);
    expect($delete)->toThrow(RuntimeException::class, $message);
    expect(ProjectTaskFile::query()->where('task_id', $task->id)->pluck('file_name')->all())->toBe(['first.pdf']);
    expect(Storage::disk('local')->allFiles('task-files'))->toBe([$existing->path]);
    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'not submitted' => [null, true],
    'returned' => ['RETURNED', true],
    'submitted' => ['SUBMITTED', false],
    'approved' => ['APPROVED', false],
]);

it('refuses a removed member the delete of the evidence they left behind', function () {
    Storage::fake('local');
    [$ana] = tsubMember('Ana');
    [$ben, $benLogin] = tsubMember('Ben');
    [$cara] = tsubMember('Cara');
    $task = tsubTask([$ana, $ben, $cara]);
    $evidence = tsubFile($task, $ben, $benLogin, 'ben.pdf');
    tsubEdit(tsubReviewer(), $task, [$ana, $cara]);
    AuditLog::query()->delete();

    expect(fn () => app(DeleteSubmission::class)->handle(tsubAs($benLogin), $evidence->id))
        ->toThrow(HttpException::class);

    expect(ProjectTaskFile::query()->whereKey($evidence->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($evidence->path);
    expect(AuditLog::query()->count())->toBe(0);
});

it('refuses a member the delete of a peer\'s evidence on an individual task', function () {
    Storage::fake('local');
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $task = tsubTask([$ana, $ben]);
    // Uploaded through Ana's login, but it is Ben's evidence.
    $evidence = tsubFile($task, $ben, $anaLogin, 'ben.pdf');

    expect(fn () => app(DeleteSubmission::class)->handle(tsubAs($anaLogin), $evidence->id))
        ->toThrow(HttpException::class);

    expect(ProjectTaskFile::query()->whereKey($evidence->id)->exists())->toBeTrue();
});

it('re-checks group evidence under the task lock, refusing an upload a hand-in or a switch overtook', function (array $change, string $message) {
    Storage::fake('local');
    [$ana, $anaLogin] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $task = tsubTask([$ana, $ben], mode: 'GROUP');
    // The change lands after the plain pre-check, while the upload holds the
    // budget lock (L5) and before its persist callback takes L1. It is
    // written on the upload's own connection, so the refusal's rollback
    // takes it back too; the MariaDB races cover the committed version.
    $raced = new class
    {
        public bool $done = false;
    };
    DB::connection()->beforeExecuting(static function (string $sql, array $bindings, Connection $connection) use ($raced, $task, $change): void {
        if (! $raced->done && preg_match('/from [`"]locks[`"]/', $sql) === 1) {
            $raced->done = true;
            $connection->table('project_tasks')->where('id', $task->id)->update($change);
        }
    });

    expect(fn () => app(AttachSubmission::class)->handle(tsubAs($anaLogin), $task->id, UploadedFile::fake()->create('late.pdf', 4, 'application/pdf')))
        ->toThrow(TaskEvidenceLocked::class, $message);

    expect($raced->done)->toBeTrue();
    expect(ProjectTaskFile::query()->where('task_id', $task->id)->count())->toBe(0);
    // AttachmentStore discarded the bytes it had written.
    expect(Storage::disk('local')->allFiles('task-files'))->toBe([]);
    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'a group hand-in' => [['status' => 'SUBMITTED'], 'Task evidence is locked in this status.'],
    'a switch to individual' => [['submission_mode' => 'INDIVIDUAL'], 'That task changed before this action completed.'],
]);

it('deletes the members\' responses with their project', function () {
    [$ana] = tsubMember('Ana');
    [$ben] = tsubMember('Ben');
    $reviewer = tsubReviewer();
    $task = tsubTask([$ana, $ben]);
    tsubResponse($task, $ana, 'APPROVED');
    tsubResponse($task, $ben, 'RETURNED');

    expect(app(DeleteProject::class)->handle(tsubAs($reviewer), $task->project_id))->not->toBeNull();

    expect(ProjectTask::query()->whereKey($task->id)->exists())->toBeFalse();
    expect(ProjectTaskSubmission::query()->count())->toBe(0);
});
