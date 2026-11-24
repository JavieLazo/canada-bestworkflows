<?php

declare(strict_types=1);

use App\Domain\Projects\TaskPolicy;
use App\Domain\Reminders\ReminderSweep;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Notifications\Inbox;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Reminders on an INDIVIDUAL task follow each member's own response.
 *
 * A member whose response is submitted or approved has nothing left to do,
 * so the overdue and due-soon reminders skip them and still reach a member
 * who has not submitted or was returned. The assigner is chased once the
 * OLDEST response awaiting review has waited ReminderSweep::REVIEW_STALE_DAYS,
 * under the key TASK_RESPONSES_PENDING:{taskId}:{its true UTC instant, to the
 * millisecond} — never stamp()'s eight-hour, whole-second quirk, so a
 * response handed in the same second as the oldest is still chased once the
 * oldest is reviewed. A later hand-in joins that reminder, and the key moves
 * on only once the oldest is reviewed. A cancelled task chases nobody, an
 * assigner on the crew (who may not review it) is not chased, and a stored
 * chase is hidden once the task leaves the reader's scope. Individual
 * responses never change the GROUP chase or its frozen TASK_REVIEW_PENDING
 * key.
 *
 * The pure rules are exercised through ReminderSweep::build() with array
 * rows; everything else runs `reminders:sweep` end to end, so the facts
 * ReminderData precomputes are proven to be the ones build() reads.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tremTaskRow(array $overrides = []): array
{
    return array_merge([
        'id' => 'tsk-9',
        'code' => 'TSK-009',
        'title' => 'Own work',
        'project_id' => 'prj-9',
        'assignee_ids' => ['emp-a', 'emp-b', 'emp-c'],
        'submitted_by' => null,
        'assigned_by' => '7',
        'status' => 'IN_PROGRESS',
        'due_date' => null,
        'submitted_at' => null,
    ], $overrides);
}

/** @return list<array<string, mixed>> */
function tremEmployeeRows(): array
{
    $rows = [];
    foreach (['emp-a' => ['Ana', 'Garcia'], 'emp-b' => ['Ben', 'Cruz']] as $id => [$first, $last]) {
        $rows[] = [
            'id' => $id,
            'first_name' => $first,
            'last_name' => $last,
            'suffix' => null,
            'is_active' => true,
            'employment_status' => 'Regular',
            'date_hired' => '2020-01-01',
            'date_regular' => '2020-07-01',
        ];
    }

    return $rows;
}

/** @param  list<string>  $modules */
function tremUser(array $modules, ?string $employeeId = null, bool $allData = false): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Trem '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId, 'is_active' => true]);
}

function tremEmployee(string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Clerk',
        'employment_status' => 'Regular',
        'date_hired' => '2020-01-01',
        'date_regular' => '2020-07-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

function tremProject(): Project
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
 * A task and its members' own response rows, written directly. Each crew
 * entry is [member, row status or null for "not submitted", submitted_at].
 *
 * @param  list<array{0: EmployeeProfile, 1: string|null, 2?: CarbonImmutable}>  $crew
 * @param  array<string, mixed>  $attributes
 */
function tremTask(Project $project, string $code, string $mode, string $status, array $crew, array $attributes = []): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Task '.$code,
        'status' => $status,
        'priority' => 'NORMAL',
        'submission_mode' => $mode,
    ], $attributes))->save();
    $task->syncAssignees(array_map(static fn (array $member): string => $member[0]->id, $crew), null);
    foreach ($crew as $member) {
        if ($member[1] !== null) {
            tremResponse($task, $member[0], $member[1], $member[2] ?? CarbonImmutable::now()->utc());
        }
    }

    return $task;
}

function tremResponse(ProjectTask $task, EmployeeProfile $member, string $status, CarbonImmutable $submittedAt): ProjectTaskSubmission
{
    $row = new ProjectTaskSubmission;
    $row->forceFill([
        'task_id' => $task->id,
        'employee_id' => $member->id,
        'status' => $status,
        'remarks' => 'Response from '.$member->first_name,
        'submitted_at' => $submittedAt,
        'revision' => 1,
    ])->save();

    return $row;
}

/**
 * The trigger of a TASK_RESPONSES_PENDING key, spelled out: the true UTC
 * instant to the millisecond. Unlike the frozen TASK_REVIEW_PENDING stamp,
 * it is neither eight hours early nor cut to whole seconds.
 */
function tremTrigger(CarbonImmutable $submittedAt): string
{
    return $submittedAt->utc()->format('Y-m-d\TH:i:s.v\Z');
}

/** @return list<string> */
function tremKeys(User $user, string $kind): array
{
    return array_values(Notification::query()->where('user_id', $user->getKey())->where('kind', $kind)
        ->orderBy('created_at')->orderBy('id')->pluck('dedupe_key')
        ->map(static fn (mixed $key): string => (string) $key)->all());
}

/*
|--------------------------------------------------------------------------
| build() — pure rules
|--------------------------------------------------------------------------
*/

it('skips only the member whose own response is done', function () {
    $task = tremTaskRow(['due_date' => '2026-09-01']);

    $done = ReminderSweep::build('2026-09-10', 'u1', ['TASKS'], 'emp-a', [$task + ['member_done' => true]]);
    $owes = ReminderSweep::build('2026-09-10', 'u2', ['TASKS'], 'emp-b', [$task + ['member_done' => false]]);
    $absent = ReminderSweep::build('2026-09-10', 'u2', ['TASKS'], 'emp-b', [$task]);

    expect($done)->toBe([])
        ->and($owes)->toHaveCount(1)
        ->and($owes[0]['dedupeKey'])->toBe('TASK_OVERDUE:tsk-9:2026-09-01')
        ->and($absent)->toBe($owes);
});

it('chases the oldest waiting response, once it has waited long enough', function () {
    $ana = CarbonImmutable::parse('2026-09-06 02:00:15.250', 'UTC');
    $ben = CarbonImmutable::parse('2026-09-07 02:00:00', 'UTC');
    $both = tremTaskRow(['pending_responses' => [
        ['employee_id' => 'emp-b', 'submitted_at' => $ben],
        ['employee_id' => 'emp-a', 'submitted_at' => $ana],
    ]]);
    $benOnly = tremTaskRow(['pending_responses' => [['employee_id' => 'emp-b', 'submitted_at' => $ben]]]);

    $out = ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], null, [$both], [], tremEmployeeRows());
    $single = ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], null, [$benOnly], [], tremEmployeeRows());

    expect($out)->toBe([[
        'dedupeKey' => 'TASK_RESPONSES_PENDING:tsk-9:2026-09-06T02:00:15.250Z',
        'kind' => 'TASK_RESPONSES_PENDING',
        'title' => 'Task TSK-009 has responses waiting on your review',
        'body' => '2 responses are waiting — the oldest from Ana Garcia, 4 days ago.',
        'href' => '/projects/prj-9',
    ]])
        ->and($single)->toHaveCount(1)
        ->and($single[0]['dedupeKey'])->toBe('TASK_RESPONSES_PENDING:tsk-9:2026-09-07T02:00:00.000Z')
        ->and($single[0]['body'])->toBe('Ben Cruz submitted theirs 3 days ago.');

    // REVIEW_STALE_DAYS (2) Manila days is the threshold: one day is too soon.
    $waited = static fn (string $at): array => ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], null,
        [tremTaskRow(['pending_responses' => [['employee_id' => 'emp-a', 'submitted_at' => CarbonImmutable::parse($at, 'UTC')]]])]);
    expect(ReminderSweep::REVIEW_STALE_DAYS)->toBe(2)
        ->and($waited('2026-09-09 02:00:00'))->toBe([])
        ->and($waited('2026-09-08 02:00:00'))->toHaveCount(1);
});

it('never chases an assigner who is on the crew about individual responses', function () {
    $task = tremTaskRow(['pending_responses' => [
        ['employee_id' => 'emp-a', 'submitted_at' => CarbonImmutable::parse('2026-09-01 02:00:00', 'UTC')],
    ]]);

    expect(ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], 'emp-c', [$task]))->toBe([])
        ->and(ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], 'emp-z', [$task]))->toHaveCount(1);
});

it('leaves the group review chase byte for byte as it was', function () {
    $group = tremTaskRow([
        'assignee_ids' => ['emp-a', 'emp-b'],
        'submitted_by' => 'emp-b',
        'status' => 'SUBMITTED',
        'submitted_at' => CarbonImmutable::parse('2026-09-06 16:30:15.250', 'UTC'),
    ]);

    $out = ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], 'emp-a', [$group], [], tremEmployeeRows());

    // The individual-response facts, present and empty, change nothing — not
    // even for an assigner on the crew, whom the GROUP chase still reaches.
    expect($out)->toBe([[
        'dedupeKey' => 'TASK_REVIEW_PENDING:tsk-9:2026-09-06T08:30:15.000Z',
        'kind' => 'TASK_REVIEW_PENDING',
        'title' => 'Task TSK-009 is waiting on your review',
        'body' => 'Ben Cruz submitted it 3 days ago.',
        'href' => '/projects/prj-9',
    ]])
        ->and(ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], 'emp-a',
            [$group + ['pending_responses' => [], 'member_done' => false]], [], tremEmployeeRows()))->toBe($out);
});

/*
|--------------------------------------------------------------------------
| reminders:sweep — the facts ReminderData precomputes, end to end
|--------------------------------------------------------------------------
*/

it('stops reminding a member whose response is submitted or approved, and still reminds the rest', function () {
    $ana = tremEmployee('Ana', 'Garcia');
    $ben = tremEmployee('Ben', 'Cruz');
    $cid = tremEmployee('Cid', 'Reyes');
    $dee = tremEmployee('Dee', 'Santos');
    $users = [];
    foreach (['ana' => $ana, 'ben' => $ben, 'cid' => $cid, 'dee' => $dee] as $name => $member) {
        $users[$name] = tremUser(['TASKS'], $member->id);
    }
    $crew = [[$ana, 'SUBMITTED'], [$ben, 'APPROVED'], [$cid, null], [$dee, 'RETURNED']];
    $today = CarbonImmutable::now(LocalClock::zone())->startOfDay();
    $project = tremProject();
    tremTask($project, 'TSK-LATE', TaskPolicy::MODE_INDIVIDUAL, 'IN_PROGRESS', $crew, ['due_date' => $today->subDays(3)->format('Y-m-d')]);
    tremTask($project, 'TSK-SOON', TaskPolicy::MODE_INDIVIDUAL, 'IN_PROGRESS', $crew, ['due_date' => $today->addDay()->format('Y-m-d')]);

    expect(Artisan::call('reminders:sweep'))->toBe(0);

    $kinds = static fn (User $user): array => Notification::query()->where('user_id', $user->getKey())
        ->orderBy('kind')->pluck('kind')->all();
    expect($kinds($users['ana']))->toBe([])
        ->and($kinds($users['ben']))->toBe([])
        ->and($kinds($users['cid']))->toBe(['TASK_DUE_SOON', 'TASK_OVERDUE'])
        ->and($kinds($users['dee']))->toBe(['TASK_DUE_SOON', 'TASK_OVERDUE']);
});

it('chases the assigner under the oldest response\'s instant once it has waited two days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 03:00:00', 'UTC'));
    $reviewer = tremUser(['PROJECTS'], allData: true);
    $ana = tremEmployee('Ana', 'Garcia');
    $ben = tremEmployee('Ben', 'Cruz');
    $cid = tremEmployee('Cid', 'Reyes');
    $oldest = CarbonImmutable::parse('2026-09-09 02:00:15.250', 'UTC');
    $project = tremProject();
    $task = tremTask($project, 'TSK-IND', TaskPolicy::MODE_INDIVIDUAL, 'IN_PROGRESS', [
        [$ben, 'SUBMITTED', CarbonImmutable::parse('2026-09-09 05:00:00', 'UTC')],
        [$ana, 'SUBMITTED', $oldest],
        [$cid, null],
    ], ['assigned_by' => $reviewer->getKey()]);

    // One Manila day old: not yet.
    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(tremKeys($reviewer, 'TASK_RESPONSES_PENDING'))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-09-11 03:00:00', 'UTC'));
    expect(Artisan::call('reminders:sweep'))->toBe(0);

    $key = 'TASK_RESPONSES_PENDING:'.$task->id.':'.tremTrigger($oldest);
    expect($key)->toBe('TASK_RESPONSES_PENDING:'.$task->id.':2026-09-09T02:00:15.250Z')
        ->and(tremKeys($reviewer, 'TASK_RESPONSES_PENDING'))->toBe([$key]);

    $row = Notification::query()->where('user_id', $reviewer->getKey())->sole();
    expect($row->title)->toBe('Task TSK-IND has responses waiting on your review')
        ->and($row->body)->toBe('2 responses are waiting — the oldest from Ana Garcia, 2 days ago.')
        ->and($row->href)->toBe('/projects/'.$project->id);

    // Idempotent: the same waiting responses are not chased twice.
    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(Notification::query()->count())->toBe(1);
});

it('re-keys the chase once the oldest response is reviewed, and not for a later hand-in', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 03:00:00', 'UTC'));
    $reviewer = tremUser(['PROJECTS'], allData: true);
    $ana = tremEmployee('Ana', 'Garcia');
    $ben = tremEmployee('Ben', 'Cruz');
    $cid = tremEmployee('Cid', 'Reyes');
    $anaAt = CarbonImmutable::parse('2026-09-06 02:00:15.250', 'UTC');
    $benAt = CarbonImmutable::parse('2026-09-07 02:00:00', 'UTC');
    $task = tremTask(tremProject(), 'TSK-IND', TaskPolicy::MODE_INDIVIDUAL, 'IN_PROGRESS', [
        [$ana, 'SUBMITTED', $anaAt],
        [$ben, 'SUBMITTED', $benAt],
        [$cid, null],
    ], ['assigned_by' => $reviewer->getKey()]);
    $anaKey = 'TASK_RESPONSES_PENDING:'.$task->id.':'.tremTrigger($anaAt);
    $benKey = 'TASK_RESPONSES_PENDING:'.$task->id.':'.tremTrigger($benAt);

    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(tremKeys($reviewer, 'TASK_RESPONSES_PENDING'))->toBe([$anaKey]);

    // A later hand-in joins the reminder already sent.
    tremResponse($task, $cid, 'SUBMITTED', CarbonImmutable::now()->utc());
    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(Artisan::output())->toContain('wrote 0 reminders')
        ->and(tremKeys($reviewer, 'TASK_RESPONSES_PENDING'))->toBe([$anaKey]);

    // Ana's response is reviewed: the chase moves to Ben's, exactly once.
    ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $ana->id)->update(['status' => 'APPROVED']);
    $this->travel(1)->minutes();
    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(tremKeys($reviewer, 'TASK_RESPONSES_PENDING'))->toBe([$anaKey, $benKey])
        ->and(Notification::query()->where('dedupe_key', $benKey)->value('body'))
        ->toBe('2 responses are waiting — the oldest from Ben Cruz, 3 days ago.');

    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(Notification::query()->count())->toBe(2);
});

it('still chases a response handed in the same second as the oldest once the oldest is reviewed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 03:00:00', 'UTC'));
    $reviewer = tremUser(['PROJECTS'], allData: true);
    $ana = tremEmployee('Ana', 'Garcia');
    $ben = tremEmployee('Ben', 'Cruz');
    $anaAt = CarbonImmutable::parse('2026-09-06 09:00:05.120', 'UTC');
    $benAt = CarbonImmutable::parse('2026-09-06 09:00:05.870', 'UTC');
    $task = tremTask(tremProject(), 'TSK-SEC', TaskPolicy::MODE_INDIVIDUAL, 'IN_PROGRESS',
        [[$ana, 'SUBMITTED', $anaAt], [$ben, 'SUBMITTED', $benAt]], ['assigned_by' => $reviewer->getKey()]);
    $anaKey = 'TASK_RESPONSES_PENDING:'.$task->id.':2026-09-06T09:00:05.120Z';
    $benKey = 'TASK_RESPONSES_PENDING:'.$task->id.':2026-09-06T09:00:05.870Z';

    // The pure rule tells the two apart, whichever order the rows arrive in.
    $row = static fn (array $pending): array => tremTaskRow(['id' => $task->id, 'pending_responses' => $pending]);
    $a = ['employee_id' => $ana->id, 'submitted_at' => $anaAt];
    $b = ['employee_id' => $ben->id, 'submitted_at' => $benAt];
    expect(ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], null, [$row([$b, $a])])[0]['dedupeKey'])->toBe($anaKey)
        ->and(ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], null, [$row([$b])])[0]['dedupeKey'])->toBe($benKey);

    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(tremKeys($reviewer, 'TASK_RESPONSES_PENDING'))->toBe([$anaKey]);

    // Ana is reviewed: Ben's response, from the same second, gets its own chase.
    ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $ana->id)->update(['status' => 'APPROVED']);
    $this->travel(1)->minutes();
    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(tremKeys($reviewer, 'TASK_RESPONSES_PENDING'))->toBe([$anaKey, $benKey])
        ->and(Notification::query()->where('dedupe_key', $benKey)->value('body'))
        ->toBe('Ben Cruz submitted theirs 4 days ago.');
});

it('chases nobody about a cancelled task, or from an assigner on the crew', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 03:00:00', 'UTC'));
    $reviewer = tremUser(['PROJECTS'], allData: true);
    $lea = tremEmployee('Lea', 'Tan');
    $lead = tremUser(['PROJECTS'], $lea->id, allData: true);
    $ana = tremEmployee('Ana', 'Garcia');
    $stale = CarbonImmutable::parse('2026-09-05 02:00:00', 'UTC');
    $project = tremProject();

    tremTask($project, 'TSK-GONE', TaskPolicy::MODE_INDIVIDUAL, 'CANCELLED',
        [[$ana, 'SUBMITTED', $stale]], ['assigned_by' => $reviewer->getKey()]);
    tremTask($project, 'TSK-CREW', TaskPolicy::MODE_INDIVIDUAL, 'IN_PROGRESS',
        [[$lea, null], [$ana, 'SUBMITTED', $stale]], ['assigned_by' => $lead->getKey()]);

    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(Notification::query()->where('user_id', $reviewer->getKey())->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $lead->getKey())->count())->toBe(0);
});

it('keeps the group chase and its frozen key alongside the individual one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 03:00:00', 'UTC'));
    $ana = tremEmployee('Ana', 'Garcia');
    $ben = tremEmployee('Ben', 'Cruz');
    $reviewer = tremUser(['PROJECTS'], $ana->id, allData: true);
    $submittedAt = CarbonImmutable::parse('2026-09-06 16:30:15.250', 'UTC');
    $project = tremProject();

    // The assigner is on this GROUP crew and is still chased.
    $group = tremTask($project, 'TSK-GRP', TaskPolicy::MODE_GROUP, 'SUBMITTED', [[$ana, null], [$ben, null]], [
        'assigned_by' => $reviewer->getKey(),
        'submitted_by' => $ben->id,
        'submitted_at' => $submittedAt,
    ]);
    $individual = tremTask($project, 'TSK-IND', TaskPolicy::MODE_INDIVIDUAL, 'IN_PROGRESS',
        [[$ben, 'SUBMITTED', $submittedAt]], ['assigned_by' => $reviewer->getKey()]);

    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(tremKeys($reviewer, 'TASK_REVIEW_PENDING'))
        ->toBe(['TASK_REVIEW_PENDING:'.$group->id.':2026-09-06T08:30:15.000Z'])
        ->and(tremKeys($reviewer, 'TASK_RESPONSES_PENDING'))
        ->toBe(['TASK_RESPONSES_PENDING:'.$individual->id.':2026-09-06T16:30:15.250Z']);

    $groupRow = Notification::query()->where('kind', 'TASK_REVIEW_PENDING')->sole();
    expect($groupRow->title)->toBe('Task TSK-GRP is waiting on your review')
        ->and($groupRow->body)->toBe('Ben Cruz submitted it 3 days ago.')
        ->and($groupRow->href)->toBe('/projects/'.$project->id);
});

it('hides a stored response chase once the task leaves the reader\'s scope', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 03:00:00', 'UTC'));
    $reviewer = tremUser(['PROJECTS'], allData: true);
    $ana = tremEmployee('Ana', 'Garcia');
    tremTask(tremProject(), 'TSK-IND', TaskPolicy::MODE_INDIVIDUAL, 'IN_PROGRESS',
        [[$ana, 'SUBMITTED', CarbonImmutable::parse('2026-09-05 02:00:00', 'UTC')]], ['assigned_by' => $reviewer->getKey()]);
    expect(Artisan::call('reminders:sweep'))->toBe(0);

    $inbox = app(Inbox::class);
    $kinds = static fn (): array => $inbox->latest(Access::forActor($reviewer->fresh()), 50)->pluck('kind')->all();
    expect($kinds())->toBe(['TASK_RESPONSES_PENDING']);

    // The reviewer's role loses organisation-wide access: the task is no
    // longer theirs to review, so the stored row stays but is never shown.
    Role::query()->whereKey($reviewer->role_id)->update(['all_data' => false]);
    app()->forgetInstance(Access::class);

    expect($kinds())->toBe([])
        ->and(Notification::query()->where('user_id', $reviewer->getKey())->count())->toBe(1);
});
