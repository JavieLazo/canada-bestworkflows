<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * An employee's previous jobs as the Personal Data Sheet's work experience
 * lists them (DELIBERATE DIVERGENCE: the reference record has no Personal
 * Data Sheet lists): one row per job held before this one, with the position
 * title, the department, agency, office or company, the dates from and to,
 * the monthly salary, the pay grade, the status of appointment and whether it
 * was government service (NULL when not stated). The CURRENT job is never a
 * row here: the work experience list derives it from the record's Employee
 * Details, so it is never stored twice.
 *
 * No unique: a row is identified by its id, an employee may have held any
 * number of jobs, and reads order the rows by their dates, most recent first.
 * `ewexp_employee_idx` serves every per-employee read.
 *
 * The rows are written only by the Workforce commands, inside a transaction
 * that locked the employee's `employee_profiles` row first — an insert, or an
 * update or delete by primary key, never a locking read or a delete by range,
 * which on InnoDB would gap-lock the index under two first saves — and read
 * only through `PdsRows` / `EmployeeRecords`, behind the record's own
 * visibility rule; nothing else joins, counts or reports on them. So they
 * carry no actor scope, like `employee_children`. A row is edited in place,
 * so the table has a nullable `updated_at`. Nothing in payroll reads
 * `monthly_salary`: it is what the employee was paid elsewhere.
 *
 * `employee_id` cascades like `employee_children`: the previous jobs are part
 * of the record and go with it. The index is declared BEFORE the foreign key
 * so MySQL/MariaDB reuse it for the constraint. No legacy column is read,
 * copied or written.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * create carries only the columns and the primary key, and every other piece
 * is its own ALTER behind its own guard: the index only while hasIndex() does
 * not list its name, and the foreign key only while hasForeignKey() finds
 * none on its column. ROW_FORMAT=DYNAMIC restates the same format on a retry
 * and needs no guard. On sqlite (tests) the foreign key is a table rebuild
 * that carries the index over. down() drops the table, and the index and key
 * with it, only if it exists.
 *
 * ROLLBACK LOSES every previous job typed since: nothing else in the system
 * holds them. The record's Employee Details, which the current job is read
 * from, are untouched either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_work_experiences')) {
            Schema::create('employee_work_experiences', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                $t->string('position_title', 150);

                // "Department / agency / office / company".
                $t->string('company', 150);

                $t->date('date_from');
                $t->date('date_to');

                $t->decimal('monthly_salary', 12, 2)->nullable();
                $t->string('pay_grade', 20)->nullable();
                $t->string('appointment_status', 60)->nullable();

                // NULL not stated, true government service, false not.
                $t->boolean('government_service')->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Each step below is checked on its own, not under the table check: a
        // run that stopped after the create has the table and none of them.
        if (! Schema::hasIndex('employee_work_experiences', 'ewexp_employee_idx')) {
            Schema::table('employee_work_experiences', function (Blueprint $t): void {
                $t->index(['employee_id'], 'ewexp_employee_idx');
            });
        }

        if (! Schema::hasForeignKey('employee_work_experiences', ['employee_id'])) {
            Schema::table('employee_work_experiences', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('employee_work_experiences');
    }

    public function down(): void
    {
        // The index and the foreign key go with the table. Guarded like every
        // step in up(), so a repeated rollback runs no DDL at all.
        if (Schema::hasTable('employee_work_experiences')) {
            Schema::drop('employee_work_experiences');
        }
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! $this->isMySql()) {
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
