<?php

declare(strict_types=1);

use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\TaskDateEdit;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(function (): void {
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'ems_testing'], true)) {
        throw new RuntimeException('Only the guarded synthetic schema may be cleaned.');
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::table('project_tasks')->delete();
    DB::table('project_task_groups')->delete();
    DB::table('projects')->delete();
});

it('reads member attention and client search under strict MariaDB grouping', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $second = cpaEmployee('Peer');
    $peer = cpaUser($second, ['TASKS']);
    $client = cpaClient($owner, [$worker, $peer]);
    $client = app(SaveClient::class)->handle(Access::forUser($owner), $client->id, 'Acme àlpha');
    $project = cpaProject($owner, $client);
    $late = cpaTask($owner, $project, [$employee], title: 'Late own work');
    $late->update(['due_date' => '2026-10-30']);
    $approved = cpaTask($owner, $project, [$employee, $second], 'INDIVIDUAL', 'Approved own response');
    $approved->update(['status' => 'IN_PROGRESS', 'due_date' => '2026-10-30']);
    $response = new ProjectTaskSubmission;
    $response->forceFill(['task_id' => $approved->id, 'employee_id' => $employee->id, 'status' => 'APPROVED', 'remarks' => 'Own private response', 'submitted_at' => now(), 'revision' => 1])->save();
    $actor = Access::forUser($worker);
    $records = app(WorkRecords::class);
    $rows = $records->ownPage($actor, ['q' => 'ÀLPHA', 'status' => 'ALL', 'project' => 'ALL'], 'focus', 'asc', 1, 20, '2026-11-01');
    expect($rows->pluck('id')->all())->toBe([$late->id, $approved->id])->and($rows->first()->project->client_name)->toBe('Acme àlpha');
    $summary = $records->ownSummary($actor, '2026-11-01');
    expect($summary['queues']['OVERDUE'])->toBe(1)->and($summary['queues']['WAITING'])->toBe(1);
});

it('uses committed date and authority facts after an earlier snapshot', function (string $change) {
    $owner = cpaUser();
    $first = cpaEmployee('First');
    $second = cpaEmployee('Second');
    $worker = cpaUser($first, ['TASKS']);
    $peer = cpaUser($second, ['TASKS']);
    $leadEmployee = cpaEmployee('Lead');
    $editor = $change === 'leader-crew' ? cpaUser($leadEmployee) : cpaUser(allData: true);
    $client = cpaClient($owner, [$worker, $peer, $editor]);
    $project = cpaProject($owner, $client);
    if ($change === 'leader-crew') {
        $project->update(['manager_id' => $editor->id]);
    }
    $task = cpaTask($owner, $project, [$first]);
    $task->update(['start_date' => '2026-11-02']);
    $assignment = (array) DB::table('project_task_assignees')->where('task_id', $task->id)->first();
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $committed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$committed, $other, $change, $task, $project, $client, $assignment, $editor, $second, $leadEmployee): void {
        if ($committed || ! str_contains($sql, '`project_tasks`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $committed = true;
        $other->beginTransaction();
        $lock = $other->prepare('SELECT id FROM project_tasks WHERE id = ? FOR UPDATE');
        $lock->execute([$task->id]);
        if (in_array($change, ['dates', 'untouched-date'], true)) {
            $write = $other->prepare("UPDATE project_tasks SET due_date = '2026-11-21' WHERE id = ?");
            $write->execute([$task->id]);
            if ($change === 'untouched-date') {
                $write = $other->prepare("UPDATE project_tasks SET priority = 'HIGH' WHERE id = ?");
                $write->execute([$task->id]);
            }
        } elseif (in_array($change, ['crew', 'leader-crew'], true)) {
            if ($change === 'crew') {
                $write = $other->prepare("UPDATE project_tasks SET priority = 'HIGH' WHERE id = ?");
                $write->execute([$task->id]);
                $write = $other->prepare('DELETE FROM project_task_assignees WHERE task_id = ?');
                $write->execute([$task->id]);
            }
            $row = array_replace($assignment, ['id' => (string) Str::ulid(), 'employee_id' => $change === 'crew' ? $second->id : $leadEmployee->id]);
            $columns = implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', array_keys($row)));
            $write = $other->prepare('INSERT INTO project_task_assignees ('.$columns.') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
            $write->execute(array_values($row));
        } elseif ($change === 'access') {
            $lock = $other->prepare('SELECT id FROM project_clients WHERE id = ? FOR UPDATE');
            $lock->execute([$client->id]);
            $write = $other->prepare('DELETE FROM project_client_members WHERE client_id = ? AND user_id = ?');
            $write->execute([$client->id, $editor->id]);
        } else {
            $write = $other->prepare("UPDATE projects SET due_date = '2026-11-21' WHERE id = ?");
            $write->execute([$project->id]);
        }
        $other->commit();
    });
    DB::beginTransaction();
    DB::table('project_tasks')->where('id', $task->id)->first();
    $input = new TaskDateEdit('2026-11-04', $change === 'leader-crew' ? '2026-11-20' : '2026-11-22', '2026-11-02', '2026-11-20');
    if ($change === 'untouched-date') {
        $input = new TaskDetails('NORMAL', null, [$first->id], null, '2026-11-04', '2026-11-02', '2026-11-20');
    }
    $failure = null;
    try {
        app(UpdateTaskDetails::class)->handle(Access::forUser($editor), $project->id, $task->id, $input);
        DB::commit();
    } catch (RuntimeException $error) {
        $failure = $error;
        DB::rollBack();
    }
    expect($committed)->toBeTrue();
    if ($change === 'untouched-date') {
        expect($failure)->toBeNull()->and($task->fresh()->priority)->toBe('HIGH')->and($task->fresh()->assigneeIds())->toBe([$first->id])
            ->and($task->fresh()->start_date->format('Y-m-d'))->toBe('2026-11-04')->and($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-21');
    } elseif ($change === 'crew') {
        expect($failure)->toBeNull()->and($task->fresh()->priority)->toBe('HIGH')->and($task->fresh()->assigneeIds())->toBe([$second->id])
            ->and($task->fresh()->start_date->format('Y-m-d'))->toBe('2026-11-04')->and($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-22');
    } else {
        expect($failure)->toBeInstanceOf(RuntimeException::class)->and($task->fresh()->start_date->format('Y-m-d'))->toBe('2026-11-02')
            ->and($task->fresh()->due_date->format('Y-m-d'))->toBe($change === 'dates' ? '2026-11-21' : '2026-11-20');
    }
})->with(['dates', 'crew', 'access', 'leader-crew', 'window', 'untouched-date'])->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires real MariaDB snapshots.');
