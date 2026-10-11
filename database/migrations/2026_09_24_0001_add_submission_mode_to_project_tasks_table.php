<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — copems has no counterpart; `project_tasks` gains a submission
 * mode.
 *
 * A task shared by several assignees can take ONE team response (GROUP: any
 * assignee submits for everyone, and the response lives on the task row as it
 * always has) or ONE RESPONSE PER ASSIGNEE (INDIVIDUAL: each member's response
 * is its own `project_task_submissions` row, created by 2026_09_24_0002). This
 * column records which.
 *
 * `varchar(20) NOT NULL DEFAULT 'GROUP'`, so every existing task reads GROUP —
 * exactly how it behaved before the column existed. There is no backfill.
 * The column is appended without `->after()`, which keeps the ADD instant on
 * MariaDB 11.4 instead of rebuilding the table.
 *
 * `tasks_submission_mode_chk` pins the two literal values on MySQL/MariaDB;
 * sqlite (the test suite) never creates it, and every writer validates the
 * mode itself. This is the first CHECK here that is added to an EXISTING
 * table, and therefore the first that must survive a retry: `ADD CONSTRAINT`
 * with a name that already exists fails, so the step first asks
 * information_schema.TABLE_CONSTRAINTS whether the constraint is there.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can be killed between any two statements, and MySQL DDL does not roll
 * back, so a half-applied run is simply run again. The column and the CHECK
 * are two ALTERs and get two guards: the column only while hasColumn() is
 * false, the CHECK only while information_schema lists no constraint of that
 * name. A run killed after the column resumes at the CHECK. down() mirrors
 * this: the CHECK goes first, so the column is never dropped out from under a
 * constraint that still names it, then the column, each only while it exists.
 *
 * ROLLBACK LOSES every task's mode. Every task falls back to GROUP behaviour,
 * which means members of an INDIVIDUAL task can see each other's evidence
 * again. The per-member rows are dropped by 2026_09_24_0002's own down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_tasks', 'submission_mode')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                // GROUP | INDIVIDUAL — varchar plus the CHECK below. Unlike
                // `status`, which has none, a new value needs a migration
                // that drops and re-adds `tasks_submission_mode_chk`.
                $t->string('submission_mode', 20)->default('GROUP');
            });
        }

        // Checked on its own, not under the column check: a run that stopped
        // between the two statements has the column and not the constraint.
        if ($this->isMySql() && ! $this->hasCheck('project_tasks', 'tasks_submission_mode_chk')) {
            DB::statement("ALTER TABLE `project_tasks` ADD CONSTRAINT `tasks_submission_mode_chk` CHECK (`submission_mode` IN ('GROUP','INDIVIDUAL'))");
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && $this->hasCheck('project_tasks', 'tasks_submission_mode_chk')) {
            DB::statement('ALTER TABLE `project_tasks` DROP CONSTRAINT `tasks_submission_mode_chk`');
        }

        if (Schema::hasColumn('project_tasks', 'submission_mode')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->dropColumn('submission_mode');
            });
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
