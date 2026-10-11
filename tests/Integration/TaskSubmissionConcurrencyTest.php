<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\WorkManagement\Application\TaskEvidenceLocked;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

/**
 * Real connections serialize every decision on a task behind its row lock.
 * Whichever of two racing writers takes the row first wins, and the other is
 * refused. It never acts on a crew, status, mode or response it read before
 * the winner committed. So no reviewer ever accepts a task while they are on
 * its crew; an INDIVIDUAL task never completes while a current assignee's
 * response is not approved; a response row never outlives its member's place
 * on the crew; a response is decided once per revision; an INDIVIDUAL task
 * never carries a group hand-in; evidence never lands behind a hand-in, in
 * either mode, or leaves with an ex-member; and two first hand-ins on
 * different tasks never deadlock.
 */

/**
 * Runs each job in its own worker against task rows this test holds FOR
 * UPDATE. Jobs start one at a time, and each must reach the held row before
 * the next starts. They queue on the row lock in the order given, because
 * InnoDB grants a row's waiters first come, first served. The test then
 * releases the rows and collects each worker's result, in job order.
 *
 * The workers' private disk is `$directory/files`. Pass a directory from
 * taskRaceDirectory() to look at the bytes afterwards; the caller then
 * deletes it. Without one, a directory is made and removed here.
 *
 * @param  list<array{op: string, userId: int|string, employeeId: string, taskId: string}>  $jobs
 * @param  list<string>  $lockTaskIds
 * @return list<array{ok: bool, type?: string, message?: string}>
 */
function taskRace(array $jobs, array $lockTaskIds, ?string $directory = null): array
{
    $ownDirectory = $directory === null;
    $directory ??= taskRaceDirectory();
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];
    $workers = [];
    DB::beginTransaction();
    DB::table('project_tasks')->whereIn('id', $lockTaskIds)->lockForUpdate()->get();
    try {
        foreach ($jobs as $index => $job) {
            $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrency-worker.php'), $job['op'], (string) $job['userId'], $job['employeeId'], $job['taskId'], (string) ($index + 1), $directory], base_path(), $environment, timeout: 20);
            $workers[] = $worker;
            $worker->start();
            $deadline = microtime(true) + 10;
            while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! str_contains($worker->getOutput(), "ready\n")) {
                throw new RuntimeException('Task worker did not reach the held task row: '.$worker->getErrorOutput().$worker->getOutput());
            }
            // "ready" prints just BEFORE the statement runs. Give it time to
            // reach the server and queue on the row, so the next job queues behind it.
            usleep(300000);
        }
        DB::commit();
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            if (! $worker->isSuccessful()) {
                throw new RuntimeException('Task worker failed: '.$worker->getErrorOutput());
            }
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result) || ! is_bool($result['ok'] ?? null)) {
                throw new RuntimeException('Invalid task worker result.');
            }
            $entry = ['ok' => $result['ok']];
            foreach (['type', 'message'] as $key) {
                if (isset($result[$key]) && is_string($result[$key])) {
                    $entry[$key] = $result[$key];
                }
            }
            $results[] = $entry;
        }

        return $results;
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if ($ownDirectory) {
            File::deleteDirectory($directory);
        }
    }
}

/** A scratch directory for the workers, holding the 1 KiB `upload.bin` an attach sends. */
function taskRaceDirectory(): string
{
    $directory = sys_get_temp_dir().'/ems-test-task-race-'.Str::random(16);
    mkdir($directory, 0700);
    file_put_contents($directory.'/upload.bin', str_repeat('x', 1024));

    return $directory;
}

/**
 * A SUBMITTED task handed in by Ben, the only assignee. Mia is an all-data
 * PROJECTS reviewer who is not on the crew. The lead is a second reviewer who
 * can edit the crew.
 *
 * @return array{task: ProjectTask, mia: EmployeeProfile, miaLogin: User, ben: EmployeeProfile, lead: User}
 */
function taskRaceFixture(): array
{
    $role = Role::create(['name' => 'Task race reviewer', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'PROJECTS']);
    $mia = EmployeeProfile::create(['employee_code' => 'RACE-MIA', 'first_name' => 'Mia', 'last_name' => 'Lopez',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $ben = EmployeeProfile::create(['employee_code' => 'RACE-BEN', 'first_name' => 'Ben', 'last_name' => 'Reyes',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $miaLogin = User::factory()->create(['role_id' => $role->id, 'employee_id' => $mia->id]);
    $lead = User::factory()->create(['role_id' => $role->id]);
    $project = Project::create(['code' => 'PRJ-RACE', 'name' => 'Task race', 'status' => 'ACTIVE', 'start_date' => '2026-09-01']);
    $task = ProjectTask::create(['code' => 'TSK-RACE', 'project_id' => $project->id, 'title' => 'Shared report',
        'status' => 'SUBMITTED', 'priority' => 'NORMAL', 'submitted_at' => CarbonImmutable::now(),
        'submitted_by' => $ben->id, 'remarks' => 'Done po.']);
    $task->syncAssignees([$ben->id], null);

    return ['task' => $task, 'mia' => $mia, 'miaLogin' => $miaLogin, 'ben' => $ben, 'lead' => $lead];
}

/** @return list<string> the task's crew as the database holds it, sorted */
function taskRaceCrew(ProjectTask $task): array
{
    $crew = ProjectTaskAssignee::query()->where('task_id', $task->id)->pluck('employee_id')
        ->map(static fn ($id): string => (string) $id)->all();
    sort($crew, SORT_STRING);

    return $crew;
}

/*
|--------------------------------------------------------------------------
| No self-review: accept vs a crew edit adding the reviewer
|--------------------------------------------------------------------------
*/

it('refuses the accept of a reviewer whose addition to the crew took the task row first', function () {
    ['task' => $task, 'mia' => $mia, 'miaLogin' => $miaLogin, 'ben' => $ben, 'lead' => $lead] = taskRaceFixture();

    [$edit, $accept] = taskRace([
        ['op' => 'task-add-assignee', 'userId' => $lead->id, 'employeeId' => $mia->id, 'taskId' => $task->id],
        ['op' => 'task-complete', 'userId' => $miaLogin->id, 'employeeId' => $mia->id, 'taskId' => $task->id],
    ], [$task->id]);

    expect($edit)->toBe(['ok' => true]);
    // The accept waited on the edit's row lock, then read the committed
    // crew under its own lock and found Mia on it.
    expect($accept)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => WorkRules::selfReviewMessage()]);
    $task->refresh();
    expect($task->status)->toBe('SUBMITTED')
        ->and($task->getAttribute('reviewed_by'))->toBeNull()
        ->and($task->completed_at)->toBeNull();
    $expected = [$ben->id, $mia->id];
    sort($expected, SORT_STRING);
    expect(taskRaceCrew($task))->toBe($expected);
    expect(AuditLog::query()->where('entity_id', $task->id)->where('action', 'TASK_COMPLETED')->count())->toBe(0);
});

it('keeps an accept that took the task row first and refuses the crew edit that raced it', function () {
    ['task' => $task, 'mia' => $mia, 'miaLogin' => $miaLogin, 'ben' => $ben, 'lead' => $lead] = taskRaceFixture();

    [$accept, $edit] = taskRace([
        ['op' => 'task-complete', 'userId' => $miaLogin->id, 'employeeId' => $mia->id, 'taskId' => $task->id],
        ['op' => 'task-add-assignee', 'userId' => $lead->id, 'employeeId' => $mia->id, 'taskId' => $task->id],
    ], [$task->id]);

    expect($accept)->toBe(['ok' => true]);
    // The edit's guarded UPDATE waited, then found the task no longer in the
    // status it read, so Mia never joins a task she has already decided.
    expect($edit)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'That task changed while you were editing it.']);
    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and((int) $task->getAttribute('reviewed_by'))->toBe((int) $miaLogin->id);
    expect(taskRaceCrew($task))->toBe([$ben->id]);
    expect(AuditLog::query()->where('entity_id', $task->id)->where('action', 'TASK_COMPLETED')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| INDIVIDUAL tasks: the completion gate, responses, the mode and evidence
|--------------------------------------------------------------------------
*/

/**
 * An active employee with an active TASKS-only login.
 *
 * @return array{0: EmployeeProfile, 1: User}
 */
function taskRaceMember(string $first): array
{
    $employee = EmployeeProfile::create(['employee_code' => 'RACE-'.Str::upper(Str::random(5)), 'first_name' => $first, 'last_name' => 'Cruz',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $role = Role::create(['name' => 'Task race member '.Str::random(6), 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'TASKS']);

    return [$employee, User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id])];
}

/** An all-data PROJECTS reviewer with no employee link, so never on a crew. */
function taskRaceReviewer(): User
{
    $role = Role::create(['name' => 'Task race lead '.Str::random(6), 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'PROJECTS']);

    return User::factory()->create(['role_id' => $role->id]);
}

/**
 * An INDIVIDUAL task in progress, shared by `$crew`, each member's response
 * at its state (null: no row yet).
 *
 * @param  list<array{0: EmployeeProfile, 1: string|null}>  $crew
 */
function taskRaceIndividual(array $crew): ProjectTask
{
    $project = Project::create(['code' => 'PRJ-'.Str::upper(Str::random(4)), 'name' => 'Individual race '.Str::random(6), 'status' => 'ACTIVE', 'start_date' => '2026-09-01']);
    $task = ProjectTask::create(['code' => 'TSK-'.Str::upper(Str::random(5)), 'project_id' => $project->id, 'title' => 'Site survey',
        'status' => 'IN_PROGRESS', 'priority' => 'NORMAL', 'submission_mode' => 'INDIVIDUAL']);
    $task->syncAssignees(array_map(static fn (array $member): string => $member[0]->id, $crew), null);
    foreach ($crew as [$member, $state]) {
        if ($state !== null) {
            ProjectTaskSubmission::create(['task_id' => $task->id, 'employee_id' => $member->id, 'status' => $state,
                'remarks' => 'Fixture note', 'submitted_at' => CarbonImmutable::now()->utc(), 'revision' => 1]);
        }
    }

    return $task;
}

/**
 * A GROUP task in progress, shared by `$crew`, with nothing handed in.
 *
 * @param  list<EmployeeProfile>  $crew
 */
function taskRaceGroup(array $crew): ProjectTask
{
    $project = Project::create(['code' => 'PRJ-'.Str::upper(Str::random(4)), 'name' => 'Group race '.Str::random(6), 'status' => 'ACTIVE', 'start_date' => '2026-09-01']);
    $task = ProjectTask::create(['code' => 'TSK-'.Str::upper(Str::random(5)), 'project_id' => $project->id, 'title' => 'Team report',
        'status' => 'IN_PROGRESS', 'priority' => 'NORMAL', 'submission_mode' => 'GROUP']);
    $task->syncAssignees(array_map(static fn (EmployeeProfile $member): string => $member->id, $crew), null);

    return $task;
}

/** @return array<string, string> each response row's status, keyed by employee id */
function taskRaceResponses(ProjectTask $task): array
{
    return ProjectTaskSubmission::query()->where('task_id', $task->id)->pluck('status', 'employee_id')
        ->map(static fn ($status): string => (string) $status)->all();
}

/** @return list<string> the files the workers left on their private disk */
function taskRaceStoredFiles(string $directory): array
{
    $root = $directory.'/files';

    return is_dir($root) ? array_values(array_map(static fn (SplFileInfo $file): string => $file->getRelativePathname(), File::allFiles($root))) : [];
}

it('never completes an individual task while a newcomer racing the completion has not been approved', function (bool $editFirst) {
    [$ana] = taskRaceMember('Ana');
    [$ben] = taskRaceMember('Ben');
    [$cara] = taskRaceMember('Cara');
    $lead = taskRaceReviewer();
    $task = taskRaceIndividual([[$ana, 'APPROVED'], [$ben, 'APPROVED']]);
    $edit = ['op' => 'task-add-assignee', 'userId' => $lead->id, 'employeeId' => $cara->id, 'taskId' => $task->id];
    $complete = ['op' => 'task-complete', 'userId' => taskRaceReviewer()->id, 'employeeId' => '', 'taskId' => $task->id];

    $results = taskRace($editFirst ? [$edit, $complete] : [$complete, $edit], [$task->id]);
    [$editResult, $completeResult] = $editFirst ? $results : array_reverse($results);

    $task->refresh();
    if ($editFirst) {
        expect($editResult)->toBe(['ok' => true]);
        expect($completeResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => "Every assignee's response has to be approved before the task can be completed."]);
        expect($task->status)->toBe('IN_PROGRESS');
        expect(taskRaceCrew($task))->toContain($cara->id);
    } else {
        expect($completeResult)->toBe(['ok' => true]);
        expect($editResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'That task changed while you were editing it.']);
        expect($task->status)->toBe('COMPLETED');
        expect(taskRaceCrew($task))->not->toContain($cara->id);
    }
    // THE INVARIANT: never COMPLETED with a current assignee not approved.
    if ($task->status === 'COMPLETED') {
        $responses = taskRaceResponses($task);
        foreach (taskRaceCrew($task) as $member) {
            expect($responses[$member] ?? 'NONE')->toBe('APPROVED');
        }
    }
})->with(['crew edit first' => true, 'completion first' => false]);

it('lets exactly one of a completion and a return of an approved response win', function (bool $returnFirst) {
    [$ana] = taskRaceMember('Ana');
    [$ben] = taskRaceMember('Ben');
    $task = taskRaceIndividual([[$ana, 'APPROVED'], [$ben, 'APPROVED']]);
    $return = ['op' => 'task-return-response', 'userId' => taskRaceReviewer()->id, 'employeeId' => $ana->id, 'taskId' => $task->id];
    $complete = ['op' => 'task-complete', 'userId' => taskRaceReviewer()->id, 'employeeId' => '', 'taskId' => $task->id];

    $results = taskRace($returnFirst ? [$return, $complete] : [$complete, $return], [$task->id]);
    [$returnResult, $completeResult] = $returnFirst ? $results : array_reverse($results);

    $task->refresh();
    if ($returnFirst) {
        expect($returnResult)->toBe(['ok' => true]);
        expect($completeResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => "Every assignee's response has to be approved before the task can be completed."]);
        expect($task->status)->toBe('IN_PROGRESS');
        expect(taskRaceResponses($task)[$ana->id])->toBe('RETURNED');
    } else {
        expect($completeResult)->toBe(['ok' => true]);
        expect($returnResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => TaskSubmissionPolicy::transitionError()]);
        expect($task->status)->toBe('COMPLETED');
        expect(taskRaceResponses($task)[$ana->id])->toBe('APPROVED');
    }
    // Never COMPLETED with a RETURNED member.
    expect($task->status === 'COMPLETED' && in_array('RETURNED', taskRaceResponses($task), true))->toBeFalse();
})->with(['return first' => true, 'completion first' => false]);

it('never keeps a response row for a member unassigned while they submit', function (bool $removeFirst) {
    [$ana, $anaLogin] = taskRaceMember('Ana');
    [$ben] = taskRaceMember('Ben');
    [$cara] = taskRaceMember('Cara');
    $task = taskRaceIndividual([[$ana, null], [$ben, null], [$cara, null]]);
    $remove = ['op' => 'task-remove-assignee', 'userId' => taskRaceReviewer()->id, 'employeeId' => $ana->id, 'taskId' => $task->id];
    $submit = ['op' => 'task-submit', 'userId' => $anaLogin->id, 'employeeId' => $ana->id, 'taskId' => $task->id];

    $results = taskRace($removeFirst ? [$remove, $submit] : [$submit, $remove], [$task->id]);
    [$removeResult, $submitResult] = $removeFirst ? $results : array_reverse($results);

    expect($removeResult)->toBe(['ok' => true]);
    expect($submitResult)->toBe($removeFirst
        ? ['ok' => false, 'type' => RuntimeException::class, 'message' => 'You are no longer assigned to this task.']
        : ['ok' => true]);
    expect(taskRaceCrew($task))->not->toContain($ana->id);
    // THE INVARIANT: response rows are a subset of the crew.
    expect(array_diff(array_keys(taskRaceResponses($task)), taskRaceCrew($task)))->toBe([]);
    $audit = AuditLog::query()->where('entity_id', $task->id)->where('action', 'TASK_UPDATED')->sole();
    expect(str_contains((string) $audit->details, '(1 response discarded)'))->toBe(! $removeFirst);
})->with(['removal first' => true, 'hand-in first' => false]);

it('decides one revision of a response once when two reviewers approve it together', function () {
    [$ana, $anaLogin] = taskRaceMember('Ana');
    [$ben] = taskRaceMember('Ben');
    $task = taskRaceIndividual([[$ana, 'SUBMITTED'], [$ben, null]]);
    $first = taskRaceReviewer();
    $second = taskRaceReviewer();

    [$one, $two] = taskRace([
        ['op' => 'task-approve-response', 'userId' => $first->id, 'employeeId' => $ana->id, 'taskId' => $task->id],
        ['op' => 'task-approve-response', 'userId' => $second->id, 'employeeId' => $ana->id, 'taskId' => $task->id],
    ], [$task->id]);

    expect($one)->toBe(['ok' => true]);
    expect($two)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => TaskSubmissionPolicy::transitionError()]);
    $row = ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $ana->id)->sole();
    expect($row->status)->toBe('APPROVED')
        ->and($row->revision)->toBe(2)
        ->and($row->reviewed_by)->toBe((int) $first->id);
    expect(AuditLog::query()->where('action', 'TASK_RESPONSE_APPROVED')->count())->toBe(1);
    expect(Notification::query()->where('kind', 'TASK_RESPONSE_APPROVED')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all())->toBe([(int) $anaLogin->id]);
});

it('ends a mode switch racing a first hand-in as GROUP with no rows or INDIVIDUAL with one', function (bool $switchFirst) {
    [$ana, $anaLogin] = taskRaceMember('Ana');
    [$ben] = taskRaceMember('Ben');
    $task = taskRaceIndividual([[$ana, null], [$ben, null]]);
    $switch = ['op' => 'task-switch-to-group', 'userId' => taskRaceReviewer()->id, 'employeeId' => '', 'taskId' => $task->id];
    $submit = ['op' => 'task-submit', 'userId' => $anaLogin->id, 'employeeId' => $ana->id, 'taskId' => $task->id];

    $results = taskRace($switchFirst ? [$switch, $submit] : [$submit, $switch], [$task->id]);
    [$switchResult, $submitResult] = $switchFirst ? $results : array_reverse($results);

    $task->refresh();
    if ($switchFirst) {
        expect($switchResult)->toBe(['ok' => true]);
        expect($submitResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'That task changed before this action completed.']);
        expect($task->submission_mode)->toBe('GROUP');
        expect(taskRaceResponses($task))->toBe([]);
    } else {
        expect($submitResult)->toBe(['ok' => true]);
        expect($switchResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'The submission type is locked once anyone has handed in work or attached evidence.']);
        expect($task->submission_mode)->toBe('INDIVIDUAL');
        expect(taskRaceResponses($task))->toBe([$ana->id => 'SUBMITTED']);
    }
    // A GROUP task never carries member rows, and the task row never
    // carries an individual hand-in.
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->submitted_by)->toBeNull();
})->with(['switch first' => true, 'hand-in first' => false]);

it('ends a switch to individual racing a group hand-in as a handed-in GROUP task or an untouched INDIVIDUAL one', function (bool $switchFirst) {
    [$ana, $anaLogin] = taskRaceMember('Ana');
    [$ben] = taskRaceMember('Ben');
    $task = taskRaceGroup([$ana, $ben]);
    $switch = ['op' => 'task-switch-to-individual', 'userId' => taskRaceReviewer()->id, 'employeeId' => '', 'taskId' => $task->id];
    $submit = ['op' => 'task-submit', 'userId' => $anaLogin->id, 'employeeId' => $ana->id, 'taskId' => $task->id];

    $results = taskRace($switchFirst ? [$switch, $submit] : [$submit, $switch], [$task->id]);
    [$switchResult, $submitResult] = $switchFirst ? $results : array_reverse($results);

    $task->refresh();
    if ($switchFirst) {
        expect($switchResult)->toBe(['ok' => true]);
        // The hand-in read GROUP before the race and took the group path.
        // Its guarded UPDATE then found the task INDIVIDUAL and matched nothing.
        expect($submitResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'That task changed before this action completed.']);
        expect($task->submission_mode)->toBe('INDIVIDUAL')
            ->and($task->status)->toBe('IN_PROGRESS');
    } else {
        expect($submitResult)->toBe(['ok' => true]);
        // The switch re-read the task under L1 and found it moved on.
        expect($switchResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'That task changed while you were editing it.']);
        expect($task->submission_mode)->toBe('GROUP')
            ->and($task->status)->toBe('SUBMITTED')
            ->and($task->submitted_by)->toBe($ana->id);
    }
    // THE INVARIANT: an INDIVIDUAL task never carries a group hand-in, and a
    // group hand-in never leaves member rows behind.
    if ($task->submission_mode === 'INDIVIDUAL') {
        expect($task->submitted_by)->toBeNull()
            ->and($task->remarks)->toBeNull();
    }
    expect(taskRaceResponses($task))->toBe([]);
    expect(AuditLog::query()->where('entity_id', $task->id)->where('action', 'TASK_SUBMITTED')->count())->toBe($switchFirst ? 0 : 1);
})->with(['switch first' => true, 'hand-in first' => false]);

it('lets first hand-ins on two different tasks both land without a deadlock', function () {
    [$ana, $anaLogin] = taskRaceMember('Ana');
    [$ben, $benLogin] = taskRaceMember('Ben');
    [$cara] = taskRaceMember('Cara');
    $one = taskRaceIndividual([[$ana, null], [$cara, null]]);
    $two = taskRaceIndividual([[$ben, null], [$cara, null]]);

    // Both task rows are held, so both workers wait on their own task and
    // are released together: their inserts into the response index race.
    $results = taskRace([
        ['op' => 'task-submit', 'userId' => $anaLogin->id, 'employeeId' => $ana->id, 'taskId' => $one->id],
        ['op' => 'task-submit', 'userId' => $benLogin->id, 'employeeId' => $ben->id, 'taskId' => $two->id],
    ], [$one->id, $two->id]);

    expect($results)->toBe([['ok' => true], ['ok' => true]]);
    expect(taskRaceResponses($one))->toBe([$ana->id => 'SUBMITTED']);
    expect(taskRaceResponses($two))->toBe([$ben->id => 'SUBMITTED']);
});

it('never lands evidence behind a member\'s own hand-in', function (bool $submitFirst) {
    [$ana, $anaLogin] = taskRaceMember('Ana');
    [$ben] = taskRaceMember('Ben');
    $task = taskRaceIndividual([[$ana, null], [$ben, null]]);
    $submit = ['op' => 'task-submit', 'userId' => $anaLogin->id, 'employeeId' => $ana->id, 'taskId' => $task->id];
    $attach = ['op' => 'task-attach', 'userId' => $anaLogin->id, 'employeeId' => $ana->id, 'taskId' => $task->id];
    $directory = taskRaceDirectory();

    try {
        $results = taskRace($submitFirst ? [$submit, $attach] : [$attach, $submit], [$task->id], $directory);
        [$submitResult, $attachResult] = $submitFirst ? $results : array_reverse($results);
        $stored = taskRaceStoredFiles($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($submitResult)->toBe(['ok' => true]);
    $response = ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $ana->id)->sole();
    $files = ProjectTaskFile::query()->where('task_id', $task->id)->get();
    if ($submitFirst) {
        expect($attachResult)->toBe(['ok' => false, 'type' => TaskEvidenceLocked::class, 'message' => 'Your evidence is locked while your response is under review or approved.']);
        expect($files)->toHaveCount(0);
        // AttachmentStore discarded the bytes it had written.
        expect($stored)->toBe([]);
    } else {
        expect($attachResult)->toBe(['ok' => true]);
        expect($files)->toHaveCount(1);
        expect($stored)->toBe([$files[0]->path]);
    }
    // THE INVARIANT: any evidence committed before the hand-in it belongs to.
    foreach ($files as $file) {
        expect($file->created_at->lessThanOrEqualTo($response->submitted_at))->toBeTrue();
    }
})->with(['hand-in first' => true, 'attach first' => false]);

it('never lands a teammate\'s evidence on a group task behind its hand-in', function (bool $submitFirst) {
    [$ana, $anaLogin] = taskRaceMember('Ana');
    [$ben, $benLogin] = taskRaceMember('Ben');
    $task = taskRaceGroup([$ana, $ben]);
    $submit = ['op' => 'task-submit', 'userId' => $anaLogin->id, 'employeeId' => $ana->id, 'taskId' => $task->id];
    $attach = ['op' => 'task-attach', 'userId' => $benLogin->id, 'employeeId' => $ben->id, 'taskId' => $task->id];
    $directory = taskRaceDirectory();

    try {
        $results = taskRace($submitFirst ? [$submit, $attach] : [$attach, $submit], [$task->id], $directory);
        [$submitResult, $attachResult] = $submitFirst ? $results : array_reverse($results);
        $stored = taskRaceStoredFiles($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($submitResult)->toBe(['ok' => true]);
    expect($task->refresh()->status)->toBe('SUBMITTED');
    $files = ProjectTaskFile::query()->where('task_id', $task->id)->get();
    // Pinned by the outcomes, not the timestamps: a GROUP hand-in stamps
    // submitted_at BEFORE its guarded UPDATE queues on the row, so a file
    // that committed while it waited can carry a later created_at.
    if ($submitFirst) {
        // Ben's upload passed its plain pre-check while Ana's hand-in waited,
        // then re-read the task under L1 and found it handed in.
        expect($attachResult)->toBe(['ok' => false, 'type' => TaskEvidenceLocked::class, 'message' => 'Task evidence is locked in this status.']);
        expect($files)->toHaveCount(0);
        // AttachmentStore discarded the bytes it had written.
        expect($stored)->toBe([]);
    } else {
        expect($attachResult)->toBe(['ok' => true]);
        expect($files)->toHaveCount(1);
        expect($files[0]->employee_id)->toBe($ben->id);
        expect($stored)->toBe([$files[0]->path]);
    }
})->with(['hand-in first' => true, 'attach first' => false]);

it('never lets a member unassigned while deleting their evidence destroy it', function (bool $removeFirst) {
    [$ana, $anaLogin] = taskRaceMember('Ana');
    [$ben] = taskRaceMember('Ben');
    [$cara] = taskRaceMember('Cara');
    $task = taskRaceIndividual([[$ana, null], [$ben, null], [$cara, null]]);
    $directory = taskRaceDirectory();
    mkdir($directory.'/files/task-files', 0700, true);
    file_put_contents($directory.'/files/task-files/ana.pdf', 'evidence');
    $evidence = new ProjectTaskFile;
    $evidence->fill(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => 'ana.pdf', 'mime' => 'application/pdf', 'size' => 8, 'path' => 'task-files/ana.pdf']);
    $evidence->uploaded_by = (int) $anaLogin->id;
    $evidence->employee_id = $ana->id;
    $evidence->save();
    $remove = ['op' => 'task-remove-assignee', 'userId' => taskRaceReviewer()->id, 'employeeId' => $ana->id, 'taskId' => $task->id];
    $delete = ['op' => 'task-delete-file', 'userId' => $anaLogin->id, 'employeeId' => $ana->id, 'taskId' => $task->id];

    try {
        $results = taskRace($removeFirst ? [$remove, $delete] : [$delete, $remove], [$task->id], $directory);
        [$removeResult, $deleteResult] = $removeFirst ? $results : array_reverse($results);
        $stored = taskRaceStoredFiles($directory);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($removeResult)->toBe(['ok' => true]);
    expect(taskRaceCrew($task))->not->toContain($ana->id);
    if ($removeFirst) {
        // Refused under the lock (L2), after the plain pre-checks passed.
        expect($deleteResult['ok'])->toBeFalse()
            ->and($deleteResult['type'] ?? null)->toBe(HttpException::class);
        expect(ProjectTaskFile::query()->whereKey($evidence->id)->exists())->toBeTrue();
        expect($stored)->toBe(['task-files/ana.pdf']);
        expect(AuditLog::query()->where('action', 'TASK_FILE_REMOVED')->count())->toBe(0);
    } else {
        expect($deleteResult)->toBe(['ok' => true]);
        expect(ProjectTaskFile::query()->whereKey($evidence->id)->exists())->toBeFalse();
        expect($stored)->toBe([]);
    }
})->with(['removal first' => true, 'delete first' => false]);
