<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE ADVANCE PAYROLL (PayrollAdvance). A regular payroll processed before
 * its period ends reads attendance only through a counted date and pays the
 * scheduled working days after it as full days; the next payroll settles
 * what those days were worth.
 *
 *  - `payroll_runs.attendance_through` DATE NULL: the run's counted date.
 *    NULL — every run before this, and every run that is not an advance —
 *    reads the whole period, exactly as before.
 *  - `payslips.attendance_through` DATE NULL: the run's date copied onto
 *    each posted payslip, so Timekeeping's paid-day freeze
 *    (PostedPayrollDateQuery) reads the payslip alone, as it always has:
 *    a posted payslip freezes its days only through this date.
 *  - `payslips.presumed_days` DECIMAL(6,2) DEFAULT 0: how many days the
 *    payslip paid ahead of their attendance.
 *  - `payslips.settlement` DECIMAL(12,2) DEFAULT 0: the settlement of
 *    earlier presumed days inside this payslip's gross pay, positive or
 *    negative. Its lines are `payslip_settlements` (2026_10_31_0002).
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 * `psl_presumed_chk` keeps the day count non-negative and
 * `psl_through_chk` keeps the counted date inside the payslip's period, on
 * mysql/mariadb only (sqlite never sees a CHECK).
 *
 * RETRY-SAFE: each column and each CHECK has its own guard. down() drops the
 * CHECKs, then the columns, and so forgets which payslips were paid in
 * advance and what they settled; `payslip_settlements` (the next migration)
 * must be dropped first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_runs', 'attendance_through')) {
            Schema::table('payroll_runs', static fn (Blueprint $t) => $t->date('attendance_through')->nullable());
        }
        if (! Schema::hasColumn('payslips', 'attendance_through')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->date('attendance_through')->nullable());
        }
        if (! Schema::hasColumn('payslips', 'presumed_days')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->decimal('presumed_days', 6, 2)->default(0));
        }
        if (! Schema::hasColumn('payslips', 'settlement')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->decimal('settlement', 12, 2)->default(0));
        }
        if ($this->isMySql()) {
            if (! $this->hasCheck('payslips', 'psl_presumed_chk')) {
                DB::statement('ALTER TABLE `payslips` ADD CONSTRAINT `psl_presumed_chk` CHECK (`presumed_days` >= 0)');
            }
            if (! $this->hasCheck('payslips', 'psl_through_chk')) {
                DB::statement('ALTER TABLE `payslips` ADD CONSTRAINT `psl_through_chk` CHECK (`attendance_through` IS NULL OR (`attendance_through` >= `period_start` AND `attendance_through` < `period_end`))');
            }
        }
    }

    public function down(): void
    {
        if ($this->isMySql()) {
            foreach (['psl_through_chk', 'psl_presumed_chk'] as $check) {
                if ($this->hasCheck('payslips', $check)) {
                    DB::statement("ALTER TABLE `payslips` DROP CONSTRAINT `{$check}`");
                }
            }
        }
        foreach (['settlement', 'presumed_days', 'attendance_through'] as $column) {
            if (Schema::hasColumn('payslips', $column)) {
                Schema::table('payslips', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        if (Schema::hasColumn('payroll_runs', 'attendance_through')) {
            Schema::table('payroll_runs', static fn (Blueprint $t) => $t->dropColumn('attendance_through'));
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
