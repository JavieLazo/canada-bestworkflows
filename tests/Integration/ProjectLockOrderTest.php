<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Real connections serialize every decision on a project fact behind the
 * project row (P in WorkRules' lock protocol), and always take task locks
 * before it, never after. So a COMPLETED project never holds an open task:
 * a task reopened by hand and a completion of its project race to P, and
 * exactly one wins in either order — the reopen is refused on a project that
 * completed first, the completion on a task reopened first — and a task
 * created while its project completes either lands first and blocks the
 * completion, or finds the project completed and is refused. And deleting a
 * project never deadlocks (1213) with a writer of one of its tasks — its Task
 * Leader's hand-set status, review or start date, a manager's due-date move —
 * or with a task being created: whichever takes the task rows first
 * finishes, then the other does, or finds the task or project gone.
 *
 * The delete's side of that rests on WorkRules' claim that DeleteProject
 * takes "CreateTask's own whole-table scan". The races with a due-date move
 * and with a create pin it: two scans that walk the table in different index
 * orders, or a scan through an index a task writer then updates, deadlock.
 */

/**
 * Runs each job in its own worker (tests/Support/concurrency-worker.php, its
 * `project-` operations) while this test holds the given PROJECT rows — P —
 * and, when given, TASK rows FOR UPDATE. Jobs start one at a time, and each
 * must reach its first locking read of a project or task row before the
 * next starts. Every job here then queues on P, or on a task row — one the
 * test holds, or one a job ahead of it holds. InnoDB grants a row's waiters
 * first come, first served, so they win in the order given. The test then
 * releases its rows and collects each worker's result, in job order:
 * `outcome` is what the command returned, when it returned something.
 *
 * @param  list<array{op: string, userId: int|string, employeeId?: string, runId: string}>  $jobs
 * @param  list<string>  $lockProjectIds
 * @param  list<string>  $lockTaskIds
 * @return list<array{ok: bool, type?: string, message?: string, outcome?: mixed}>
 */
function plockRace(array $jobs, array $lockProjectIds, array $lockTaskIds = []): array
{
    $directory = sys_get_temp_dir().'/ems-test-project-race-'.Str::random(16);
    mkdir($directory, 0700);
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
    DB::table('projects')->whereIn('id', $lockProjectIds)->lockForUpdate()->get();
    // By primary key, one row at a time: only the clustered record is held.
    foreach ($lockTaskIds as $taskId) {
        DB::table('project_tasks')->where('id', $taskId)->lockForUpdate()->get();
    }
    try {
        foreach ($jobs as $index => $job) {
            $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrency-worker.php'), $job['op'], (string) $job['userId'], $job['employeeId'] ?? '', $job['runId'], (string) ($index + 1), $directory], base_path(), $environment, timeout: 20);
            $workers[] = $worker;
            $worker->start();
            $deadline = microtime(true) + 10;
            while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! str_contains($worker->getOutput(), "ready\n")) {
                throw new RuntimeException('Project worker did not reach a locking read: '.$worker->getErrorOutput().$worker->getOutput());
            }
            // "ready" prints just BEFORE the statement runs. Give it time to
            // reach the server — and, when that lock is granted, to go on to
            // P — and queue, so the next job queues behind it.
            usleep(300000);
        }
        DB::commit();
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            if (! $worker->isSuccessful()) {
                throw new RuntimeException('Project worker failed: '.$worker->getErrorOutput());
            }
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result) || ! is_bool($result['ok'] ?? null)) {
                throw new RuntimeException('Invalid project worker result.');
            }
            $entry = ['ok' => $result['ok']];
            foreach (['type', 'message'] as $key) {
                if (isset($result[$key]) && is_string($result[$key])) {
                    $entry[$key] = $result[$key];
                }
            }
            if (isset($result['outcome'])) {
                $entry['outcome'] = $result['outcome'];
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
        File::deleteDirectory($directory);
    }
}

/** @param list<string> $modules */
function plockRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Lock order '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** An all-data PROJECTS manager with no employee link, so never on a crew. */
function plockManager(): User
{
    return User::factory()->create(['role_id' => plockRole(['PROJECTS'], true)->getKey()]);
}

/**
 * A Task Leader account: PROJECTS without all-data, and no employee link, so
 * it sees the project only as its leader and is never on a crew.
 */
function plockLeader(): User
{
    return User::factory()->create(['role_id' => plockRole(['PROJECTS', 'TASKS'], false)->getKey()]);
}

/**
 * An active employee with an active TASKS-only login, so a notice to the
 * crew lands somewhere.
 *
 * @return array{0: EmployeeProfile, 1: User}
 */
function plockMember(string $first): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LOCK-'.Str::upper(Str::random(5)),
        'first_name' => $first,
        'last_name' => 'Cruz',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return [$employee, User::factory()->create(['role_id' => plockRole(['TASKS'], false)->getKey(), 'employee_id' => $employee->id])];
}

/** An ACTIVE project led by `$leader`. */
function plockProject(User $leader): Project
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::upper(Str::random(4)),
        'name' => 'Lock order '.Str::random(6),
        'status' => 'ACTIVE',
        'start_date' => '2026-09-01',
        'due_date' => '2026-12-31',
        'manager_id' => $leader->getKey(),
    ])->save();

    return $project;
}

/**
 * A GROUP task of the project in `$status`, shared by `$crew`, due mid-October
 * with no start date planned. A SUBMITTED or COMPLETED task carries the
 * hand-in that got it there, a COMPLETED or CANCELLED one the decision.
 *
 * @param  list<EmployeeProfile>  $crew
 * @param  array<string, mixed>  $columns  overrides, merged last
 */
function plockTask(Project $project, string $status, array $crew, array $columns = []): ProjectTask
{
    $now = CarbonImmutable::now()->utc();
    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => 'TSK-'.Str::upper(Str::random(5)),
        'project_id' => $project->id,
        'title' => 'Site survey '.Str::random(4),
        'status' => $status,
        'priority' => 'NORMAL',
        'submission_mode' => 'GROUP',
        'due_date' => '2026-10-15',
        'assigned_at' => $now,
    ], in_array($status, ['SUBMITTED', 'COMPLETED'], true) && $crew !== []
        ? ['submitted_at' => $now, 'submitted_by' => $crew[0]->id, 'remarks' => 'Done po.']
        : [], in_array($status, ['COMPLETED', 'CANCELLED'], true)
        ? ['completed_at' => $status === 'COMPLETED' ? $now : null, 'review_remarks' => 'Decided before the race.']
        : [], $columns))->save();
    if ($crew !== []) {
        $task->syncAssignees(array_map(static fn (EmployeeProfile $member): string => $member->id, $crew), null);
    }

    return $task;
}

/** THE INVARIANT: a COMPLETED project holds no task that is still open. */
function plockAssertNoOpenTaskInCompletedProject(Project $project): void
{
    $status = Project::query()->whereKey($project->id)->value('status');
    if ($status !== 'COMPLETED') {
        return;
    }
    $open = ProjectTask::query()->where('project_id', $project->id)->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->count();
    expect($open)->toBe(0);
}

/** How many audit rows carry `$action` for this entity. */
function plockAudits(string $entityId, string $action): int
{
    return AuditLog::query()->where('entity_id', $entityId)->where('action', $action)->count();
}

/*
|--------------------------------------------------------------------------
| A project completion racing a task reopened by hand
|--------------------------------------------------------------------------
*/

it('lets exactly one of a project completion and a task reopen win, in either order', function (string $closed, bool $completeFirst) {
    [$ana, $anaLogin] = plockMember('Ana');
    $leader = plockLeader();
    $project = plockProject($leader);
    $task = plockTask($project, $closed, [$ana]);
    // A second closed task, so the completion's plain read has more than
    // the raced row to look at.
    plockTask($project, 'COMPLETED', [$ana]);
    $complete = ['op' => 'project-complete', 'userId' => plockManager()->id, 'runId' => $project->id];
    $reopen = ['op' => 'project-task-reopen', 'userId' => $leader->id, 'runId' => $task->id];

    $results = plockRace($completeFirst ? [$complete, $reopen] : [$reopen, $complete], [$project->id]);
    [$completeResult, $reopenResult] = $completeFirst ? $results : array_reverse($results);

    $project->refresh();
    $task->refresh();
    if ($completeFirst) {
        expect($completeResult)->toBe(['ok' => true, 'outcome' => true]);
        // The reopen held its task row, waited on P, then read the committed
        // project under it and found it completed.
        expect($reopenResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'Reopen the project before reopening one of its tasks.']);
        expect($project->status)->toBe('COMPLETED');
        expect($task->status)->toBe($closed)
            ->and($task->review_remarks)->toBe('Decided before the race.');
        expect(plockAudits($project->id, 'PROJECT_STATUS_CHANGED'))->toBe(1);
        expect(plockAudits($task->id, 'TASK_IN_PROGRESS'))->toBe(0);
        expect(Notification::query()->where('user_id', $anaLogin->id)->where('kind', 'TASK_IN_PROGRESS')->count())->toBe(0);
    } else {
        expect($reopenResult)->toBe(['ok' => true]);
        // The completion waited on P behind the reopen, then read the tasks
        // plainly — its snapshot opened only after the reopen committed.
        expect($completeResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'Complete or cancel every task before completing the project.']);
        expect($project->status)->toBe('ACTIVE');
        expect($task->status)->toBe('IN_PROGRESS')
            ->and($task->completed_at)->toBeNull()
            ->and($task->review_remarks)->toBeNull();
        expect(plockAudits($project->id, 'PROJECT_STATUS_CHANGED'))->toBe(0);
        expect(plockAudits($task->id, 'TASK_IN_PROGRESS'))->toBe(1);
        expect(Notification::query()->where('user_id', $anaLogin->id)->where('kind', 'TASK_IN_PROGRESS')->count())->toBe(1);
    }
    plockAssertNoOpenTaskInCompletedProject($project);
})->with([
    'cancelled task, completion first' => ['CANCELLED', true],
    'cancelled task, reopen first' => ['CANCELLED', false],
    'completed task, completion first' => ['COMPLETED', true],
    'completed task, reopen first' => ['COMPLETED', false],
]);

/*
|--------------------------------------------------------------------------
| A project completion racing a new task
|--------------------------------------------------------------------------
*/

it('never leaves a completed project with a task created while it completed', function (bool $completeFirst) {
    $leader = plockLeader();
    $project = plockProject($leader);
    plockTask($project, 'COMPLETED', []);
    // The leader completes; only a manager creates tasks.
    $complete = ['op' => 'project-complete', 'userId' => $leader->id, 'runId' => $project->id];
    $create = ['op' => 'project-create-task', 'userId' => plockManager()->id, 'runId' => $project->id];

    $results = plockRace($completeFirst ? [$complete, $create] : [$create, $complete], [$project->id]);
    [$completeResult, $createResult] = $completeFirst ? $results : array_reverse($results);

    $project->refresh();
    $tasks = ProjectTask::query()->where('project_id', $project->id)->pluck('status', 'code')->all();
    if ($completeFirst) {
        expect($completeResult)->toBe(['ok' => true, 'outcome' => true]);
        // The create took the task-code scan, waited on P, then found the
        // project completed under it: nothing was inserted.
        expect($createResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'Reopen the project before adding a task to it.']);
        expect($project->status)->toBe('COMPLETED');
        expect(array_values($tasks))->toBe(['COMPLETED']);
        expect(AuditLog::query()->where('action', 'TASK_ASSIGNED')->count())->toBe(0);
    } else {
        expect($createResult['ok'])->toBeTrue();
        // The completion waited on P behind the create, and its plain read
        // then counted the new task.
        expect($completeResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'Complete or cancel every task before completing the project.']);
        expect($project->status)->toBe('ACTIVE');
        expect($tasks)->toHaveCount(2);
        // The new task is open, under the code the create returned.
        $code = $createResult['outcome'] ?? null;
        expect(is_string($code) ? ($tasks[$code] ?? null) : null)->toBe('PENDING');
        expect(plockAudits($project->id, 'PROJECT_STATUS_CHANGED'))->toBe(0);
    }
    plockAssertNoOpenTaskInCompletedProject($project);
})->with(['completion first' => true, 'create first' => false]);

/*
|--------------------------------------------------------------------------
| A project delete racing its Task Leader on one of its tasks
|--------------------------------------------------------------------------
*/

it('never deadlocks a project delete with its task leader deciding one of its tasks', function (string $op, string $status, string $landed, bool $deleteFirst) {
    [$ana] = plockMember('Ana');
    $leader = plockLeader();
    $project = plockProject($leader);
    $task = plockTask($project, $status, [$ana]);
    $other = plockTask($project, 'PENDING', [$ana]);
    $delete = ['op' => 'project-delete', 'userId' => plockManager()->id, 'runId' => $project->id];
    $decide = ['op' => $op, 'userId' => $leader->id, 'runId' => $task->id];

    $results = plockRace($deleteFirst ? [$delete, $decide] : [$decide, $delete], [$project->id]);
    [$deleteResult, $decideResult] = $deleteFirst ? $results : array_reverse($results);

    // Both end cleanly, and never on a deadlock (1213): the delete finishes
    // either way — with every row of the project gone.
    expect($deleteResult)->toBe(['ok' => true, 'outcome' => $project->code]);
    if ($deleteFirst) {
        // The leader's lookup ran before the delete committed; its task lock
        // queued behind the delete's scan, then found the row gone.
        expect($decideResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'That task no longer exists.']);
        expect(plockAudits($task->id, 'TASK_'.$landed))->toBe(0);
    } else {
        // The decision held the task row and took P first; the delete's scan
        // waited on that row, then took P after it and removed the task.
        expect($decideResult)->toBe(['ok' => true]);
        expect(plockAudits($task->id, 'TASK_'.$landed))->toBe(1);
    }
    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse();
    expect(ProjectTask::query()->whereIn('id', [$task->id, $other->id])->exists())->toBeFalse();
    expect(ProjectTaskAssignee::query()->whereIn('task_id', [$task->id, $other->id])->exists())->toBeFalse();
    expect(plockAudits($project->id, 'PROJECT_DELETED'))->toBe(1);
    $details = (string) AuditLog::query()->where('entity_id', $project->id)->where('action', 'PROJECT_DELETED')->value('details');
    expect($details)->toEndWith('deleted with 2 tasks');
})->with([
    'status by hand, delete first' => ['project-task-reopen', 'CANCELLED', 'IN_PROGRESS', true],
    'status by hand, status first' => ['project-task-reopen', 'CANCELLED', 'IN_PROGRESS', false],
    'review, delete first' => ['project-task-accept', 'SUBMITTED', 'COMPLETED', true],
    'review, review first' => ['project-task-accept', 'SUBMITTED', 'COMPLETED', false],
]);

it('never deadlocks a project delete with a date edit on one of its tasks', function (string $op, bool $byLeader, string $column, bool $deleteFirst) {
    [$ana] = plockMember('Ana');
    $leader = plockLeader();
    $project = plockProject($leader);
    $task = plockTask($project, 'IN_PROGRESS', [$ana]);
    $other = plockTask($project, 'PENDING', [$ana]);
    $delete = ['op' => 'project-delete', 'userId' => plockManager()->id, 'runId' => $project->id];
    // A manager moves the due date, which is indexed (tasks_due_idx); the
    // leader plans the start date, which is not.
    $edit = ['op' => $op, 'userId' => $byLeader ? $leader->id : plockManager()->id, 'runId' => $task->id];

    $results = plockRace($deleteFirst ? [$delete, $edit] : [$edit, $delete], [$project->id]);
    [$deleteResult, $editResult] = $deleteFirst ? $results : array_reverse($results);

    expect($deleteResult)->toBe(['ok' => true, 'outcome' => $project->code]);
    if ($deleteFirst) {
        // The edit's task lock queued behind the delete's scan, then found
        // the row gone: nothing changed, as far as that edit can tell.
        expect($editResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'That task changed while you were editing it.']);
        expect(plockAudits($task->id, 'TASK_UPDATED'))->toBe(0);
    } else {
        expect($editResult)->toBe(['ok' => true]);
        $details = (string) AuditLog::query()->where('entity_id', $task->id)->where('action', 'TASK_UPDATED')->sole()->details;
        expect($details)->toEndWith('updated ('.$column.')');
    }
    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse();
    expect(ProjectTask::query()->whereIn('id', [$task->id, $other->id])->exists())->toBeFalse();
    expect(plockAudits($project->id, 'PROJECT_DELETED'))->toBe(1);
})->with([
    'due date by a manager, delete first' => ['project-task-move-due', false, 'due_date', true],
    'due date by a manager, edit first' => ['project-task-move-due', false, 'due_date', false],
    'start date by the leader, delete first' => ['project-task-plan-start', true, 'start_date', true],
    'start date by the leader, edit first' => ['project-task-plan-start', true, 'start_date', false],
]);

/*
|--------------------------------------------------------------------------
| A project delete racing a new task
|--------------------------------------------------------------------------
*/

it('never deadlocks a project delete with a task being created', function (bool $deleteFirst) {
    $leader = plockLeader();
    $project = plockProject($leader);
    // Code order and due-date order disagree: TSK-902 is due first, and
    // comes last by code. The test holds it, so each scan stops on it —
    // DeleteProject's before any other row if it walks the tasks by due
    // date, CreateTask's after TSK-901 if it walks them by code.
    $dueFirst = plockTask($project, 'PENDING', [], ['code' => 'TSK-902', 'due_date' => '2026-10-01']);
    $dueLast = plockTask($project, 'PENDING', [], ['code' => 'TSK-901', 'due_date' => '2026-10-20']);
    $delete = ['op' => 'project-delete', 'userId' => plockManager()->id, 'runId' => $project->id];
    $create = ['op' => 'project-create-task', 'userId' => plockManager()->id, 'runId' => $project->id];

    $results = plockRace($deleteFirst ? [$delete, $create] : [$create, $delete], [], [$dueFirst->id]);
    [$deleteResult, $createResult] = $deleteFirst ? $results : array_reverse($results);

    // THE WorkRules PROTOCOL: DeleteProject takes "CreateTask's own
    // whole-table scan", so the two serialise on one plan and the loser of
    // the scan simply waits — never a deadlock (1213).
    expect($deleteResult)->toBe(['ok' => true, 'outcome' => $project->code]);
    if ($deleteFirst) {
        // The create finished its scan after the delete committed and then
        // found no project under P.
        expect($createResult)->toBe(['ok' => false, 'type' => RuntimeException::class, 'message' => 'That project no longer exists.']);
    } else {
        expect($createResult['ok'])->toBeTrue();
    }
    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse();
    expect(ProjectTask::query()->where('project_id', $project->id)->exists())->toBeFalse();
    expect(ProjectTask::query()->whereIn('id', [$dueFirst->id, $dueLast->id])->exists())->toBeFalse();
    $details = (string) AuditLog::query()->where('entity_id', $project->id)->where('action', 'PROJECT_DELETED')->sole()->details;
    expect($details)->toEndWith($deleteFirst ? 'deleted with 2 tasks' : 'deleted with 3 tasks');
})->with(['delete first' => true, 'create first' => false]);
