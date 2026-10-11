<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three storage migrations for individual task responses roll back and
 * re-apply cleanly, survive a second up() (the cPanel cron re-running a
 * half-applied deploy), and leave every existing task in GROUP mode. A create
 * killed right after its CREATE TABLE resumes: the next up() adds every index
 * and foreign key the bare table lacks, and the one after runs no DDL at all.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: the `finally` re-runs up()
 * before anything else, then deletes the rows seeded here by key. On
 * MySQL/MariaDB (phpunit.mariadb.xml) DDL commits implicitly, so the first
 * down() ends RefreshDatabase's transaction, which can then roll back neither
 * the schema nor those rows. Laravel does notice that the connection left its
 * transaction and runs migrate:fresh before the next database test
 * (RefreshDatabase::beginDatabaseTransaction), but this file leaves the
 * schema and data as it found them without relying on that.
 *
 * Child rows are seeded only after the final up(): on sqlite adding a foreign
 * key rebuilds the table, and inside RefreshDatabase's transaction that DROP
 * TABLE cascades into children (see ProjectTaskAssigneesTest).
 *
 * The CHECK constraints exist only on MySQL/MariaDB and are asserted in
 * tests/Integration/TaskSubmissionSchemaTest.php, along with a final down().
 */

/**
 * The three migrations, oldest first — anonymous classes, so untyped here.
 *
 * @return list<mixed>
 */
function tsmigMigrations(): array
{
    return [
        require base_path('database/migrations/2026_09_24_0001_add_submission_mode_to_project_tasks_table.php'),
        require base_path('database/migrations/2026_09_24_0002_create_project_task_submissions_table.php'),
        require base_path('database/migrations/2026_09_24_0003_add_employee_id_to_project_task_files_table.php'),
    ];
}

/**
 * What a run killed straight after 0002's CREATE TABLE leaves on MySQL: the
 * columns and the primary key, with no unique, index or foreign key yet.
 */
function tsmigBareResponsesTable(): void
{
    Schema::create('project_task_submissions', function (Blueprint $t): void {
        $t->char('id', 26)->primary();
        $t->char('task_id', 26);
        $t->char('employee_id', 26);
        $t->string('status', 20);
        $t->text('remarks')->nullable();
        $t->dateTime('submitted_at', 3);
        $t->unsignedBigInteger('reviewed_by')->nullable();
        $t->text('review_remarks')->nullable();
        $t->dateTime('reviewed_at', 3)->nullable();
        $t->unsignedInteger('revision')->default(0);
        $t->dateTime('created_at', 3);
        $t->dateTime('updated_at', 3)->nullable();
    });
}

/** Whether 0002 has added every index and foreign key to the member responses table. */
function tsmigResponsesComplete(): bool
{
    return Schema::hasIndex('project_task_submissions', 'pts_task_employee_unq', 'unique')
        && Schema::hasIndex('project_task_submissions', 'pts_employee_idx')
        && Schema::hasIndex('project_task_submissions', 'pts_reviewer_idx')
        && Schema::hasForeignKey('project_task_submissions', ['task_id'])
        && Schema::hasForeignKey('project_task_submissions', ['employee_id'])
        && Schema::hasForeignKey('project_task_submissions', ['reviewed_by']);
}

function tsmigEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Ana',
        'last_name' => 'Garcia',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

it('rolls the three migrations back and re-applies them twice, leaving an existing task in GROUP mode', function () {
    [$mode, $responses, $fileOwner] = tsmigMigrations();

    // A task that will predate the mode column once down() removes it, with
    // no children yet.
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-TSMIG',
        'name' => 'Migration project',
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    $task = new ProjectTask;
    $task->forceFill([
        'code' => 'TSK-TSMIG',
        'project_id' => $project->getKey(),
        'title' => 'Existing task',
        'status' => 'IN_PROGRESS',
        'priority' => 'NORMAL',
    ])->save();

    $seeded = ['project' => $project->getKey(), 'task' => $task->getKey()];

    try {
        // Newest first, as migrate:rollback would, called directly rather
        // than with `--step`, which targets whatever migration is newest.
        $fileOwner->down();
        $responses->down();
        $mode->down();

        expect(Schema::hasColumn('project_tasks', 'submission_mode'))->toBeFalse();
        expect(Schema::hasTable('project_task_submissions'))->toBeFalse();
        expect(Schema::hasColumn('project_task_files', 'employee_id'))->toBeFalse();
        expect(Schema::hasIndex('project_task_files', 'taskfile_employee_idx'))->toBeFalse();
        expect(Schema::hasForeignKey('project_task_files', ['employee_id']))->toBeFalse();

        $mode->up();
        $responses->up();
        $fileOwner->up();

        // The retry: every step is skipped because its result already exists.
        $mode->up();
        $responses->up();
        $fileOwner->up();

        expect(Schema::hasColumn('project_tasks', 'submission_mode'))->toBeTrue();
        expect(Schema::hasTable('project_task_submissions'))->toBeTrue();
        expect(Schema::hasColumns('project_task_submissions', [
            'id', 'task_id', 'employee_id', 'status', 'remarks', 'submitted_at', 'reviewed_by',
            'review_remarks', 'reviewed_at', 'revision', 'created_at', 'updated_at',
        ]))->toBeTrue();
        expect(tsmigResponsesComplete())->toBeTrue();
        expect(Schema::hasColumn('project_task_files', 'employee_id'))->toBeTrue();
        expect(Schema::hasIndex('project_task_files', 'taskfile_employee_idx'))->toBeTrue();
        expect(Schema::hasForeignKey('project_task_files', ['employee_id']))->toBeTrue();

        // The column default is the whole backfill.
        expect(DB::table('project_tasks')->where('id', $task->getKey())->value('submission_mode'))->toBe('GROUP');
        expect($task->fresh()?->submission_mode)->toBe('GROUP');

        // Children only now, against the final schema.
        $ana = tsmigEmployee('TSMIG-001');
        $ben = tsmigEmployee('TSMIG-002');
        $seeded['employees'] = [$ana->getKey(), $ben->getKey()];
        $task->syncAssignees([$ana->getKey(), $ben->getKey()], null);

        $response = new ProjectTaskSubmission;
        $response->fill([
            'task_id' => $task->getKey(),
            'employee_id' => $ana->getKey(),
            'status' => 'SUBMITTED',
            'remarks' => 'My part is done.',
            'submitted_at' => '2026-09-24 01:00:00.000',
            'revision' => 1,
        ])->save();
        $seeded['response'] = $response->getKey();

        $file = new ProjectTaskFile;
        $file->fill([
            'task_id' => $task->getKey(),
            'kind' => 'SUBMISSION',
            'file_name' => 'evidence.pdf',
            'mime' => 'application/pdf',
            'size' => 10,
            'path' => 'task-files/tsmig.pdf',
        ]);
        $file->employee_id = $ana->getKey();
        $file->save();
        $seeded['file'] = $file->getKey();

        expect($task->submissions()->pluck('employee_id')->all())->toBe([$ana->getKey()]);
        expect($response->fresh()?->revision)->toBe(1);
        expect($file->fresh()?->employee_id)->toBe($ana->getKey());

        // One response per member: the unique index refuses a second row.
        $duplicate = new ProjectTaskSubmission;
        $duplicate->fill([
            'task_id' => $task->getKey(),
            'employee_id' => $ana->getKey(),
            'status' => 'SUBMITTED',
            'submitted_at' => '2026-09-24 02:00:00.000',
        ]);

        expect(fn () => $duplicate->save())->toThrow(QueryException::class);
        expect(ProjectTaskSubmission::query()->where('task_id', $task->getKey())->count())->toBe(1);
    } finally {
        // Re-migrate FIRST, even when an expectation failed between the
        // down() and up() calls above. Every step is guarded, so on a passing
        // run these are no-ops.
        $mode->up();
        $responses->up();
        $fileOwner->up();

        // DDL has already committed these rows on MySQL/MariaDB, where the
        // rollback can no longer remove them, so remove them by key.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (isset($seeded['response'])) {
                ProjectTaskSubmission::query()->whereKey($seeded['response'])->delete();
            }
            if (isset($seeded['file'])) {
                ProjectTaskFile::query()->whereKey($seeded['file'])->delete();
            }
            ProjectTaskAssignee::query()->where('task_id', $seeded['task'])->delete();
            ProjectTask::query()->whereKey($seeded['task'])->delete();
            Project::query()->whereKey($seeded['project'])->delete();
            if (isset($seeded['employees'])) {
                EmployeeProfile::query()->whereKey($seeded['employees'])->delete();
            }
        }
    }
});

it('resumes a create killed after its CREATE TABLE, and a second run changes nothing', function () {
    [, $responses] = tsmigMigrations();

    try {
        $responses->down();
        tsmigBareResponsesTable();

        expect(Schema::hasTable('project_task_submissions'))->toBeTrue();
        expect(Schema::hasIndex('project_task_submissions', 'pts_task_employee_unq'))->toBeFalse();
        expect(Schema::hasIndex('project_task_submissions', 'pts_employee_idx'))->toBeFalse();
        expect(Schema::hasIndex('project_task_submissions', 'pts_reviewer_idx'))->toBeFalse();
        expect(Schema::getForeignKeys('project_task_submissions'))->toBe([]);

        // The retry skips the table and adds each missing piece.
        $responses->up();

        expect(tsmigResponsesComplete())->toBeTrue();

        // And the one after finds every piece in place: no CREATE, ALTER or
        // DROP reaches the database, bar the idempotent ROW_FORMAT restatement
        // on MySQL/MariaDB.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $responses->up();
        DB::disableQueryLog();

        $ddl = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1
                && ! str_contains($sql, 'ROW_FORMAT=DYNAMIC'),
        ));
        expect($ddl)->toBe([]);
        expect(tsmigResponsesComplete())->toBeTrue();
    } finally {
        // Ends migrated whatever failed above: every step is guarded.
        $responses->up();
    }
});
