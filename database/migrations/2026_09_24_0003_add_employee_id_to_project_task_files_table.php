<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `project_task_files.employee_id`, the member a piece of
 * evidence belongs to.
 *
 * On an INDIVIDUAL task (2026_09_24_0001) each assignee's evidence is part of
 * their own response, so a file has to say whose it is. It cannot hang off
 * the member's `project_task_submissions` row (2026_09_24_0002): evidence is
 * attached BEFORE the member submits, while no row exists yet, and the link
 * must survive the member resubmitting after a return. So the file names the
 * employee directly.
 *
 * Nullable, with nullOnDelete like `project_tasks.submitted_by`
 * (2026_09_10_0001). BRIEF files come from management and name nobody. There
 * is NO BACKFILL: every SUBMISSION file already stored belongs to a GROUP
 * task, where the evidence is the whole crew's and `uploaded_by` (an account,
 * not an employee) already records who sent it.
 *
 * `taskfile_employee_idx` is declared BEFORE the foreign key, so MySQL reuses
 * it for the constraint instead of adding a second index.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can be killed between any two statements, and MySQL DDL does not roll
 * back. The column, the index and the constraint are three ALTERs on MySQL
 * and get three guards: the column only while hasColumn() is false, the index
 * only while hasIndex() does not list it, the constraint only while
 * getForeignKeys() lists none on the column. A retry resumes from wherever the
 * last run stopped. down() mirrors this in reverse — constraint, index,
 * column — because MySQL will not drop an index a foreign key still uses; on
 * SQLite (tests) the constraint drop is a table rebuild, which carries the
 * index over, so the same order works there.
 *
 * ROLLBACK LOSES which member each piece of evidence belongs to. The files,
 * their bytes and `uploaded_by` all stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_task_files', 'employee_id')) {
            Schema::table('project_task_files', function (Blueprint $t): void {
                // The member this evidence belongs to — an employee, not an
                // account, so it pairs with the membership pivot rather than
                // with uploaded_by.
                $t->char('employee_id', 26)->nullable();
            });
        }

        if (! Schema::hasIndex('project_task_files', 'taskfile_employee_idx')) {
            Schema::table('project_task_files', function (Blueprint $t): void {
                $t->index('employee_id', 'taskfile_employee_idx');
            });
        }

        // Checked on its own, not under the column check: a run that stopped
        // between the statements has the column and not the constraint.
        if (! Schema::hasForeignKey('project_task_files', ['employee_id'])) {
            Schema::table('project_task_files', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Symmetrical with up(): a rollback after a half-applied run drops
        // whichever pieces exist rather than failing on one that does not.
        if (Schema::hasForeignKey('project_task_files', ['employee_id'])) {
            Schema::table('project_task_files', function (Blueprint $t): void {
                $t->dropForeign(['employee_id']);
            });
        }

        if (Schema::hasIndex('project_task_files', 'taskfile_employee_idx')) {
            Schema::table('project_task_files', function (Blueprint $t): void {
                $t->dropIndex('taskfile_employee_idx');
            });
        }

        if (Schema::hasColumn('project_task_files', 'employee_id')) {
            Schema::table('project_task_files', function (Blueprint $t): void {
                $t->dropColumn('employee_id');
            });
        }
    }
};
