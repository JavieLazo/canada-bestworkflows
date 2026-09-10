<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * A task used to be owned by exactly one employee through the nullable
 * `project_tasks.assignee_id` column (migration 0012). It is now a SHARED unit
 * of work with 0..N assignees, and the membership moves to this pivot. Status,
 * remarks and submitted_at stay ONE per task: any assignee may start or submit
 * the task for everyone, and the new `project_tasks.submitted_by` column
 * (added here too) records WHO last submitted, since the task row alone can no
 * longer say.
 *
 * Shape follows the payroll_run_members precedent (migration 0016): a ULID
 * primary key minted by BaseModel, two cascading foreign keys, and the short
 * explicit unique/index names declared BEFORE the foreign keys so MySQL reuses
 * them for the constraints instead of adding a second index each.
 *
 * `assigned_by` is an ACTOR column (a users id, session-supplied) — it lives
 * on the pivot row because "who assigned this person" is per assignee, and it
 * is never in the model's $fillable.
 *
 * RETRY-SAFE. Production migrates from a temporary cPanel cron with no SSH and
 * MySQL DDL does not roll back, so a run can stop between any two statements
 * and is simply run again; every step is therefore skipped when its result
 * already exists. The table is created only when absent. The `submitted_by`
 * column and its foreign key are TWO statements on MySQL and get two checks:
 * the column only when absent, the constraint only while getForeignKeys()
 * lists none on that column — one check on the column alone would wave a
 * retry past a column that was added but never constrained, and the schema
 * would silently stay that way. The backfill inserts only the pivot rows that
 * are still missing. down() mirrors this: it drops whichever half is there.
 *
 * The one window a retry does not repair is inside the create block: on MySQL
 * the pivot's unique, index and foreign keys are follow-up ALTERs to the
 * CREATE TABLE, and a retry skips a table that already exists. The table is
 * still empty at that point, so `DROP TABLE project_task_assignees` and a
 * re-run put it right.
 *
 * The backfill copies one pivot row per task that still carries an
 * `assignee_id`. The column itself is dropped by the NEXT migration
 * (2026_09_10_0002), once every reader has moved to the pivot, so a deploy
 * that stops between the two leaves a consistent database either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_task_assignees')) {
            Schema::create('project_task_assignees', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('task_id', 26);
                $t->char('employee_id', 26);

                // Actor column: the account that handed the task to THIS
                // employee. nullOnDelete like every other users FK.
                $t->unsignedBigInteger('assigned_by')->nullable();

                // Append-only: no updated_at, exactly like project_task_files.
                $t->dateTime('created_at', 3)->nullable();

                // Explicit short names, declared before the foreign keys
                // (payroll_run_members precedent).
                $t->unique(['task_id', 'employee_id'], 'pta_task_employee_unq');
                $t->index('employee_id', 'pta_employee_idx');

                $t->foreign('task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
                $t->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            });

            $this->dynamicRowFormat('project_task_assignees');
        }

        if (! Schema::hasColumn('project_tasks', 'submitted_by')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                // The assignee who last submitted — an employee, not an
                // account, so it pairs with the pivot rather than with
                // assigned_by/reviewed_by.
                $t->char('submitted_by', 26)->nullable()->after('reviewed_by');
            });
        }

        // Checked on its own, not under the column check: a run that stopped
        // between the two statements has the column and not the constraint.
        if (! Schema::hasForeignKey('project_tasks', ['submitted_by'])) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->foreign('submitted_by')->references('id')->on('employee_profiles')->nullOnDelete();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        // Symmetrical with up(): a rollback after a half-applied run drops
        // whichever half exists rather than failing on the half that does not.
        if (Schema::hasForeignKey('project_tasks', ['submitted_by'])) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->dropForeign(['submitted_by']);
            });
        }

        if (Schema::hasColumn('project_tasks', 'submitted_by')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->dropColumn('submitted_by');
            });
        }

        Schema::dropIfExists('project_task_assignees');
    }

    /**
     * One pivot row per task that still names a single assignee.
     *
     * Insert-where-not-exists: a task whose pivot row already exists (from an
     * earlier, interrupted run) is skipped, so re-running is harmless.
     */
    private function backfill(): void
    {
        if (! Schema::hasColumn('project_tasks', 'assignee_id')) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s.v');

        // Read the whole set first: inserting while paging a NOT EXISTS query
        // would shrink the result set under the cursor and skip rows.
        $tasks = DB::table('project_tasks')
            ->select(['id', 'assignee_id', 'assigned_by', 'assigned_at'])
            ->whereNotNull('assignee_id')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw('1'))
                    ->from('project_task_assignees')
                    ->whereColumn('project_task_assignees.task_id', 'project_tasks.id')
                    ->whereColumn('project_task_assignees.employee_id', 'project_tasks.assignee_id');
            })
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($tasks as $task) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'task_id' => $task->id,
                'employee_id' => $task->assignee_id,
                'assigned_by' => $task->assigned_by,
                'created_at' => $task->assigned_at ?? $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('project_task_assignees')->insert($chunk);
        }
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }
};
