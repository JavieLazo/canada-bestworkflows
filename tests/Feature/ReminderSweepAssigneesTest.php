<?php

declare(strict_types=1);

use App\Domain\Reminders\ReminderSweep;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * The reminder sweep under the multi-assignee task model.
 *
 * A task is SHARED by 0..N employees (project_task_assignees). The assignee
 * rules (TASK_OVERDUE / TASK_DUE_SOON) must therefore fire once PER ASSIGNEE —
 * the dedupe key is per task and the unique index is per account, so nothing
 * collides — and a task nobody holds must chase nobody. The reviewer's chase
 * (TASK_REVIEW_PENDING) names whoever pressed Submit (`submitted_by`), and
 * only falls back to the whole assignee list when that column is empty. Its
 * dedupe key is pinned to the exact stamp the sweep minted before submitted_at
 * travelled as an instant, so a submission chased once stays chased once.
 *
 * Half the file exercises ReminderSweep::build() as the pure function it is
 * (array rows in, reminder rows out); the other half runs `reminders:sweep`
 * end to end so the row shape the command builds from Eloquent is proven to
 * be the one build() reads.
 */
/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rswTaskRow(array $overrides = []): array
{
    return array_merge([
        'id' => 'tsk-1',
        'code' => 'TSK-001',
        'title' => 'Shared work',
        'project_id' => 'prj-1',
        'assignee_ids' => [],
        'submitted_by' => null,
        'assigned_by' => '7',
        'status' => 'PENDING',
        'due_date' => null,
        'submitted_at' => null,
    ], $overrides);
}

/** @return array<string, mixed> */
function rswEmployeeRow(string $id, string $first, string $last): array
{
    return [
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

/** @param  list<string>  $modules */
function rswRole(array $modules, bool $allData = false): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Sweep '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param  list<string>  $modules */
function rswUser(array $modules, ?string $employeeId = null, bool $allData = false): User
{
    return User::factory()->create([
        'role_id' => rswRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
        'is_active' => true,
    ]);
}

function rswEmployee(string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(5)),
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

function rswProject(): Project
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
 * @param  list<string>  $assigneeIds
 * @param  array<string, mixed>  $attributes
 */
function rswTask(Project $project, string $code, array $assigneeIds, array $attributes = []): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Task '.$code,
        'status' => 'PENDING',
        'priority' => 'NORMAL',
    ], $attributes))->save();

    $task->syncAssignees($assigneeIds, null);

    return $task;
}

function rswToday(): CarbonImmutable
{
    return CarbonImmutable::now(LocalClock::zone())->startOfDay();
}

/*
|--------------------------------------------------------------------------
| build() — pure rules
|--------------------------------------------------------------------------
*/

it('announces a due-soon task to every assignee, and to nobody else', function () {
    $task = rswTaskRow(['assignee_ids' => ['emp-a', 'emp-b'], 'due_date' => '2026-09-11']);

    $forA = ReminderSweep::build('2026-09-10', 'u1', ['TASKS'], 'emp-a', [$task]);
    $forB = ReminderSweep::build('2026-09-10', 'u2', ['TASKS'], 'emp-b', [$task]);
    $forC = ReminderSweep::build('2026-09-10', 'u3', ['TASKS'], 'emp-c', [$task]);

    expect($forA)->toHaveCount(1)
        ->and($forA[0]['kind'])->toBe('TASK_DUE_SOON')
        ->and($forA[0]['dedupeKey'])->toBe('TASK_DUE_SOON:tsk-1:2026-09-11')
        ->and($forA[0]['title'])->toBe('Task TSK-001 is due soon')
        ->and($forB)->toBe($forA)
        ->and($forC)->toBe([]);
});

it('announces an overdue task to every assignee under the same per-task key', function () {
    $task = rswTaskRow(['assignee_ids' => ['emp-a', 'emp-b'], 'due_date' => '2026-09-01']);

    $forA = ReminderSweep::build('2026-09-10', 'u1', ['TASKS'], 'emp-a', [$task]);
    $forB = ReminderSweep::build('2026-09-10', 'u2', ['TASKS'], 'emp-b', [$task]);

    expect($forA)->toHaveCount(1)
        ->and($forA[0]['kind'])->toBe('TASK_OVERDUE')
        ->and($forA[0]['dedupeKey'])->toBe('TASK_OVERDUE:tsk-1:2026-09-01')
        ->and($forA[0]['body'])->toBe('Shared work was due 9 days ago.')
        ->and($forB)->toBe($forA);
});

it('never chases anyone about a task with no assignees', function () {
    $overdue = rswTaskRow(['id' => 'tsk-1', 'assignee_ids' => [], 'due_date' => '2026-09-01']);
    $dueSoon = rswTaskRow(['id' => 'tsk-2', 'assignee_ids' => [], 'due_date' => '2026-09-10']);
    $malformed = rswTaskRow(['id' => 'tsk-3', 'assignee_ids' => 'emp-a', 'due_date' => '2026-09-10']);

    expect(ReminderSweep::build('2026-09-10', 'u1', ['TASKS'], 'emp-a', [$overdue, $dueSoon, $malformed]))->toBe([]);
});

it('names the submitter in the review chase, and falls back to the assignee list', function () {
    $employees = [
        rswEmployeeRow('emp-a', 'Ana', 'Garcia'),
        rswEmployeeRow('emp-b', 'Ben', 'Cruz'),
    ];
    $submittedAt = '2026-09-07T08:00:00Z';

    $named = rswTaskRow([
        'id' => 'tsk-1',
        'code' => 'TSK-001',
        'assignee_ids' => ['emp-a', 'emp-b'],
        'submitted_by' => 'emp-b',
        'status' => 'SUBMITTED',
        'submitted_at' => $submittedAt,
    ]);
    $legacy = rswTaskRow([
        'id' => 'tsk-2',
        'code' => 'TSK-002',
        'assignee_ids' => ['emp-a', 'emp-b'],
        'submitted_by' => null,
        'status' => 'SUBMITTED',
        'submitted_at' => $submittedAt,
    ]);
    $orphan = rswTaskRow([
        'id' => 'tsk-3',
        'code' => 'TSK-003',
        'assignee_ids' => [],
        'submitted_by' => null,
        'status' => 'SUBMITTED',
        'submitted_at' => $submittedAt,
    ]);

    $out = ReminderSweep::build('2026-09-10', '7', ['PROJECTS'], null, [$named, $legacy, $orphan], [], $employees);

    expect($out)->toHaveCount(3)
        ->and($out[0]['kind'])->toBe('TASK_REVIEW_PENDING')
        ->and($out[0]['body'])->toBe('Ben Cruz submitted it 3 days ago.')
        ->and($out[1]['body'])->toBe('Ana Garcia, Ben Cruz submitted it 3 days ago.')
        ->and($out[2]['body'])->toBe('an employee submitted it 3 days ago.');
});

/*
|--------------------------------------------------------------------------
| reminders:sweep — the Eloquent row shape end to end
|--------------------------------------------------------------------------
*/

it('writes one due-soon and one overdue row per assignee account, and none for an unassigned task', function () {
    $ana = rswEmployee('Ana', 'Garcia');
    $ben = rswEmployee('Ben', 'Cruz');
    $cid = rswEmployee('Cid', 'Reyes');
    $anaUser = rswUser(['TASKS'], $ana->getKey());
    $benUser = rswUser(['TASKS'], $ben->getKey());
    $cidUser = rswUser(['TASKS'], $cid->getKey());

    $project = rswProject();
    $today = rswToday();

    rswTask($project, 'TSK-SOON', [$ana->getKey(), $ben->getKey()], ['due_date' => $today->addDay()->format('Y-m-d')]);
    rswTask($project, 'TSK-LATE', [$ana->getKey(), $ben->getKey()], ['due_date' => $today->subDays(3)->format('Y-m-d')]);
    rswTask($project, 'TSK-NONE', [], ['due_date' => $today->subDays(3)->format('Y-m-d')]);

    expect(Artisan::call('reminders:sweep'))->toBe(0);

    foreach ([$anaUser, $benUser] as $user) {
        $kinds = Notification::query()->where('user_id', $user->getKey())->orderBy('kind')->pluck('kind')->all();
        expect($kinds)->toBe(['TASK_DUE_SOON', 'TASK_OVERDUE']);
    }

    expect(Notification::query()->where('user_id', $cidUser->getKey())->count())->toBe(0)
        ->and(Notification::query()->where('title', 'like', 'TSK-NONE%')->count())->toBe(0);

    // Idempotent: a second sweep adds nothing.
    expect(Artisan::call('reminders:sweep'))->toBe(0);
    expect(Notification::query()->count())->toBe(4);
});

it('chases the reviewer with the submitter named, from the submitted_by column', function () {
    $ana = rswEmployee('Ana', 'Garcia');
    $ben = rswEmployee('Ben', 'Cruz');
    $reviewer = rswUser(['PROJECTS'], allData: true);

    $project = rswProject();

    $task = rswTask($project, 'TSK-REV', [$ana->getKey(), $ben->getKey()], [
        'status' => 'SUBMITTED',
        'assigned_by' => $reviewer->getKey(),
        'submitted_at' => CarbonImmutable::now()->subDays(3),
        'submitted_by' => $ben->getKey(),
    ]);

    expect(Artisan::call('reminders:sweep'))->toBe(0);

    $row = Notification::query()->where('user_id', $reviewer->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row?->kind)->toBe('TASK_REVIEW_PENDING')
        ->and($row?->title)->toBe('Task TSK-REV is waiting on your review')
        ->and($row?->body)->toBe('Ben Cruz submitted it 3 days ago.')
        ->and($row?->href)->toBe('/projects/'.$task->project_id);
});

/*
|--------------------------------------------------------------------------
| TASK_REVIEW_PENDING — the dedupe key is frozen to the pre-change stamp
|--------------------------------------------------------------------------
*/

/**
 * The key the sweep minted before submitted_at travelled as a CarbonImmutable:
 * the command handed over an offset-less 'Y-m-d H:i:s' (UTC digits), which
 * LocalClock::instant() read as Manila wall clock — eight hours early, at
 * whole seconds. This is that computation, verbatim; every TASK_REVIEW_PENDING
 * row already in the table carries a key of this shape.
 */
function rswLegacyReviewKey(ProjectTask $task, CarbonImmutable $submittedAt): string
{
    return 'TASK_REVIEW_PENDING:'.$task->getKey().':'
        .LocalClock::instant($submittedAt->format('Y-m-d H:i:s'))->utc()->format('Y-m-d\TH:i:s.v\Z');
}

/** One submitted task, four Manila days old against the frozen clock. */
function rswStaleSubmission(User $reviewer, CarbonImmutable $submittedAt): ProjectTask
{
    $ana = rswEmployee('Ana', 'Garcia');

    return rswTask(rswProject(), 'TSK-KEY', [$ana->getKey()], [
        'status' => 'SUBMITTED',
        'assigned_by' => $reviewer->getKey(),
        'submitted_at' => $submittedAt,
        'submitted_by' => $ana->getKey(),
    ]);
}

it('mints the review-pending key exactly as the pre-instant sweep did', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 03:00:00', 'UTC'));
    $reviewer = rswUser(['PROJECTS'], allData: true);
    $submittedAt = CarbonImmutable::parse('2026-09-06 16:30:15.250', 'UTC');
    $task = rswStaleSubmission($reviewer, $submittedAt);

    // 16:30:15.250Z read as Manila wall clock is 08:30:15Z, milliseconds dropped.
    $legacy = rswLegacyReviewKey($task, $submittedAt);
    expect($legacy)->toBe('TASK_REVIEW_PENDING:'.$task->getKey().':2026-09-06T08:30:15.000Z');

    expect(Artisan::call('reminders:sweep'))->toBe(0);

    $keys = Notification::query()
        ->where('user_id', $reviewer->getKey())
        ->where('kind', 'TASK_REVIEW_PENDING')
        ->pluck('dedupe_key')
        ->all();
    expect($keys)->toBe([$legacy]);
});

it('does not chase again a submission whose reminder carries the pre-change key', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 03:00:00', 'UTC'));
    $reviewer = rswUser(['PROJECTS'], allData: true);
    $submittedAt = CarbonImmutable::parse('2026-09-06 16:30:15.250', 'UTC');
    $task = rswStaleSubmission($reviewer, $submittedAt);

    // The row an earlier sweep wrote, under the key it minted then.
    $chased = new Notification;
    $chased->forceFill([
        'user_id' => $reviewer->getKey(),
        'kind' => 'TASK_REVIEW_PENDING',
        'title' => 'TSK-KEY is waiting on your review',
        'dedupe_key' => rswLegacyReviewKey($task, $submittedAt),
    ])->save();

    expect(Artisan::call('reminders:sweep'))->toBe(0)
        ->and(Artisan::output())->toContain('wrote 0 reminders')
        ->and(Notification::query()->where('user_id', $reviewer->getKey())->count())->toBe(1);
});
