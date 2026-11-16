<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Livewire\Projects\Show;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\ChangeProjectStatus;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A status set BY HAND (DELIBERATE DIVERGENCE) moves only along
 * the map keyed by the CURRENT status — ManualTaskStatus for a task (per
 * submission mode), ManualProjectStatus for a project — and only for whoever
 * runs the project. Each allowed move writes exactly the side facts its new
 * status needs (completed_at, submitted_at, reviewed_by from the session,
 * review_remarks, a MANAGEMENT thread line for a note), is audited as
 * TASK_<TO> "(set by hand, from …)" and tells the crew but never the actor.
 * Every refusal — a move off the map, a return without a note, a hand-in
 * that forbids PENDING, an INDIVIDUAL completion before every response is
 * approved, a reopen inside a closed project, a stale expected status, a
 * self-review by someone on the crew — leaves the row, audit_logs,
 * task_comments and notifications exactly as they were. A project completes
 * only with every task closed, may be reopened, and an edit-dialog save that
 * did not touch the status or start date never reverts a change made
 * underneath it.
 */

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function mstatRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Mstat '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * A fresh account in a fresh role, signed in, with the scoped Access
 * forgotten so the next resolve reads this actor.
 *
 * @param  list<string>  $modules
 */
function mstatActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create(['role_id' => mstatRole($modules, $allData)->getKey(), 'employee_id' => $employeeId]);
    mstatSignIn($user);

    return $user;
}

function mstatSignIn(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
}

/** A Projects manager: all-data PROJECTS, optionally linked to an employee. */
function mstatManager(?string $employeeId = null): User
{
    return mstatActor(['PROJECTS'], true, $employeeId);
}

/** A Task Leader candidate: PROJECTS without all_data, not signed in. */
function mstatLeaderAccount(?string $employeeId = null): User
{
    return User::factory()->create(['role_id' => mstatRole(['PROJECTS', 'TASKS'], false)->getKey(), 'employee_id' => $employeeId]);
}

/** An active employee with one active TASKS-only login, so a notice to them is countable. */
function mstatEmployee(string $first): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(5)),
        'first_name' => $first,
        'last_name' => 'Santos',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();
    User::factory()->create(['role_id' => mstatRole(['TASKS'], false)->getKey(), 'employee_id' => $employee->getKey()]);

    return $employee;
}

/** The one account mstatEmployee() made for that employee. */
function mstatAccountOf(EmployeeProfile $employee): User
{
    return User::query()->where('employee_id', $employee->id)->orderBy('id')->firstOrFail();
}

/** @param array<string, mixed> $attributes column overrides */
function mstatProject(array $attributes = []): Project
{
    $project = new Project;
    $project->forceFill(array_merge([
        'code' => 'PRJ-'.Str::upper(Str::random(5)),
        'name' => 'Project '.Str::random(8),
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ], $attributes))->save();

    return $project;
}

/**
 * A GROUP task shared by `$crew`. A SUBMITTED or COMPLETED one carries its
 * first member's hand-in, as the real flow leaves it.
 *
 * @param  list<EmployeeProfile>  $crew
 * @param  array<string, mixed>  $attributes  column overrides
 */
function mstatTask(Project $project, string $status, array $crew, array $attributes = []): ProjectTask
{
    $handedIn = in_array($status, ['SUBMITTED', 'COMPLETED'], true) && $crew !== []
        ? ['submitted_at' => CarbonImmutable::now()->utc(), 'submitted_by' => $crew[0]->id, 'remarks' => 'Done po.']
        : [];

    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => 'TSK-'.Str::upper(Str::random(5)),
        'project_id' => $project->getKey(),
        'title' => 'Shared report',
        'status' => $status,
        'priority' => 'NORMAL',
        'assigned_at' => CarbonImmutable::now()->utc(),
    ], $handedIn, $attributes))->save();
    $task->syncAssignees(array_map(static fn (EmployeeProfile $e): string => $e->id, $crew), null);

    return $task;
}

/**
 * An INDIVIDUAL task; each `[$member, $state]` gets a response row at that
 * state, and `$crew` lists every assignee (with or without a row).
 *
 * @param  list<EmployeeProfile>  $crew
 * @param  list<array{0: EmployeeProfile, 1: string}>  $responses
 */
function mstatIndividualTask(Project $project, string $status, array $crew, array $responses = []): ProjectTask
{
    $task = mstatTask($project, $status, $crew, ['submission_mode' => 'INDIVIDUAL', 'title' => 'Own report']);
    foreach ($responses as [$member, $state]) {
        $row = new ProjectTaskSubmission;
        $row->fill(['task_id' => $task->id, 'employee_id' => $member->id, 'status' => $state, 'remarks' => 'Mine po.', 'submitted_at' => CarbonImmutable::now()->utc(), 'revision' => 1]);
        $row->save();
    }

    return $task;
}

/** ChangeTaskStatus as `$user`; `$from` defaults to the status the row holds now. */
function mstatMove(User $user, ProjectTask $task, string $to, string $note = '', ?string $from = null): void
{
    app(ChangeTaskStatus::class)->handle(Access::forActor($user), $task->project_id, $task->id, $to, $note, $from ?? (string) $task->fresh()?->status);
}

/**
 * Everything a refused move must leave alone: the task row verbatim, and the
 * audit trail, the thread and the bell.
 *
 * @return array{row: array<string, mixed>, audit: int, comments: int, notices: int}
 */
function mstatSnapshot(ProjectTask $task): array
{
    return [
        'row' => $task->fresh()?->getAttributes() ?? [],
        'audit' => AuditLog::query()->count(),
        'comments' => TaskComment::query()->count(),
        'notices' => Notification::query()->count(),
    ];
}

/** @param array{row: array<string, mixed>, audit: int, comments: int, notices: int} $before */
function mstatExpectUntouched(ProjectTask $task, array $before): void
{
    expect(mstatSnapshot($task))->toBe($before);
}

/** The refusal of a direct command call, and its lack of effect. */
function mstatExpectRefused(User $user, ProjectTask $task, string $to, string $message, string $note = '', ?string $from = null): void
{
    $before = mstatSnapshot($task);

    expect(fn () => mstatMove($user, $task, $to, $note, $from))->toThrow(RuntimeException::class, $message);

    mstatExpectUntouched($task, $before);
}

/** The audit sentence a hand-set move leaves. */
function mstatAuditDetails(ProjectTask $task, string $from, string $to): string
{
    return sprintf('Task %s "%s" → %s (set by hand, from %s)', $task->code, $task->title, mb_strtolower($to), mb_strtolower($from));
}

/*
|--------------------------------------------------------------------------
| GROUP tasks — each allowed move and what it writes
|--------------------------------------------------------------------------
*/

it('starts a pending group task by hand without stamping a reviewer, and tells the whole crew', function () {
    $ana = mstatEmployee('Ana');
    $ben = mstatEmployee('Ben');
    $earlier = mstatLeaderAccount();
    $task = mstatTask(mstatProject(), 'PENDING', [$ana, $ben], ['reviewed_by' => $earlier->getKey()]);
    $manager = mstatManager();

    mstatMove($manager, $task, 'IN_PROGRESS');

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->submitted_at)->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBe($earlier->getKey())
        ->and($task->review_remarks)->toBeNull();

    $audit = AuditLog::query()->where('action', 'TASK_IN_PROGRESS')->where('entity_id', $task->id)->sole();
    expect($audit->details)->toBe(mstatAuditDetails($task, 'PENDING', 'IN_PROGRESS'))
        ->and($audit->actor_id)->toBe($manager->getKey());
    // No note, no thread line.
    expect(TaskComment::query()->count())->toBe(0);

    $notices = Notification::query()->where('kind', 'TASK_IN_PROGRESS')->get();
    expect($notices->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([mstatAccountOf($ana)->getKey(), mstatAccountOf($ben)->getKey()])->sort()->values()->all());
    expect($notices->first()?->title)->toBe('Task '.$task->code.' set to in progress')
        ->and($notices->first()?->body)->toBe('Shared report')
        ->and($notices->first()?->href)->toBe('/my-tasks?task='.$task->id);
    expect(Notification::query()->where('user_id', $manager->getKey())->count())->toBe(0);
});

it('puts an in-progress group task back to pending while nothing has been handed in', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'IN_PROGRESS', [$ana]);
    $manager = mstatManager();

    mstatMove($manager, $task, 'PENDING');

    expect($task->refresh()->status)->toBe('PENDING');
    expect(AuditLog::query()->where('action', 'TASK_PENDING')->where('entity_id', $task->id)->sole()->details)
        ->toBe(mstatAuditDetails($task, 'IN_PROGRESS', 'PENDING'));
    expect(Notification::query()->where('kind', 'TASK_PENDING')->where('user_id', mstatAccountOf($ana)->getKey())->count())->toBe(1);
});

it('refuses to put a group task back to pending once work was handed in on it', function () {
    $ana = mstatEmployee('Ana');
    // Returned once and restarted: StartTask cleared submitted_at, but the
    // hand-in's submitter and note stay on the row.
    $task = mstatTask(mstatProject(), 'IN_PROGRESS', [$ana], ['submitted_by' => $ana->id, 'remarks' => 'First draft po.']);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, 'PENDING', 'Work has already been handed in, so this task cannot go back to pending.');
});

it('force-completes a pending group task, stamping the actor, the note, the thread and the crew', function () {
    $ana = mstatEmployee('Ana');
    $ben = mstatEmployee('Ben');
    $task = mstatTask(mstatProject(), 'PENDING', [$ana, $ben]);
    $manager = mstatManager();

    mstatMove($manager, $task, 'COMPLETED', '  Done offline at the site.  ');

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBe($manager->getKey())
        ->and($task->review_remarks)->toBe('Done offline at the site.');

    $comment = TaskComment::query()->where('task_id', $task->id)->sole();
    expect($comment->side)->toBe('MANAGEMENT')
        ->and($comment->event)->toBe('COMPLETED')
        ->and($comment->body)->toBe('Done offline at the site.')
        ->and($comment->author_id)->toBe($manager->getKey());
    expect(AuditLog::query()->where('action', 'TASK_COMPLETED')->where('entity_id', $task->id)->sole()->details)
        ->toBe(mstatAuditDetails($task, 'PENDING', 'COMPLETED'));
    expect(AuditLog::query()->where('action', 'TASK_COMMENTED')->where('entity_id', $comment->id)->count())->toBe(1);

    $notices = Notification::query()->where('kind', 'TASK_COMPLETED')->get();
    expect($notices)->toHaveCount(2);
    expect($notices->first()?->body)->toBe('Done offline at the site.');
});

it('cancels a submitted group task by hand, keeping the hand-in on record', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'SUBMITTED', [$ana]);
    $submittedAt = $task->submitted_at;
    $manager = mstatManager();

    mstatMove($manager, $task, 'CANCELLED');

    $task->refresh();
    expect($task->status)->toBe('CANCELLED')
        ->and($task->completed_at)->toBeNull()
        ->and($task->submitted_at?->format('Y-m-d H:i:s'))->toBe($submittedAt?->format('Y-m-d H:i:s'))
        ->and($task->submitted_by)->toBe($ana->id)
        ->and($task->getAttribute('reviewed_by'))->toBe($manager->getKey())
        ->and($task->review_remarks)->toBeNull();
    expect(AuditLog::query()->where('action', 'TASK_CANCELLED')->where('entity_id', $task->id)->count())->toBe(1);
    expect(Notification::query()->where('kind', 'TASK_CANCELLED')->count())->toBe(1);
});

it('refuses to return a submitted group task without a note, leaving no trace', function (string $note) {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'SUBMITTED', [$ana]);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, 'RETURNED', 'Say what needs reworking before returning the task.', $note);
})->with(['empty' => '', 'blank' => "  \n "]);

it('returns a submitted group task with a note as a MANAGEMENT thread line and tells the crew', function () {
    $ana = mstatEmployee('Ana');
    $ben = mstatEmployee('Ben');
    $task = mstatTask(mstatProject(), 'SUBMITTED', [$ana, $ben]);
    $manager = mstatManager();

    mstatMove($manager, $task, 'RETURNED', 'Redo the totals.');

    $task->refresh();
    expect($task->status)->toBe('RETURNED')
        ->and($task->submitted_at)->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBe($manager->getKey())
        ->and($task->review_remarks)->toBe('Redo the totals.');

    $comment = TaskComment::query()->where('task_id', $task->id)->sole();
    expect($comment->side)->toBe('MANAGEMENT')
        ->and($comment->event)->toBe('RETURNED')
        ->and($comment->body)->toBe('Redo the totals.');
    expect(AuditLog::query()->where('action', 'TASK_RETURNED')->where('entity_id', $task->id)->sole()->details)
        ->toBe(mstatAuditDetails($task, 'SUBMITTED', 'RETURNED'));
    expect(AuditLog::query()->where('action', 'TASK_COMMENTED')->count())->toBe(1);

    $notices = Notification::query()->where('kind', 'TASK_RETURNED')->get();
    expect($notices)->toHaveCount(2);
    expect($notices->first()?->title)->toBe('Task '.$task->code.' set to returned')
        ->and($notices->first()?->body)->toBe('Redo the totals.');
});

it('reopens a completed group task, clearing its completion and the decision note', function () {
    $ana = mstatEmployee('Ana');
    $reviewer = mstatLeaderAccount();
    $task = mstatTask(mstatProject(), 'COMPLETED', [$ana], [
        'completed_at' => CarbonImmutable::now()->utc(),
        'reviewed_by' => $reviewer->getKey(),
        'review_remarks' => 'Looks good.',
    ]);
    $manager = mstatManager();

    mstatMove($manager, $task, 'IN_PROGRESS');

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->completed_at)->toBeNull()
        ->and($task->review_remarks)->toBeNull()
        ->and($task->submitted_at)->toBeNull()
        // The record of what was handed in stays.
        ->and($task->submitted_by)->toBe($ana->id);
    expect(AuditLog::query()->where('action', 'TASK_IN_PROGRESS')->where('entity_id', $task->id)->sole()->details)
        ->toBe(mstatAuditDetails($task, 'COMPLETED', 'IN_PROGRESS'));
});

it('restarts a returned group task by hand and keeps the rework note', function () {
    $ana = mstatEmployee('Ana');
    $reviewer = mstatLeaderAccount();
    $task = mstatTask(mstatProject(), 'RETURNED', [$ana], [
        'submitted_by' => $ana->id,
        'remarks' => 'First draft po.',
        'reviewed_by' => $reviewer->getKey(),
        'review_remarks' => 'Fix the totals.',
    ]);
    $manager = mstatManager();

    mstatMove($manager, $task, 'IN_PROGRESS');

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->review_remarks)->toBe('Fix the totals.')
        ->and($task->getAttribute('reviewed_by'))->toBe($reviewer->getKey())
        ->and($task->submitted_at)->toBeNull()
        ->and($task->completed_at)->toBeNull();
});

it('never sets a task to SUBMITTED by hand, from any status', function (string $from) {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), $from, [$ana]);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, 'SUBMITTED', TaskPolicy::transitionError());
})->with(['PENDING', 'IN_PROGRESS', 'SUBMITTED', 'RETURNED', 'COMPLETED', 'CANCELLED']);

it('refuses an unknown task status', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'PENDING', [$ana]);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, 'ARCHIVED', 'That task status is not supported.');
});

it('refuses to reopen a task inside a completed or cancelled project', function (string $projectStatus) {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(['status' => $projectStatus]), 'CANCELLED', [$ana]);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, 'IN_PROGRESS', 'Reopen the project before reopening one of its tasks.');
})->with(['COMPLETED', 'CANCELLED']);

it('reopens a cancelled task inside an active project', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(['status' => 'ACTIVE']), 'CANCELLED', [$ana], ['review_remarks' => 'Called off.']);
    $manager = mstatManager();

    mstatMove($manager, $task, 'IN_PROGRESS');

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->review_remarks)->toBeNull()
        ->and($task->completed_at)->toBeNull();
    expect(AuditLog::query()->where('action', 'TASK_IN_PROGRESS')->where('entity_id', $task->id)->count())->toBe(1);
});

it('refuses a move from a status the task has already left', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'IN_PROGRESS', [$ana]);
    $manager = mstatManager();

    // The dialog showed PENDING; somebody started the task meanwhile.
    mstatExpectRefused($manager, $task, 'COMPLETED', 'That task changed before this action completed.', '', 'PENDING');
});

/*
|--------------------------------------------------------------------------
| INDIVIDUAL tasks
|--------------------------------------------------------------------------
*/

it('never moves an individual task to SUBMITTED or RETURNED by hand', function (string $from, string $to) {
    $ana = mstatEmployee('Ana');
    $ben = mstatEmployee('Ben');
    $task = mstatIndividualTask(mstatProject(), $from, [$ana, $ben], [[$ana, 'SUBMITTED']]);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, $to, TaskPolicy::transitionError(), 'Rework it.');
})->with([
    'in progress → submitted' => ['IN_PROGRESS', 'SUBMITTED'],
    'in progress → returned' => ['IN_PROGRESS', 'RETURNED'],
    'completed → returned' => ['COMPLETED', 'RETURNED'],
    'a legacy submitted row → cancelled' => ['SUBMITTED', 'CANCELLED'],
]);

it('completes an individual task by hand only once every current assignee\'s response is approved', function () {
    $ana = mstatEmployee('Ana');
    $ben = mstatEmployee('Ben');
    $task = mstatIndividualTask(mstatProject(), 'IN_PROGRESS', [$ana, $ben], [[$ana, 'APPROVED'], [$ben, 'SUBMITTED']]);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, 'COMPLETED', "Every assignee's response has to be approved before the task can be completed.");

    ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $ben->id)->update(['status' => 'APPROVED']);

    mstatMove($manager, $task, 'COMPLETED');

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBe($manager->getKey());
    expect(AuditLog::query()->where('action', 'TASK_COMPLETED')->where('entity_id', $task->id)->sole()->details)
        ->toBe(mstatAuditDetails($task, 'IN_PROGRESS', 'COMPLETED'));
    expect(Notification::query()->where('kind', 'TASK_COMPLETED')->count())->toBe(2);
});

it('refuses to complete an individual task while an assignee has no response at all', function () {
    $ana = mstatEmployee('Ana');
    $ben = mstatEmployee('Ben');
    $task = mstatIndividualTask(mstatProject(), 'IN_PROGRESS', [$ana, $ben], [[$ana, 'APPROVED']]);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, 'COMPLETED', "Every assignee's response has to be approved before the task can be completed.");
});

it('refuses to put an individual task back to pending once any response exists', function () {
    $ana = mstatEmployee('Ana');
    $ben = mstatEmployee('Ben');
    $task = mstatIndividualTask(mstatProject(), 'IN_PROGRESS', [$ana, $ben], [[$ben, 'RETURNED']]);
    $manager = mstatManager();

    mstatExpectRefused($manager, $task, 'PENDING', 'Work has already been handed in, so this task cannot go back to pending.');

    // Without a single response row, the same move goes through.
    $fresh = mstatIndividualTask(mstatProject(), 'IN_PROGRESS', [$ana, $ben]);
    mstatMove($manager, $fresh, 'PENDING');
    expect($fresh->refresh()->status)->toBe('PENDING');
});

/*
|--------------------------------------------------------------------------
| Who may: a manager on the crew, the Task Leader, anyone else
|--------------------------------------------------------------------------
*/

it('lets a manager on the crew pause the task, telling the rest of the crew but not themselves', function () {
    $mia = mstatEmployee('Mia');
    $ben = mstatEmployee('Ben');
    $task = mstatTask(mstatProject(), 'IN_PROGRESS', [$mia, $ben]);
    $manager = mstatManager($mia->id);

    mstatMove($manager, $task, 'PENDING');

    expect($task->refresh()->status)->toBe('PENDING');
    $notices = Notification::query()->where('kind', 'TASK_PENDING')->get();
    expect($notices->pluck('user_id')->all())->toBe([mstatAccountOf($ben)->getKey()]);
    expect(Notification::query()->where('user_id', $manager->getKey())->count())->toBe(0);
});

it('refuses a manager on the crew every review move', function (string $from, string $to, string $note) {
    $mia = mstatEmployee('Mia');
    $ben = mstatEmployee('Ben');
    $task = mstatTask(mstatProject(), $from, [$ben, $mia]);
    $manager = mstatManager($mia->id);

    mstatExpectRefused($manager, $task, $to, WorkRules::selfReviewMessage(), $note);
})->with([
    'complete in progress work' => ['IN_PROGRESS', 'COMPLETED', ''],
    'complete a hand-in' => ['SUBMITTED', 'COMPLETED', ''],
    'return a hand-in' => ['SUBMITTED', 'RETURNED', 'Redo it.'],
    'cancel a hand-in' => ['SUBMITTED', 'CANCELLED', ''],
    'reopen an accepted task' => ['COMPLETED', 'IN_PROGRESS', ''],
    'return an accepted task' => ['COMPLETED', 'RETURNED', 'Redo it.'],
]);

it('lets the project\'s Task Leader set a task status by hand, but not on a task they are assigned to', function () {
    $ana = mstatEmployee('Ana');
    $lia = mstatEmployee('Lia');
    $leader = mstatLeaderAccount($lia->id);
    $project = mstatProject(['manager_id' => $leader->getKey()]);
    $theirs = mstatTask($project, 'IN_PROGRESS', [$ana]);
    $shared = mstatTask($project, 'IN_PROGRESS', [$ana, $lia]);
    mstatSignIn($leader);

    mstatMove($leader, $theirs, 'COMPLETED', 'Checked on site.');

    $theirs->refresh();
    expect($theirs->status)->toBe('COMPLETED')
        ->and($theirs->getAttribute('reviewed_by'))->toBe($leader->getKey());
    expect(TaskComment::query()->where('task_id', $theirs->id)->sole()->side)->toBe('MANAGEMENT');

    // On their own task the leader decides nothing, not even a pause.
    mstatExpectRefused($leader, $shared, 'PENDING', WorkRules::selfReviewMessage());
});

it('refuses a hand-set status to a PROJECTS holder who does not run the project', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'IN_PROGRESS', [$ana]);
    // Ana herself, through a PROJECTS login without all_data: she sees her
    // task, but neither manages projects nor leads this one.
    $viewer = mstatActor(['PROJECTS'], false, $ana->id);

    mstatExpectRefused($viewer, $task, 'PENDING', WorkRules::forbiddenMessage());
});

/*
|--------------------------------------------------------------------------
| The review dialog's "Set status by hand"
|--------------------------------------------------------------------------
*/

it('offers a manager only the moves the decision buttons do not already make', function (string $status, array $moves) {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), $status, [$ana]);
    mstatManager();

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->assertSeeHtml('aria-label="Change task status"')
        ->assertViewHas('openTaskManualMoves', $moves);
})->with([
    // Accept and Return are the decision buttons; Cancel is not offered
    // there for a hand-in, so it is the one extra move.
    'submitted' => ['SUBMITTED', ['CANCELLED']],
    // Cancel is a decision button; PENDING is refused once handed in.
    'pending' => ['PENDING', ['IN_PROGRESS', 'COMPLETED']],
    'in progress' => ['IN_PROGRESS', ['PENDING', 'COMPLETED']],
    'completed after a hand-in' => ['COMPLETED', ['IN_PROGRESS', 'RETURNED', 'CANCELLED']],
]);

it('sets a status from the review dialog and toasts it', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'PENDING', [$ana]);
    mstatManager();

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->call('chooseTaskStatus', 'IN_PROGRESS')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Task '.$task->code.' set to in progress.')
        ->assertSet('manualStatus', '')
        ->assertSet('remarks', '');

    expect($task->refresh()->status)->toBe('IN_PROGRESS');
    expect(AuditLog::query()->where('action', 'TASK_IN_PROGRESS')->where('entity_id', $task->id)->count())->toBe(1);
});

it('returns an accepted task from the dialog with the status note, required to return', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'COMPLETED', [$ana], ['completed_at' => CarbonImmutable::now()->utc()]);
    mstatManager();

    $page = Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id);
    $page->call('chooseTaskStatus', 'RETURNED')
        ->assertSet('taskStatusConfirm', true)->assertSee('Reason for return');
    $page
        ->call('setTaskStatus', 'COMPLETED')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Say what needs reworking before returning the task.');
    expect($task->refresh()->status)->toBe('COMPLETED');
    expect(AuditLog::query()->count())->toBe(0);

    $page->set('remarks', 'The totals are off.')
        ->call('setTaskStatus', 'COMPLETED')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Task '.$task->code.' set to returned.');

    $task->refresh();
    expect($task->status)->toBe('RETURNED')
        ->and($task->completed_at)->toBeNull()
        ->and($task->review_remarks)->toBe('The totals are off.');
    expect(TaskComment::query()->where('task_id', $task->id)->sole()->event)->toBe('RETURNED');
});

it('shows no status picker to a viewer who does not run the project, and refuses the call anyway', function () {
    $ana = mstatEmployee('Ana');
    $task = mstatTask(mstatProject(), 'IN_PROGRESS', [$ana]);
    mstatActor(['PROJECTS'], false, $ana->id);
    $before = mstatSnapshot($task);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->assertSet('openTaskId', $task->id)
        ->assertDontSeeHtml('aria-label="Change task status"')
        ->call('chooseTaskStatus', 'PENDING')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === WorkRules::forbiddenMessage())
        ->set('manualStatus', 'PENDING')
        ->call('setTaskStatus', 'IN_PROGRESS')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === WorkRules::forbiddenMessage())
        ->assertViewHas('openTaskManualMoves', []);

    mstatExpectUntouched($task, $before);
});

/*
|--------------------------------------------------------------------------
| Project status by hand
|--------------------------------------------------------------------------
*/

it('reopens a completed project', function (string $next, bool $withLeader) {
    $leader = mstatLeaderAccount();
    $project = mstatProject(['status' => 'COMPLETED', 'manager_id' => $withLeader ? $leader->getKey() : null]);
    mstatTask($project, 'COMPLETED', [mstatEmployee('Ana')]);
    $manager = mstatManager();

    expect(app(ChangeProjectStatus::class)->handle(Access::forActor($manager), $project->id, $next, 'COMPLETED'))->toBeTrue();

    expect($project->refresh()->status)->toBe($next);
    $audit = AuditLog::query()->where('action', 'PROJECT_STATUS_CHANGED')->where('entity_id', $project->id)->sole();
    expect($audit->details)->toBe(sprintf('Project %s — %s moved from Completed to %s', $project->code, $project->name, ProjectPolicy::statusLabel($next)));
})->with([
    'to planning' => ['PLANNING', false],
    'to on hold' => ['ON_HOLD', false],
    'to active, with a task leader' => ['ACTIVE', true],
]);

it('refuses to complete a project while any task is open, and completes it once none is', function () {
    $leader = mstatLeaderAccount();
    $project = mstatProject(['status' => 'ACTIVE', 'manager_id' => $leader->getKey()]);
    $ana = mstatEmployee('Ana');
    mstatTask($project, 'COMPLETED', [$ana]);
    $open = mstatTask($project, 'RETURNED', [$ana]);
    $manager = mstatManager();

    expect(fn () => app(ChangeProjectStatus::class)->handle(Access::forActor($manager), $project->id, 'COMPLETED', 'ACTIVE'))
        ->toThrow(RuntimeException::class, 'Complete or cancel every task before completing the project.');
    expect($project->refresh()->status)->toBe('ACTIVE');
    expect(AuditLog::query()->count())->toBe(0);

    $open->forceFill(['status' => 'CANCELLED'])->save();

    expect(app(ChangeProjectStatus::class)->handle(Access::forActor($manager), $project->id, 'COMPLETED', 'ACTIVE'))->toBeTrue();
    expect($project->refresh()->status)->toBe('COMPLETED');
});

it('reactivates a cancelled project only with a task leader', function () {
    $bare = mstatProject(['status' => 'CANCELLED']);
    $led = mstatProject(['status' => 'CANCELLED', 'manager_id' => mstatLeaderAccount()->getKey()]);
    $manager = mstatManager();

    expect(fn () => app(ChangeProjectStatus::class)->handle(Access::forActor($manager), $bare->id, 'ACTIVE', 'CANCELLED'))
        ->toThrow(RuntimeException::class, 'Assign a project lead before activating this project.');
    expect($bare->refresh()->status)->toBe('CANCELLED');
    expect(AuditLog::query()->count())->toBe(0);

    expect(app(ChangeProjectStatus::class)->handle(Access::forActor($manager), $led->id, 'ACTIVE', 'CANCELLED'))->toBeTrue();
    expect($led->refresh()->status)->toBe('ACTIVE');
    expect(AuditLog::query()->where('action', 'PROJECT_STATUS_CHANGED')->where('entity_id', $led->id)->count())->toBe(1);
});

it('refuses a project move from a status the project has already left', function () {
    $project = mstatProject(['status' => 'ON_HOLD', 'manager_id' => mstatLeaderAccount()->getKey()]);
    $manager = mstatManager();

    // The select was drawn while the project was PLANNING.
    expect(fn () => app(ChangeProjectStatus::class)->handle(Access::forActor($manager), $project->id, 'ACTIVE', 'PLANNING'))
        ->toThrow(RuntimeException::class, 'That project changed before this action completed.');
    expect($project->refresh()->status)->toBe('ON_HOLD');
    expect(AuditLog::query()->count())->toBe(0);
});

it('treats setting the status a project already has as a no-op', function () {
    $project = mstatProject(['status' => 'ON_HOLD']);
    $manager = mstatManager();

    expect(app(ChangeProjectStatus::class)->handle(Access::forActor($manager), $project->id, 'ON_HOLD', 'ON_HOLD'))->toBeFalse();
    expect($project->refresh()->status)->toBe('ON_HOLD');
    expect(AuditLog::query()->count())->toBe(0);
});

it('lets the project\'s Task Leader reopen it, and nobody else without all-data', function () {
    $ana = mstatEmployee('Ana');
    $leader = mstatLeaderAccount();
    $project = mstatProject(['status' => 'COMPLETED', 'manager_id' => $leader->getKey()]);
    mstatTask($project, 'COMPLETED', [$ana]);

    // Ana sees the project through her task, but does not lead it.
    $viewer = mstatActor(['PROJECTS'], false, $ana->id);
    expect(fn () => app(ChangeProjectStatus::class)->handle(Access::forActor($viewer), $project->id, 'ON_HOLD', 'COMPLETED'))
        ->toThrow(RuntimeException::class, WorkRules::forbiddenMessage());
    expect($project->refresh()->status)->toBe('COMPLETED');
    expect(AuditLog::query()->count())->toBe(0);

    mstatSignIn($leader);
    expect(app(ChangeProjectStatus::class)->handle(Access::forActor($leader), $project->id, 'ON_HOLD', 'COMPLETED'))->toBeTrue();
    expect($project->refresh()->status)->toBe('ON_HOLD');
    expect(AuditLog::query()->where('action', 'PROJECT_STATUS_CHANGED')->where('actor_id', $leader->getKey())->count())->toBe(1);
});

it('disables Completed in the project details status editor while a task is open', function () {
    $project = mstatProject(['status' => 'ACTIVE', 'manager_id' => mstatLeaderAccount()->getKey()]);
    $open = mstatTask($project, 'IN_PROGRESS', [mstatEmployee('Ana')]);
    mstatManager();
    $disabledCompleted = '/<option\b[^>]*\bdisabled\b[^>]*value="COMPLETED"/';

    expect(Livewire::test(Show::class, ['project' => $project->id])
        ->call('openProjectDetails')->call('editProjectStatus')->html())->toMatch($disabledCompleted);

    $open->forceFill(['status' => 'COMPLETED'])->save();

    expect(Livewire::test(Show::class, ['project' => $project->id])
        ->call('openProjectDetails')->call('editProjectStatus')->html())->not->toMatch($disabledCompleted);
});

it('completes a project through its details status editor once every task is closed', function () {
    $project = mstatProject(['status' => 'ACTIVE', 'manager_id' => mstatLeaderAccount()->getKey()]);
    $open = mstatTask($project, 'IN_PROGRESS', [mstatEmployee('Ana')]);
    mstatManager();

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openProjectDetails')->call('editProjectStatus')
        ->set('editStatus', 'COMPLETED')->call('saveProjectStatus')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Complete or cancel every task before completing the project.');
    expect($project->refresh()->status)->toBe('ACTIVE');

    $open->forceFill(['status' => 'CANCELLED'])->save();

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openProjectDetails')->call('editProjectStatus')
        ->set('editStatus', 'COMPLETED')->call('saveProjectStatus')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Project marked completed.');
    expect($project->refresh()->status)->toBe('COMPLETED');
});

/*
|--------------------------------------------------------------------------
| The edit dialog never reverts a status or start date it did not touch
|--------------------------------------------------------------------------
*/

it('keeps a status changed underneath a manager\'s edit dialog that did not touch it', function () {
    $leader = mstatLeaderAccount();
    $project = mstatProject(['status' => 'PLANNING', 'manager_id' => $leader->getKey(), 'name' => 'Old name']);
    mstatManager();

    $page = Livewire::test(Show::class, ['project' => $project->id])
        ->call('openEdit')
        ->assertSet('editStatus', 'PLANNING');

    // The leader puts the project on hold while the dialog is open.
    $project->forceFill(['status' => 'ON_HOLD'])->save();

    $page->set('editName', 'New name')
        ->call('saveProject')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Project '.$project->code.' updated.');

    $project->refresh();
    expect($project->name)->toBe('New name')
        ->and($project->status)->toBe('ON_HOLD');
    expect(AuditLog::query()->where('action', 'PROJECT_STATUS_CHANGED')->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'PROJECT_UPDATED')->where('entity_id', $project->id)->sole()->details)
        ->toBe($project->code.' — New name updated (name)');
});

it('keeps a start date changed underneath a manager\'s edit dialog that did not touch it', function () {
    $leader = mstatLeaderAccount();
    $project = mstatProject(['status' => 'PLANNING', 'manager_id' => $leader->getKey(), 'start_date' => '2026-01-01', 'name' => 'Old name']);
    mstatManager();

    $page = Livewire::test(Show::class, ['project' => $project->id])
        ->call('openEdit')
        ->assertSet('editStartDate', '2026-01-01');

    // The leader re-plans the start while the dialog is open.
    $project->forceFill(['start_date' => '2026-02-01'])->save();

    $page->set('editName', 'New name')->call('saveProject');

    $project->refresh();
    expect($project->name)->toBe('New name')
        ->and($project->start_date->format('Y-m-d'))->toBe('2026-02-01');
});

it('still saves a status the manager did change in the edit dialog, along the hand-set rule', function () {
    $leader = mstatLeaderAccount();
    $project = mstatProject(['status' => 'COMPLETED', 'manager_id' => $leader->getKey()]);
    mstatManager();

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openEdit')
        ->set('editStatus', 'ACTIVE')
        ->call('saveProject');

    expect($project->refresh()->status)->toBe('ACTIVE');
    expect(AuditLog::query()->where('action', 'PROJECT_STATUS_CHANGED')->where('entity_id', $project->id)->count())->toBe(1);
});
