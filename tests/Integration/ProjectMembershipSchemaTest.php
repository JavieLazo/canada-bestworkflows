<?php

declare(strict_types=1);

use App\Models\ProjectMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Application\TaskDateEdit;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(function (): void {
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'ems_testing'], true)) {
        throw new RuntimeException('Only the guarded synthetic schema may be cleaned.');
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if (Schema::hasTable('project_members')) {
        DB::table('project_members')->delete();
    }
    DB::table('project_tasks')->delete();
    DB::table('project_task_groups')->delete();
    DB::table('projects')->delete();
});

it('reruns membership schema preserves existing work and refuses a destructive rollback', function () {
    $owner = cpaUser();
    $member = cpaUser(cpaEmployee());
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    cpaTask($owner, $project, [$member->employee]);
    $tables = ['projects', 'project_tasks', 'project_task_assignees', 'project_task_submissions', 'project_task_files', 'task_comments', 'project_clients', 'project_client_members', 'project_task_groups'];
    $snapshot = static fn (): array => array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    $migration = require database_path('migrations/2026_11_02_0001_add_project_members.php');
    $migration->up();
    $migration->up();
    expect($snapshot())->toBe($before)->and(ProjectMember::query()->count())->toBe(0);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $member->id, 'EDITOR');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Keep project memberships');
    $row = (array) DB::table('project_members')->first();
    expect(fn () => DB::table('project_members')->insert(array_replace($row, ['id' => (string) Str::ulid()])))->toThrow(QueryException::class);
    expect(fn () => DB::table('project_members')->insert(array_replace($row, ['id' => (string) Str::ulid(), 'project_id' => (string) Str::ulid()])))->toThrow(QueryException::class);
    DB::table('project_members')->delete();
    $migration->down();
    $migration->down();
    expect(Schema::hasTable('project_members'))->toBeFalse()->and($snapshot())->toBe($before);
    $migration->up();
    $migration->up();
    expect($snapshot())->toBe($before);
});

it('reads scoped summaries workloads and team under strict grouping', function () {
    $owner = cpaUser();
    $member = cpaUser(cpaEmployee());
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    cpaTask($owner, $project, [$member->employee]);
    cpaTask($owner, $project, []);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $member->id, 'VIEWER');
    $actor = Access::forUser($member);
    $summary = app(WorkSummary::class);
    expect($summary->overview($actor, '2026-11-02')['totals']->total)->toBe(2)
        ->and($summary->workload($actor, null, '2026-11-02', 8)->first()['active'])->toBe(1)
        ->and(app(ProjectPeople::class)->members($actor, $project->id)->first()->role)->toBe('VIEWER');
});

it('checks current delegated role client access and handed-in work behind an earlier snapshot', function (string $change) {
    $owner = cpaUser();
    $editor = cpaUser();
    $first = cpaUser(cpaEmployee('First'), ['TASKS']);
    $second = cpaUser(cpaEmployee('Second'), ['TASKS']);
    $client = cpaClient($owner, [$editor, $first, $second]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$first->employee, $second->employee], 'INDIVIDUAL');
    $task->update(['status' => 'IN_PROGRESS', 'start_date' => '2026-11-02']);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $committed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$committed, $other, $change, $task, $project, $client, $editor, $first): void {
        if ($committed || ! str_contains($sql, '`project_tasks`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $committed = true;
        $other->beginTransaction();
        if ($change === 'evidence') {
            $write = $other->prepare('SELECT id FROM project_tasks WHERE id = ? FOR UPDATE');
            $write->execute([$task->id]);
            $write = $other->prepare("INSERT INTO project_task_submissions (id, task_id, employee_id, status, remarks, submitted_at, revision, created_at, updated_at) VALUES (?, ?, ?, 'SUBMITTED', 'Preserve committed response', NOW(), 1, NOW(), NOW())");
            $write->execute([(string) Str::ulid(), $task->id, $first->employee_id]);
        } else {
            $write = $other->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE');
            $write->execute([$project->id]);
            if ($change === 'role') {
                $write = $other->prepare("UPDATE project_members SET role = 'VIEWER' WHERE project_id = ? AND user_id = ?");
                $write->execute([$project->id, $editor->id]);
            } else {
                $write = $other->prepare('SELECT id FROM project_clients WHERE id = ? FOR UPDATE');
                $write->execute([$client->id]);
                $write = $other->prepare('DELETE FROM project_client_members WHERE client_id = ? AND user_id = ?');
                $write->execute([$client->id, $editor->id]);
            }
        }
        $other->commit();
    });
    DB::beginTransaction();
    DB::table('project_members')->where('project_id', $project->id)->first();
    $input = $change === 'evidence'
        ? new TaskDetails('NORMAL', null, [$second->employee_id])
        : new TaskDateEdit('2026-11-04', '2026-11-22', '2026-11-02', '2026-11-20');
    $failure = null;
    try {
        app(UpdateTaskDetails::class)->handle(Access::forUser($editor), $project->id, $task->id, $input);
        DB::commit();
    } catch (RuntimeException $error) {
        $failure = $error;
        DB::rollBack();
    }
    expect($committed)->toBeTrue()->and($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($task->fresh()->start_date->format('Y-m-d'))->toBe('2026-11-02')
        ->and($task->fresh()->assigneeIds())->toHaveCount(2);
    if ($change === 'evidence') {
        expect(DB::table('project_task_submissions')->where('task_id', $task->id)->value('remarks'))->toBe('Preserve committed response');
    }
})->with(['role', 'client', 'evidence'])->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires real MariaDB snapshots.');
