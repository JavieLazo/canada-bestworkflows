<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * One assignee's own response to an INDIVIDUAL task (2026_09_24_0001 added the
 * mode). A GROUP task keeps its single response on the task row and never has
 * rows here. There is at most one row per (task, member): it holds the
 * member's LATEST response and the latest decision on it, and a resubmission
 * updates it in place. No row means "not submitted", so NONE is never stored,
 * and `status` is SUBMITTED | RETURNED | APPROVED, pinned by `pts_status_chk`
 * on MySQL/MariaDB.
 *
 * `employee_id` is NOT NULL on purpose. A NULL never matches row scoping, and
 * a UNIQUE index does not stop two NULLs, so a "team" row with no member would
 * both escape the privacy rules and duplicate freely. It cascades with the
 * employee, like the membership pivot (2026_09_10_0001).
 *
 * `reviewed_by` is an ACTOR column (a users id, session-supplied, never in the
 * model's $fillable) and nulls when the account goes, like every other users
 * FK. `revision` is an optimistic token: every write to the row bumps it, so a
 * decision taken on a response the member has since resubmitted can be told
 * apart and refused.
 *
 * Shape follows project_task_assignees: a ULID primary key minted by
 * BaseModel, cascading foreign keys, and short explicit unique/index names
 * added BEFORE the foreign keys so MySQL reuses them for the constraints.
 * `pts_task_employee_unq` also serves every per-task lookup by its prefix.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * with no SSH and MySQL DDL does not roll back, so a run can stop between any
 * two statements and is simply run again. The create carries only the columns
 * and the primary key, which MySQL puts in the CREATE TABLE itself; every
 * other piece is its own ALTER with its own guard, so a retry resumes from
 * wherever the last run stopped: the table only while hasTable() is false,
 * the unique and each index only while hasIndex() does not list its name,
 * each foreign key only while hasForeignKey() finds none on its column, and
 * the CHECK only while information_schema.TABLE_CONSTRAINTS lists no
 * constraint of that name. ROW_FORMAT=DYNAMIC restates the same format on a
 * retry, so it needs no guard. On SQLite (tests) each foreign key is a table
 * rebuild, which carries the indexes over. down() drops the table, and every
 * index, key and CHECK with it, only if it exists.
 *
 * ROLLBACK LOSES every member response, approval and return note. Only the
 * audit log keeps a trace of them, and it never held their text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_task_submissions')) {
            Schema::create('project_task_submissions', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('task_id', 26);
                $t->char('employee_id', 26);

                // SUBMITTED | RETURNED | APPROVED — free text plus the CHECK
                // below. "Not submitted" is the absence of a row.
                $t->string('status', 20);
                $t->text('remarks')->nullable();
                $t->dateTime('submitted_at', 3);

                // Actor column: the account that decided the latest response.
                // nullOnDelete like every other users FK.
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->text('review_remarks')->nullable();
                $t->dateTime('reviewed_at', 3)->nullable();

                // Optimistic token, +1 on every write to the row.
                $t->unsignedInteger('revision')->default(0);

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Every step below is checked on its own, not under the table check:
        // a run that stopped after the create has the table and none of them.
        // The unique and the indexes come BEFORE the foreign keys, so MySQL
        // reuses them for the constraints instead of adding its own
        // (payroll_run_members precedent). The unique's task_id prefix is the
        // one the task_id constraint reuses.
        if (! Schema::hasIndex('project_task_submissions', 'pts_task_employee_unq')) {
            Schema::table('project_task_submissions', function (Blueprint $t): void {
                $t->unique(['task_id', 'employee_id'], 'pts_task_employee_unq');
            });
        }

        if (! Schema::hasIndex('project_task_submissions', 'pts_employee_idx')) {
            Schema::table('project_task_submissions', function (Blueprint $t): void {
                $t->index('employee_id', 'pts_employee_idx');
            });
        }

        if (! Schema::hasIndex('project_task_submissions', 'pts_reviewer_idx')) {
            Schema::table('project_task_submissions', function (Blueprint $t): void {
                $t->index('reviewed_by', 'pts_reviewer_idx');
            });
        }

        if (! Schema::hasForeignKey('project_task_submissions', ['task_id'])) {
            Schema::table('project_task_submissions', function (Blueprint $t): void {
                $t->foreign('task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey('project_task_submissions', ['employee_id'])) {
            Schema::table('project_task_submissions', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey('project_task_submissions', ['reviewed_by'])) {
            Schema::table('project_task_submissions', function (Blueprint $t): void {
                $t->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('project_task_submissions');

        // Its own guard like every step above: a run that stopped before it
        // has the table and not the constraint.
        if ($this->isMySql() && ! $this->hasCheck('project_task_submissions', 'pts_status_chk')) {
            DB::statement("ALTER TABLE `project_task_submissions` ADD CONSTRAINT `pts_status_chk` CHECK (`status` IN ('SUBMITTED','RETURNED','APPROVED'))");
        }
    }

    public function down(): void
    {
        // The CHECK, the indexes and the foreign keys all go with the table.
        Schema::dropIfExists('project_task_submissions');
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

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
