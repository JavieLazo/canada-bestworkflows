<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — copems has no counterpart; `project_tasks` gains a planned
 * start date.
 *
 * DELIBERATE DIVERGENCE (2026-09-25): the reference task carries a due date
 * and nothing else (copems prisma/schema.prisma, model ProjectTask, and
 * TaskInput in src/lib/api/schemas.ts). Here whoever hands out the work may
 * also say when it is meant to START. It is a plan, typed by hand: nothing
 * stamps it when an assignee presses Start, and it never stops anyone from
 * starting early. The commands keep it inside the project's own window and on
 * or before the task's due date (TaskSchedule); the database does not.
 *
 * `DATE NULL`, with no default, no CHECK and no index. Every existing task
 * reads null — "no start date yet" — which is exactly what it meant before
 * the column existed, so there is no backfill. A project holds tens of tasks,
 * and the one sort over this column runs inside one project's rows, so an
 * index would cost every write and help no read. The column is appended
 * without `->after()`, which keeps the ADD instant on MariaDB 11.4 instead of
 * rebuilding the table.
 *
 * RETRY-SAFE, STEP BY STEP. There is one step, one ALTER, guarded by
 * hasColumn(): production migrates from a temporary cPanel cron that can be
 * killed at any point, MySQL DDL does not roll back, and a run that already
 * added the column finds it and does nothing. down() drops the column only
 * while it exists, so a repeated rollback is as safe as a repeated up().
 *
 * ROLLBACK LOSES every task's start date. Nothing else reads the column, so
 * the tasks themselves, their statuses and their due dates are untouched; the
 * dates typed in since the release are gone for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_tasks', 'start_date')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->date('start_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_tasks', 'start_date')) {
            Schema::table('project_tasks', function (Blueprint $t): void {
                $t->dropColumn('start_date');
            });
        }
    }
};
