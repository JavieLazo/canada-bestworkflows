<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The planned-start migration (2026_09_25_0001) rolls back and re-applies
 * cleanly, and survives a repeated up() or down() — the cPanel cron re-running
 * a half-applied deploy. The column comes back as a nullable DATE, every task
 * that existed before it reads null, and ROLLBACK LOSES a start date that was
 * typed in (the migration's docblock says so; this pins it).
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up()
 * before anything else, then deletes the rows seeded here by key. On
 * MySQL/MariaDB DDL commits implicitly, so the down() ends RefreshDatabase's
 * transaction, which can then roll back neither the schema nor those rows
 * (the same pattern as TaskSubmissionMigrationsTest).
 *
 * The seeded task has no children: on sqlite dropping or adding a column may
 * rebuild the table, and inside RefreshDatabase's transaction that DROP TABLE
 * cascades into child rows (see ProjectTaskAssigneesTest).
 */

/** The migration under test — an anonymous class, so untyped here. */
function tsdmigMigration(): mixed
{
    return require base_path('database/migrations/2026_09_25_0001_add_start_date_to_project_tasks_table.php');
}

/**
 * A project and a task seeded straight through the models, with an optional
 * start date on the task.
 *
 * @return array{project: string, task: string}
 */
function tsdmigSeed(string $suffix, ?string $startDate): array
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-TSDMIG-'.$suffix,
        'name' => 'Start date migration '.$suffix,
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => 'TSK-TSDMIG-'.$suffix,
        'project_id' => $project->getKey(),
        'title' => 'Existing task '.$suffix,
        'status' => 'IN_PROGRESS',
        'priority' => 'NORMAL',
    ], $startDate === null ? [] : ['start_date' => $startDate]))->save();

    return ['project' => (string) $project->getKey(), 'task' => (string) $task->getKey()];
}

/**
 * Removes the seeded rows by key where DDL has already committed them.
 *
 * @param  array<string, array{project: string, task: string}>  $seeded
 */
function tsdmigCleanup(array $seeded): void
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }
    foreach ($seeded as $rows) {
        ProjectTask::query()->whereKey($rows['task'])->delete();
        Project::query()->whereKey($rows['project'])->delete();
    }
}

/**
 * The start_date column as the schema reports it, or null when it is absent.
 *
 * @return array<string, mixed>|null
 */
function tsdmigColumn(): ?array
{
    foreach (Schema::getColumns('project_tasks') as $column) {
        if ($column['name'] === 'start_date') {
            return $column;
        }
    }

    return null;
}

it('rolls the start date back and re-applies it twice, leaving an existing task with no start date', function () {
    $migration = tsdmigMigration();
    $seeded = [];

    try {
        // Two tasks that predate the rollback: one never had a start, one had
        // a start typed in — which the rollback throws away.
        $seeded['blank'] = tsdmigSeed('A', null);
        $seeded['planned'] = tsdmigSeed('B', '2026-02-10');
        expect((string) DB::table('project_tasks')->where('id', $seeded['planned']['task'])->value('start_date'))->toStartWith('2026-02-10');

        $migration->down();
        expect(Schema::hasColumn('project_tasks', 'start_date'))->toBeFalse();

        // A repeated rollback finds nothing to drop and does nothing.
        $migration->down();
        expect(Schema::hasColumn('project_tasks', 'start_date'))->toBeFalse();

        $migration->up();
        // The retry: the column already exists, so the step is skipped.
        $migration->up();

        expect(Schema::hasColumn('project_tasks', 'start_date'))->toBeTrue();
        $column = tsdmigColumn();
        expect($column)->not->toBeNull()
            ->and(strtolower((string) $column['type_name']))->toBe('date')
            ->and($column['nullable'])->toBeTrue()
            // No default: sqlite reports null, MariaDB's information_schema
            // reports the literal string 'NULL' for a nullable column without
            // one (COLUMN_DEFAULT, since 10.2.7) — both mean "none".
            ->and(in_array($column['default'], [null, 'NULL'], true))->toBeTrue();

        // No backfill: both existing tasks read "no start date yet", the
        // planned one included — ROLLBACK LOSES every task start date.
        foreach ($seeded as $rows) {
            expect(DB::table('project_tasks')->where('id', $rows['task'])->value('start_date'))->toBeNull();
            expect(ProjectTask::query()->findOrFail($rows['task'])->start_date)->toBeNull();
        }

        // The rest of the row is untouched by the drop and the re-add.
        $planned = ProjectTask::query()->findOrFail($seeded['planned']['task']);
        expect($planned->status)->toBe('IN_PROGRESS')
            ->and($planned->title)->toBe('Existing task B');

        // And the column takes a date again once it is back.
        $planned->forceFill(['start_date' => '2026-03-05'])->save();
        expect($planned->fresh()?->start_date?->format('Y-m-d'))->toBe('2026-03-05');
    } finally {
        // Re-migrate FIRST, even when an expectation failed between the
        // down() and up() calls above. The step is guarded, so on a passing
        // run this is a no-op.
        $migration->up();
        tsdmigCleanup($seeded);
    }
});

it('runs no DDL on an up() that finds the column already there', function () {
    $migration = tsdmigMigration();

    try {
        expect(Schema::hasColumn('project_tasks', 'start_date'))->toBeTrue();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $migration->up();
        DB::disableQueryLog();

        $ddl = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
        ));
        expect($ddl)->toBe([]);
        expect(Schema::hasColumn('project_tasks', 'start_date'))->toBeTrue();
    } finally {
        $migration->up();
    }
});
