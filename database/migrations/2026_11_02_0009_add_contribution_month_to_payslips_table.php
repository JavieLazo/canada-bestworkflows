<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — `payslips.contribution_month` and
 * `payslips.contribution_base` (PayslipCalculator divergence 19): the
 * month (`YYYY-MM`) a payslip's SSS, PhilHealth and Pag-IBIG shares count
 * toward, and the monthly salary the tables were read at. A later payslip
 * of the same month reads the shares already posted for it
 * (`ContributionMonthToDate`), which the HALF_THEN_REST and WHOLE_FIRST
 * schedules take the rest from. Both NULL on a payslip posted before the
 * columns: its month is read as its period's start month, and its base is
 * unknown.
 *
 * RETRY-SAFE: each column and the CHECK stand behind their own guard; the
 * columns are appended (no `after()`) so MariaDB takes ALGORITHM=INSTANT.
 *
 * ROLLBACK LOSES the recorded month and base; the shares themselves stay
 * on the payslip, and a replay reads the month from the period again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        if (! Schema::hasColumn('payslips', 'contribution_month')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->string('contribution_month', 7)->nullable());
        }
        if (! Schema::hasColumn('payslips', 'contribution_base')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->decimal('contribution_base', 12, 2)->nullable());
        }
        if (! Schema::hasIndex('payslips', 'psl_contribution_month_idx')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->index(['employee_id', 'contribution_month'], 'psl_contribution_month_idx'));
        }

        if ($this->isMySql() && ! $this->hasCheck('psl_contribution_month_chk')) {
            DB::statement("ALTER TABLE `payslips` ADD CONSTRAINT `psl_contribution_month_chk` CHECK (`contribution_month` IS NULL OR `contribution_month` REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        if ($this->isMySql() && $this->hasCheck('psl_contribution_month_chk')) {
            DB::statement('ALTER TABLE `payslips` DROP CONSTRAINT `psl_contribution_month_chk`');
        }
        if (Schema::hasIndex('payslips', 'psl_contribution_month_idx')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->dropIndex('psl_contribution_month_idx'));
        }
        foreach (['contribution_base', 'contribution_month'] as $column) {
            if (Schema::hasColumn('payslips', $column)) {
                Schema::table('payslips', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payslips' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
