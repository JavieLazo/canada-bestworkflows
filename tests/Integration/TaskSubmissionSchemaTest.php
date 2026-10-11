<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the storage for individual task responses is
 * retry-safe step by step, its two CHECK constraints exist exactly once and
 * refuse bad values, a full rollback removes every piece and a re-run
 * restores it, and the foreign keys cascade or null as the migrations say. A
 * create killed after its CREATE TABLE resumes with every index, foreign key
 * and CHECK, each constraint reusing its declared index rather than adding one.
 *
 * These are the repo's first CHECKs added through an information_schema
 * guard, and sqlite never creates a CHECK, so only this suite proves them.
 * DatabaseMigrations rolls the whole schema back after each test, so EVERY
 * TEST ENDS MIGRATED: the rollback then runs each down() against the state
 * it expects.
 */

/**
 * The three migrations, oldest first — anonymous classes, so untyped here.
 *
 * @return list<mixed>
 */
function tschemaMigrations(): array
{
    return [
        require base_path('database/migrations/2026_09_24_0001_add_submission_mode_to_project_tasks_table.php'),
        require base_path('database/migrations/2026_09_24_0002_create_project_task_submissions_table.php'),
        require base_path('database/migrations/2026_09_24_0003_add_employee_id_to_project_task_files_table.php'),
    ];
}

/** How many CHECK constraints of this name the table carries. */
function tschemaChecks(string $table, string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$table, $name],
    );

    return is_object($row) && isset($row->n) ? (int) $row->n : 0;
}

/**
 * The table's index names, sorted — lower-cased by the schema builder.
 *
 * @return list<string>
 */
function tschemaIndexNames(string $table): array
{
    $names = array_map(static fn (array $index): string => $index['name'], Schema::getIndexes($table));
    sort($names);

    return $names;
}

/** Every piece the three migrations add, present or absent together. */
function tschemaAssertApplied(bool $applied): void
{
    expect(Schema::hasColumn('project_tasks', 'submission_mode'))->toBe($applied);
    expect(tschemaChecks('project_tasks', 'tasks_submission_mode_chk'))->toBe($applied ? 1 : 0);

    expect(Schema::hasTable('project_task_submissions'))->toBe($applied);
    expect(tschemaChecks('project_task_submissions', 'pts_status_chk'))->toBe($applied ? 1 : 0);

    expect(Schema::hasColumn('project_task_files', 'employee_id'))->toBe($applied);
    expect(Schema::hasIndex('project_task_files', 'taskfile_employee_idx'))->toBe($applied);
    expect(Schema::hasForeignKey('project_task_files', ['employee_id']))->toBe($applied);

    if ($applied) {
        expect(Schema::hasIndex('project_task_submissions', 'pts_task_employee_unq', 'unique'))->toBeTrue();
        expect(Schema::hasIndex('project_task_submissions', 'pts_employee_idx'))->toBeTrue();
        expect(Schema::hasIndex('project_task_submissions', 'pts_reviewer_idx'))->toBeTrue();
        expect(Schema::hasForeignKey('project_task_submissions', ['task_id']))->toBeTrue();
        expect(Schema::hasForeignKey('project_task_submissions', ['employee_id']))->toBeTrue();
        expect(Schema::hasForeignKey('project_task_submissions', ['reviewed_by']))->toBeTrue();
    }
}

function tschemaEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Synthetic',
        'last_name' => $code,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

function tschemaTask(Project $project, string $code): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill([
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Task '.$code,
        'status' => 'IN_PROGRESS',
        'priority' => 'NORMAL',
    ])->save();

    return $task;
}

function tschemaProject(): Project
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::upper(Str::random(5)),
        'name' => 'Schema project',
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    return $project;
}

/** A member's response row, straight into the table so no model default hides a bad value. */
function tschemaResponse(ProjectTask $task, EmployeeProfile $member, string $status, ?int $reviewedBy = null): string
{
    $id = (string) Str::ulid();
    DB::table('project_task_submissions')->insert([
        'id' => $id,
        'task_id' => $task->getKey(),
        'employee_id' => $member->getKey(),
        'status' => $status,
        'submitted_at' => '2026-09-24 01:00:00.000',
        'reviewed_by' => $reviewedBy,
        'revision' => 1,
        'created_at' => '2026-09-24 01:00:00.000',
    ]);

    return $id;
}

it('applies each migration twice on top of a migrated schema, with each CHECK exactly once', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    // migrate:fresh has already run all three through the migrator.
    tschemaAssertApplied(true);

    foreach (tschemaMigrations() as $migration) {
        $migration->up();
        $migration->up();
    }

    tschemaAssertApplied(true);
});

it('refuses a submission mode or a response status outside the literal lists', function () {
    $task = tschemaTask(tschemaProject(), 'TSK-CHK-1');
    $ana = tschemaEmployee('CHK-001');

    expect(DB::table('project_tasks')->where('id', $task->getKey())->value('submission_mode'))->toBe('GROUP');
    expect(fn () => DB::table('project_tasks')->where('id', $task->getKey())->update(['submission_mode' => 'X']))
        ->toThrow(QueryException::class, 'tasks_submission_mode_chk');

    DB::table('project_tasks')->where('id', $task->getKey())->update(['submission_mode' => 'INDIVIDUAL']);
    expect(DB::table('project_tasks')->where('id', $task->getKey())->value('submission_mode'))->toBe('INDIVIDUAL');

    // "Not submitted" is the absence of a row, never a stored NONE.
    expect(fn () => tschemaResponse($task, $ana, 'BOGUS'))->toThrow(QueryException::class, 'pts_status_chk');
    expect(fn () => tschemaResponse($task, $ana, 'NONE'))->toThrow(QueryException::class, 'pts_status_chk');
    expect(ProjectTaskSubmission::query()->count())->toBe(0);

    $id = tschemaResponse($task, $ana, 'SUBMITTED');
    foreach (['RETURNED', 'APPROVED'] as $status) {
        DB::table('project_task_submissions')->where('id', $id)->update(['status' => $status]);
        expect(DB::table('project_task_submissions')->where('id', $id)->value('status'))->toBe($status);
    }
    expect(fn () => DB::table('project_task_submissions')->where('id', $id)->update(['status' => 'COMPLETED']))
        ->toThrow(QueryException::class, 'pts_status_chk');

    // One row per member on a task.
    expect(fn () => tschemaResponse($task, $ana, 'SUBMITTED'))->toThrow(QueryException::class, 'pts_task_employee_unq');
});

it('removes every piece on a full rollback, tolerates a second one, and restores them all', function () {
    [$mode, $responses, $fileOwner] = tschemaMigrations();

    $fileOwner->down();
    $responses->down();
    $mode->down();

    tschemaAssertApplied(false);

    // A rollback killed and re-run finds nothing left to drop.
    $fileOwner->down();
    $responses->down();
    $mode->down();

    tschemaAssertApplied(false);

    $mode->up();
    $responses->up();
    $fileOwner->up();

    tschemaAssertApplied(true);
});

it('resumes each migration from a run killed between its statements', function () {
    [$mode, $responses, $fileOwner] = tschemaMigrations();

    // 0001: the column landed, the CHECK did not.
    DB::statement('ALTER TABLE `project_tasks` DROP CONSTRAINT `tasks_submission_mode_chk`');
    expect(Schema::hasColumn('project_tasks', 'submission_mode'))->toBeTrue();
    expect(tschemaChecks('project_tasks', 'tasks_submission_mode_chk'))->toBe(0);
    $mode->up();
    expect(tschemaChecks('project_tasks', 'tasks_submission_mode_chk'))->toBe(1);

    // 0002: the table landed, the CHECK did not.
    DB::statement('ALTER TABLE `project_task_submissions` DROP CONSTRAINT `pts_status_chk`');
    expect(tschemaChecks('project_task_submissions', 'pts_status_chk'))->toBe(0);
    $responses->up();
    expect(tschemaChecks('project_task_submissions', 'pts_status_chk'))->toBe(1);

    // 0003: the column and its index landed, the foreign key did not.
    DB::statement('ALTER TABLE `project_task_files` DROP FOREIGN KEY `project_task_files_employee_id_foreign`');
    expect(Schema::hasIndex('project_task_files', 'taskfile_employee_idx'))->toBeTrue();
    expect(Schema::hasForeignKey('project_task_files', ['employee_id']))->toBeFalse();
    $fileOwner->up();
    expect(Schema::hasForeignKey('project_task_files', ['employee_id']))->toBeTrue();

    // 0003 again: only the column landed.
    DB::statement('ALTER TABLE `project_task_files` DROP FOREIGN KEY `project_task_files_employee_id_foreign`');
    DB::statement('ALTER TABLE `project_task_files` DROP INDEX `taskfile_employee_idx`');
    expect(Schema::hasColumn('project_task_files', 'employee_id'))->toBeTrue();
    $fileOwner->up();

    // And a rollback from a half-applied state drops what is there.
    DB::statement('ALTER TABLE `project_tasks` DROP CONSTRAINT `tasks_submission_mode_chk`');
    DB::statement('ALTER TABLE `project_task_files` DROP FOREIGN KEY `project_task_files_employee_id_foreign`');
    $fileOwner->down();
    $mode->down();
    expect(Schema::hasColumn('project_task_files', 'employee_id'))->toBeFalse();
    expect(Schema::hasColumn('project_tasks', 'submission_mode'))->toBeFalse();
    $mode->up();
    $fileOwner->up();

    tschemaAssertApplied(true);
});

it('resumes the responses table from a create killed after its CREATE TABLE', function () {
    [, $responses] = tschemaMigrations();

    // Strip the migrated table back to what that run leaves: the columns and
    // the primary key. The foreign keys go first, because MariaDB will not
    // drop an index a constraint still uses.
    foreach (['task_id', 'employee_id', 'reviewed_by'] as $column) {
        DB::statement("ALTER TABLE `project_task_submissions` DROP FOREIGN KEY `project_task_submissions_{$column}_foreign`");
    }
    foreach (['pts_task_employee_unq', 'pts_employee_idx', 'pts_reviewer_idx'] as $index) {
        DB::statement("ALTER TABLE `project_task_submissions` DROP INDEX `{$index}`");
    }
    DB::statement('ALTER TABLE `project_task_submissions` DROP CONSTRAINT `pts_status_chk`');

    expect(tschemaIndexNames('project_task_submissions'))->toBe(['primary']);
    expect(Schema::getForeignKeys('project_task_submissions'))->toBe([]);
    expect(tschemaChecks('project_task_submissions', 'pts_status_chk'))->toBe(0);

    $responses->up();

    tschemaAssertApplied(true);
    // The indexes landed before the constraints, so MariaDB reused them and
    // added no index of its own for any foreign key.
    $declared = ['primary', 'pts_employee_idx', 'pts_reviewer_idx', 'pts_task_employee_unq'];
    expect(tschemaIndexNames('project_task_submissions'))->toBe($declared);

    // A second run finds every piece in place: the only DDL it sends is the
    // idempotent ROW_FORMAT restatement.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $responses->up();
    DB::disableQueryLog();

    $ddl = array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
    ));
    expect($ddl)->toBe(['ALTER TABLE `project_task_submissions` ROW_FORMAT=DYNAMIC']);
    tschemaAssertApplied(true);
    expect(tschemaIndexNames('project_task_submissions'))->toBe($declared);
});

it('cascades member responses with their task and member, and nulls the reviewer and the evidence owner', function () {
    $project = tschemaProject();
    $shared = tschemaTask($project, 'TSK-FK-1');
    $other = tschemaTask($project, 'TSK-FK-2');
    $ana = tschemaEmployee('FK-001');
    $ben = tschemaEmployee('FK-002');
    $reviewer = User::factory()->create();

    $anaShared = tschemaResponse($shared, $ana, 'APPROVED', (int) $reviewer->getKey());
    $benShared = tschemaResponse($shared, $ben, 'SUBMITTED');
    $anaOther = tschemaResponse($other, $ana, 'SUBMITTED');

    $evidence = new ProjectTaskFile;
    $evidence->fill([
        'task_id' => $shared->getKey(),
        'kind' => 'SUBMISSION',
        'file_name' => 'ana.pdf',
        'mime' => 'application/pdf',
        'size' => 10,
        'path' => 'task-files/tschema-ana.pdf',
    ]);
    $evidence->employee_id = $ana->getKey();
    $evidence->save();

    // An account going away leaves the decision in place, unattributed.
    DB::table('users')->where('id', $reviewer->getKey())->delete();
    expect(DB::table('project_task_submissions')->where('id', $anaShared)->value('reviewed_by'))->toBeNull();
    expect(DB::table('project_task_submissions')->where('id', $anaShared)->value('status'))->toBe('APPROVED');

    // A task takes its responses with it, and only its own.
    DB::table('project_tasks')->where('id', $other->getKey())->delete();
    expect(DB::table('project_task_submissions')->where('id', $anaOther)->exists())->toBeFalse();
    expect(DB::table('project_task_submissions')->where('id', $anaShared)->exists())->toBeTrue();

    // An employee takes their responses with them; their evidence stays, unowned.
    DB::table('employee_profiles')->where('id', $ana->getKey())->delete();
    expect(DB::table('project_task_submissions')->where('id', $anaShared)->exists())->toBeFalse();
    expect(DB::table('project_task_submissions')->where('id', $benShared)->exists())->toBeTrue();
    expect(DB::table('project_task_files')->where('id', $evidence->getKey())->exists())->toBeTrue();
    expect(DB::table('project_task_files')->where('id', $evidence->getKey())->value('employee_id'))->toBeNull();
});
