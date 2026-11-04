<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ATTENDANCE CHARGED TO LEAVE CREDITS (AttendanceChargePlan). A finalized
 * payroll charges each day's lateness, undertime and absences to the
 * employee's leave credits first, by their approved policy, and prices only
 * what credits did not cover to pay.
 *
 *  - `leave_attendance_charges`: one row per employee, date and kind (LATE —
 *    lateness and undertime together — or ABSENCE) a payslip charged: the
 *    minutes it came from, the days they converted to, what each leave type
 *    covered (`covered` JSON: type, days, ledger entry id) and what was left
 *    for pay. UNIQUE (employee, date, kind): a day is charged once, and a
 *    second charge fails the finalization that tries it. `payslip_id` is
 *    RESTRICT: the charge is evidence of the payslip and must not vanish
 *    with it; the employee key cascades like every attendance row.
 *  - `payslips.leave_charged_days` DECIMAL(8,4) DEFAULT 0: the days of
 *    credits the payslip charged, lateness and absences together.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 * `lac_kind_chk`, `lac_days_chk` and `psl_charged_chk` are created on
 * mysql/mariadb only (sqlite never sees a CHECK).
 *
 * RETRY-SAFE: each table, column, index, key and CHECK has its own guard.
 * down() drops the CHECK, the column and the table, and so forgets what
 * every posted payslip charged to credits; the CHARGE ledger entries and
 * the allocations they moved stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_attendance_charges')) {
            Schema::create('leave_attendance_charges', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->char('payslip_id', 26)->nullable();
                $t->date('date');
                $t->string('kind', 10);
                $t->unsignedInteger('minutes')->nullable();
                $t->decimal('days', 10, 4);
                $t->json('covered');
                $t->decimal('uncovered_days', 10, 4)->default(0);
                $t->dateTime('created_at', 3);
            });
            $this->dynamicRowFormat('leave_attendance_charges');
        }
        if (! Schema::hasIndex('leave_attendance_charges', 'lac_employee_date_kind_unq')) {
            Schema::table('leave_attendance_charges', static fn (Blueprint $t) => $t->unique(['employee_id', 'date', 'kind'], 'lac_employee_date_kind_unq'));
        }
        if (! Schema::hasIndex('leave_attendance_charges', 'lac_payslip_idx')) {
            Schema::table('leave_attendance_charges', static fn (Blueprint $t) => $t->index('payslip_id', 'lac_payslip_idx'));
        }
        if (! Schema::hasForeignKey('leave_attendance_charges', ['employee_id'])) {
            Schema::table('leave_attendance_charges', static fn (Blueprint $t) => $t->foreign('employee_id', 'lac_employee_fk')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }
        if (! Schema::hasForeignKey('leave_attendance_charges', ['payslip_id'])) {
            Schema::table('leave_attendance_charges', static fn (Blueprint $t) => $t->foreign('payslip_id', 'lac_payslip_fk')->references('id')->on('payslips')->restrictOnDelete());
        }
        if (! Schema::hasColumn('payslips', 'leave_charged_days')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->decimal('leave_charged_days', 8, 4)->default(0));
        }
        if ($this->isMySql()) {
            if (! $this->hasCheck('leave_attendance_charges', 'lac_kind_chk')) {
                DB::statement("ALTER TABLE `leave_attendance_charges` ADD CONSTRAINT `lac_kind_chk` CHECK (`kind` IN ('LATE', 'ABSENCE'))");
            }
            if (! $this->hasCheck('leave_attendance_charges', 'lac_days_chk')) {
                DB::statement('ALTER TABLE `leave_attendance_charges` ADD CONSTRAINT `lac_days_chk` CHECK (`days` >= 0 AND `uncovered_days` >= 0 AND `uncovered_days` <= `days`)');
            }
            if (! $this->hasCheck('payslips', 'psl_charged_chk')) {
                DB::statement('ALTER TABLE `payslips` ADD CONSTRAINT `psl_charged_chk` CHECK (`leave_charged_days` >= 0)');
            }
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && $this->hasCheck('payslips', 'psl_charged_chk')) {
            DB::statement('ALTER TABLE `payslips` DROP CONSTRAINT `psl_charged_chk`');
        }
        if (Schema::hasColumn('payslips', 'leave_charged_days')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->dropColumn('leave_charged_days'));
        }
        if (Schema::hasTable('leave_attendance_charges')) {
            Schema::drop('leave_attendance_charges');
        }
    }

    private function dynamicRowFormat(string $table): void
    {
        if ($this->isMySql()) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

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
