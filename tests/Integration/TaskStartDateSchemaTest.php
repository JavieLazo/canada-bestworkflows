<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, a task's planned start date is one nullable DATE
 * column added by a single retry-safe ALTER: re-running the migration on a
 * migrated schema sends no DDL, a run killed before or after its one ALTER
 * resumes to the same column, a full rollback removes it (a second rollback
 * finds nothing to drop) and a re-run restores it — and the tasks themselves
 * survive every step, only their start dates are lost to a rollback.
 *
 * The migration appends the column without `->after()`; its docblock says
 * that keeps the ADD instant on MariaDB 11.4, and this suite checks the
 * statement it sends is accepted with ALGORITHM=INSTANT. sqlite rebuilds a
 * table for a column drop, so only this suite sees the real ALTERs.
 * DatabaseMigrations rolls the whole schema back after each test, so EVERY
 * TEST ENDS MIGRATED.
 */

/** The migration — an anonymous class, so untyped here. */
function tsdschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_25_0001_add_start_date_to_project_tasks_table.php');
}

/**
 * The column as information_schema describes it, or null when absent.
 *
 * @return array{type: string, nullable: string, default: mixed}|null
 */
function tsdschemaColumn(): ?array
{
    $row = DB::selectOne(
        'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['project_tasks', 'start_date'],
    );
    // The default is checked for presence, not isset(): MySQL reports it as a real null.
    if (! is_object($row) || ! isset($row->type, $row->nullable) || ! property_exists($row, 'dflt')) {
        return null;
    }

    return ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => $row->dflt];
}

/** The column is there, a plain nullable DATE with no default of its own. */
function tsdschemaAssertApplied(): void
{
    $column = tsdschemaColumn();
    expect($column)->not->toBeNull();
    assert($column !== null);
    expect($column['type'])->toBe('date')
        ->and($column['nullable'])->toBe('YES');
    // MariaDB reports a nullable column's implicit default as the string
    // 'NULL' (MySQL as a real null): either way, no default value.
    expect($column['default'] === null || $column['default'] === 'NULL')->toBeTrue();
    expect(Schema::hasColumn('project_tasks', 'start_date'))->toBeTrue();
}

function tsdschemaAssertAbsent(): void
{
    expect(tsdschemaColumn())->toBeNull();
    expect(Schema::hasColumn('project_tasks', 'start_date'))->toBeFalse();
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function tsdschemaDdlDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
    ));
}

function tsdschemaProject(): Project
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::upper(Str::random(5)),
        'name' => 'Start date project '.Str::random(6),
        'status' => 'ACTIVE',
        'start_date' => '2026-09-01',
        'due_date' => '2026-12-31',
    ])->save();

    return $project;
}

/**
 * A task straight into the table, so no model default hides what the column
 * holds. A null `$startDate` leaves the column out of the INSERT altogether,
 * which also works while the column does not exist.
 */
function tsdschemaTask(Project $project, string $code, ?string $startDate): string
{
    $id = (string) Str::ulid();
    $row = [
        'id' => $id,
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Task '.$code,
        'status' => 'PENDING',
        'priority' => 'NORMAL',
        'due_date' => '2026-10-15',
        'assigned_at' => '2026-09-25 01:00:00.000',
        'created_at' => '2026-09-25 01:00:00.000',
        'updated_at' => '2026-09-25 01:00:00.000',
    ];
    if ($startDate !== null) {
        $row['start_date'] = $startDate;
    }
    DB::table('project_tasks')->insert($row);

    return $id;
}

it('applies the migration twice on top of a migrated schema without sending any DDL', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    // migrate:fresh has already run it through the migrator.
    tsdschemaAssertApplied();
    $migration = tsdschemaMigration();

    expect(tsdschemaDdlDuring(static function () use ($migration): void {
        $migration->up();
        $migration->up();
    }))->toBe([]);

    tsdschemaAssertApplied();
});

it('adds the column as DATE NULL in one ALTER with no AFTER clause, which MariaDB runs instantly', function () {
    $migration = tsdschemaMigration();
    $migration->down();
    tsdschemaAssertAbsent();

    $ddl = tsdschemaDdlDuring(static fn () => $migration->up());

    // The one statement a run sends: appended, never placed with AFTER.
    expect($ddl)->toBe(['alter table `project_tasks` add `start_date` date null']);
    tsdschemaAssertApplied();

    // The same statement with ALGORITHM=INSTANT is refused outright where the
    // engine would have to copy the table, so this proves the ADD is instant.
    $migration->down();
    DB::statement($ddl[0].', ALGORITHM=INSTANT');
    tsdschemaAssertApplied();
});

it('stores a start date as a plain day and leaves every task that has none null', function () {
    $project = tsdschemaProject();
    $blank = tsdschemaTask($project, 'TSK-SD-1', null);
    $planned = tsdschemaTask($project, 'TSK-SD-2', '2026-09-28');

    expect(DB::table('project_tasks')->where('id', $blank)->value('start_date'))->toBeNull();
    expect(DB::table('project_tasks')->where('id', $planned)->value('start_date'))->toBe('2026-09-28');
    // The model's cast reads the same day back.
    $task = ProjectTask::query()->findOrFail($planned);
    expect($task->start_date?->format('Y-m-d'))->toBe('2026-09-28');
    expect(ProjectTask::query()->findOrFail($blank)->start_date)->toBeNull();
});

it('removes the column on a full rollback, tolerates a second one, and restores it with every task intact', function () {
    $project = tsdschemaProject();
    $planned = tsdschemaTask($project, 'TSK-RB-1', '2026-09-28');
    $blank = tsdschemaTask($project, 'TSK-RB-2', null);
    $migration = tsdschemaMigration();

    $migration->down();
    tsdschemaAssertAbsent();

    // A rollback killed after its DROP and re-run finds nothing left to drop.
    expect(tsdschemaDdlDuring(static fn () => $migration->down()))->toBe([]);
    tsdschemaAssertAbsent();

    // The tasks, their statuses and their due dates are untouched.
    expect(DB::table('project_tasks')->whereIn('id', [$planned, $blank])->count())->toBe(2);
    expect(DB::table('project_tasks')->where('id', $planned)->value('due_date'))->toBe('2026-10-15');
    expect(DB::table('project_tasks')->where('id', $planned)->value('status'))->toBe('PENDING');

    $migration->up();
    tsdschemaAssertApplied();

    // ROLLBACK LOSES every task's start date: the restored column is blank,
    // for the task that had one as much as for the task that did not.
    expect(DB::table('project_tasks')->whereIn('id', [$planned, $blank])->pluck('start_date')->all())->toBe([null, null]);
    expect(DB::table('project_tasks')->whereIn('id', [$planned, $blank])->count())->toBe(2);
});

it('resumes a run killed on either side of its one ALTER', function () {
    $project = tsdschemaProject();
    $task = tsdschemaTask($project, 'TSK-RS-1', null);
    $migration = tsdschemaMigration();

    // Killed BEFORE the ALTER: the column is not there, and the re-run adds it.
    $migration->down();
    tsdschemaAssertAbsent();
    expect(tsdschemaDdlDuring(static fn () => $migration->up()))->toHaveCount(1);
    tsdschemaAssertApplied();

    // Killed AFTER the ALTER, before the migrator recorded it: the column is
    // there, a start date may already have been typed, and the re-run sends
    // no DDL and leaves the value alone.
    DB::table('project_tasks')->where('id', $task)->update(['start_date' => '2026-09-30']);
    expect(tsdschemaDdlDuring(static fn () => $migration->up()))->toBe([]);
    tsdschemaAssertApplied();
    expect(DB::table('project_tasks')->where('id', $task)->value('start_date'))->toBe('2026-09-30');
});
