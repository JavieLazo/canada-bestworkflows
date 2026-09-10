<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second step of the multi-assignee change: drop the single-assignee column.
 *
 * 2026_09_10_0001 copied every `project_tasks.assignee_id` into the
 * `project_task_assignees` pivot. Keeping the column as a "first assignee"
 * mirror was rejected on purpose: two sources of truth would need syncing on
 * every write path, and ScopesToActor's whole design is that the scoping
 * column is the ONLY truth — a stale mirror would silently widen or narrow
 * visibility. Nothing outside this application reads the column.
 *
 * Order matters on MySQL: the composite `tasks_assignee_status_idx` was
 * declared before the foreign key in migration 0012, so MySQL reused it to
 * back the constraint. The constraint must go first, then the index, then the
 * column; down() adds them back in the reverse order so the constraint lands
 * on that index again.
 *
 * RETRY-SAFE, STEP BY STEP. Those are three separate ALTERs on MySQL, where
 * DDL does not roll back and production migrates from a cPanel cron that can
 * be killed between any two of them. A single check on the column would wave
 * a retry into re-issuing a DROP FOREIGN KEY or DROP INDEX that already
 * happened, which MySQL refuses with 1091 "Can't DROP; check that column/key
 * exists" — and the migration could then only be finished by hand. So each
 * step has its own precondition: the constraint is dropped only while
 * getForeignKeys() still lists one on assignee_id, the index only while
 * getIndexes() still lists it, the column only while it exists, and a retry
 * resumes from wherever the last run stopped. A fresh database where 0001 ran
 * against a schema that never had the column is a no-op for the same reason.
 * On SQLite (tests) dropForeign is a table rebuild, which carries the index
 * over, so the three steps run in the same order there.
 *
 * ROLLBACK KEEPS ONE ASSIGNEE PER TASK. The column can hold a single employee,
 * so down() fills it from the pivot — the earliest membership by created_at,
 * then by row id — while the pivot still exists (0001's down() drops it right
 * after). Co-assignees beyond the first are necessarily lost on rollback, and
 * so is submitted_by. The memberships are read BEFORE the column comes back:
 * on SQLite the foreign key is re-added through a table rebuild whose DROP
 * TABLE cascades into the pivot whenever `PRAGMA foreign_keys = OFF` cannot
 * take effect (inside a transaction — which is how the test suite runs these
 * migrations), and a snapshot taken first is immune to that; MySQL alters in
 * place and does not care about the order. Only still-empty assignee_id cells
 * are written, and each re-added piece has its own check, so a repeated
 * down() is as safe as a repeated up().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_tasks', 'assignee_id')) {
            return;
        }

        if (Schema::hasForeignKey('project_tasks', ['assignee_id'])) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->dropForeign(['assignee_id']);
            });
        }

        if (Schema::hasIndex('project_tasks', 'tasks_assignee_status_idx')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->dropIndex('tasks_assignee_status_idx');
            });
        }

        Schema::table('project_tasks', function (Blueprint $t): void {
            $t->dropColumn('assignee_id');
        });
    }

    public function down(): void
    {
        // Snapshot first — see "ROLLBACK KEEPS ONE ASSIGNEE PER TASK" above.
        $firstAssignees = $this->firstAssigneePerTask();

        if (! Schema::hasColumn('project_tasks', 'assignee_id')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->char('assignee_id', 26)->nullable()->after('description');
            });
        }

        if (! Schema::hasIndex('project_tasks', 'tasks_assignee_status_idx')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->index(['assignee_id', 'status'], 'tasks_assignee_status_idx');
            });
        }

        if (! Schema::hasForeignKey('project_tasks', ['assignee_id'])) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->foreign('assignee_id')->references('id')->on('employee_profiles')->nullOnDelete();
            });
        }

        foreach ($firstAssignees as $taskId => $employeeId) {
            DB::table('project_tasks')
                ->where('id', $taskId)
                ->whereNull('assignee_id')
                ->update(['assignee_id' => $employeeId]);
        }
    }

    /**
     * Task id => employee id of that task's earliest membership.
     *
     * Ordered by created_at and then by row id (a ULID, so creation order
     * breaks a same-millisecond tie); unique() keeps the first row it meets
     * for each task. Plain query-builder reads, so it is the same on both
     * drivers.
     *
     * @return array<string, string>
     */
    private function firstAssigneePerTask(): array
    {
        if (! Schema::hasTable('project_task_assignees')) {
            return [];
        }

        return DB::table('project_task_assignees')
            ->select(['task_id', 'employee_id'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->unique('task_id')
            ->pluck('employee_id', 'task_id')
            ->all();
    }
};
