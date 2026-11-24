<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS on `payslips`: the deductions taken at source and what they
 * were worked out on.
 *
 *  - `cpp`, `cpp2`, `ei`, `federal_tax`, `provincial_tax`: the employee's
 *    Canada Pension Plan contribution and second contribution, Employment
 *    Insurance premium, and federal and provincial income tax for the pay.
 *    They are part of `total_deductions`.
 *  - `cpp_er`, `cpp2_er`, `ei_er`: what the employer pays on top of each.
 *    Never deducted.
 *  - `pensionable_earnings`, `insurable_earnings`: the pay the pension
 *    contribution and the premium were worked out on. A year's totals of the
 *    ten columns are what the year-end slip reports, and the year's running
 *    totals are what stops a contribution at its yearly maximum.
 *
 * All DECIMAL(12,2), 0 by default — which is what every payslip posted
 * before the columns reads as — and appended without ->after(), so MariaDB
 * 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: each column and the CHECK stand behind their own guard. The
 * CHECK is MariaDB/MySQL only.
 *
 * ROLLBACK LOSES the ten figures on every payslip; `total_deductions` and
 * `net_pay` keep what was paid.
 */
return new class extends Migration
{
    private const COLUMNS = ['cpp', 'cpp2', 'ei', 'federal_tax', 'provincial_tax', 'cpp_er', 'cpp2_er', 'ei_er', 'pensionable_earnings', 'insurable_earnings'];

    public function up(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('payslips', $column)) {
                Schema::table('payslips', static fn (Blueprint $t) => $t->decimal($column, 12, 2)->default(0));
            }
        }

        if ($this->isMySql() && ! $this->hasCheck('psl_source_deductions_chk')) {
            $nonNegative = implode(' AND ', array_map(static fn (string $column): string => "`{$column}` >= 0", self::COLUMNS));
            DB::statement("ALTER TABLE `payslips` ADD CONSTRAINT `psl_source_deductions_chk` CHECK ({$nonNegative})");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        // The CHECK names the columns, so it goes first.
        if ($this->isMySql() && $this->hasCheck('psl_source_deductions_chk')) {
            DB::statement('ALTER TABLE `payslips` DROP CONSTRAINT `psl_source_deductions_chk`');
        }
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('payslips', $column)) {
                Schema::table('payslips', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
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
