<?php

declare(strict_types=1);

use App\Models\ProjectAvailability;
use App\Models\ProjectTaskEffort;
use App\Modules\WorkManagement\Application\Commands\SaveProjectAvailability;
use App\Modules\WorkManagement\Application\Commands\SaveTaskEffort;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Queries\ProjectCapacity;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(function (): void {
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'ems_testing'], true)) {
        throw new RuntimeException('Only the guarded synthetic capacity records may be cleaned.');
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (['project_task_efforts', 'project_availability', 'project_cost_categories', 'project_finances', 'project_members', 'project_tasks', 'projects'] as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }
});

it('reruns and reverses empty capacity schema preserves prior work and finance and refuses retained planning rollback', function () {
    $employee = cpaEmployee();
    $owner = cpaUser($employee);
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, [$employee]);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $tables = ['projects', 'project_tasks', 'project_task_assignees', 'project_finances', 'project_cost_categories', 'project_clients', 'project_client_members', 'users'];
    $snapshot = static fn (): array => array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    $migration = require database_path('migrations/2026_11_03_0002_add_project_capacity.php');
    $migration->down();
    $migration->down();
    $migration->up();
    $migration->up();
    expect($snapshot())->toBe($before);
    app(SaveProjectAvailability::class)->handle($actor, '2026-11-02', '30', true, 0);
    app(SaveTaskEffort::class)->handle($actor, $task->id, $employee->id, '2026-11-02', '12.34', 0);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Keep capacity');
    $migration->up();
    expect(ProjectAvailability::query()->sole()->hours_hundredths)->toBe(3000)->and(ProjectTaskEffort::query()->sole()->hours_hundredths)->toBe(1234);
    $row = (array) DB::table('project_availability')->first();
    expect(fn () => DB::table('project_availability')->insert(array_replace($row, ['id' => (string) Str::ulid()])))->toThrow(QueryException::class);
    $row = (array) DB::table('project_task_efforts')->first();
    expect(fn () => DB::table('project_task_efforts')->insert(array_replace($row, ['id' => (string) Str::ulid()])))->toThrow(QueryException::class);
});

it('honors current client editor crew and effort revisions behind a prior planning snapshot', function (string $change) {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $editor = cpaUser();
    $client = cpaClient($owner, [$worker, $editor]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee]);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    app(SaveTaskEffort::class)->handle(Access::forUser($owner), $task->id, $employee->id, '2026-11-02', '10', 0);
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $committed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$committed, $other, $change, $task, $project, $client, $editor, $employee): void {
        if ($committed || ! str_contains($sql, 'from `project_tasks`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $committed = true;
        $other->beginTransaction();
        foreach ([['SELECT id FROM project_tasks WHERE id = ? FOR UPDATE', $task->id], ['SELECT id FROM projects WHERE id = ? FOR UPDATE', $project->id]] as [$statement, $id]) {
            $lock = $other->prepare($statement);
            $lock->execute([$id]);
        }
        if ($change === 'client') {
            $statement = $other->prepare('DELETE FROM project_client_members WHERE client_id = ? AND user_id = ?');
            $statement->execute([$client->id, $editor->id]);
        } elseif ($change === 'editor') {
            $statement = $other->prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?');
            $statement->execute([$project->id, $editor->id]);
        } elseif ($change === 'crew') {
            $statement = $other->prepare('DELETE FROM project_task_assignees WHERE task_id = ? AND employee_id = ?');
            $statement->execute([$task->id, $employee->id]);
        } else {
            $statement = $other->prepare('UPDATE project_task_efforts SET hours_hundredths = 2000, revision = revision + 1 WHERE task_id = ?');
            $statement->execute([$task->id]);
        }
        $other->commit();
    });
    $actor = Access::forUser($change === 'crew' ? $worker : $editor);
    DB::beginTransaction();
    DB::table('project_task_efforts')->get();
    DB::table('project_members')->get();
    $failure = null;
    try {
        app(SaveTaskEffort::class)->handle($actor, $task->id, $employee->id, '2026-11-02', '30', 1);
        DB::commit();
    } catch (Throwable $error) {
        $failure = $error;
        DB::rollBack();
    }
    expect($committed)->toBeTrue()->and($failure)->not->toBeNull()
        ->and(ProjectTaskEffort::query()->sole()->hours_hundredths)->toBe($change === 'revision' ? 2000 : 1000);
})->with(['client', 'editor', 'crew', 'revision'])->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires MariaDB snapshots.');

it('refuses stale personal availability revisions behind a prior snapshot', function () {
    $employee = cpaEmployee();
    $owner = cpaUser($employee);
    $actor = Access::forUser($owner);
    app(SaveProjectAvailability::class)->handle($actor, '2026-11-02', '30', true, 0);
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $committed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$committed, $other, $employee): void {
        if ($committed || ! str_contains($sql, 'from `project_availability`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $committed = true;
        $statement = $other->prepare('UPDATE project_availability SET hours_hundredths = 2000, shared = 0, revision = revision + 1 WHERE employee_id = ?');
        $statement->execute([$employee->id]);
    });
    DB::beginTransaction();
    DB::table('project_availability')->get();
    $failure = null;
    try {
        app(SaveProjectAvailability::class)->handle($actor, '2026-11-02', '40', true, 1);
        DB::commit();
    } catch (Throwable $error) {
        $failure = $error;
        DB::rollBack();
    }
    expect($committed)->toBeTrue()->and($failure)->toBeInstanceOf(ValidationException::class)
        ->and(ProjectAvailability::query()->sole()->hours_hundredths)->toBe(2000)->and(ProjectAvailability::query()->sole()->shared)->toBeFalse();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires MariaDB snapshots.');

it('keeps calendar and summary keys canonical after case insensitive identifier lookups', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $task = cpaTask($owner, $project, [$employee]);
    $actor = Access::forUser($owner);
    app(SaveTaskEffort::class)->handle($actor, strtolower($task->id), strtolower($employee->id), '2026-11-02', '1', 0);
    $queries = new ProjectCapacity;
    $calendar = $queries->people($actor, $project->id, false, '2026-11-02')->first()->weeks['2026-11-02']['planned'];
    expect($calendar)->toBe($queries->totals($actor, $project->id, false, '2026-11-02')->plannedHundredths)
        ->and(ProjectTaskEffort::query()->sole()->task_id)->toBe($task->id)
        ->and(ProjectTaskEffort::query()->sole()->employee_id)->toBe($employee->id);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires case insensitive MariaDB identifiers.');
