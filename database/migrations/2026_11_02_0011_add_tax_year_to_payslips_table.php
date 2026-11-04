<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — `payslips.tax_year`, `payslips.tax_method` and
 * `payslips.tax_refund` (divergence 20): the tax year a payslip's
 * withholding counts in (the year of its pay date), how the withholding
 * was worked out (TABLE, ANNUALIZED, FIXED, PERCENT, WHOLE_YEAR or NONE)
 * and the refund the year's last payslip paid back when the whole-year
 * estimate found too much withheld — zero everywhere else, and added to
 * the net pay. All NULL / 0 on a payslip posted before the columns: its
 * year is read from its run's pay date, else its period's last day.
 *
 * RETRY-SAFE: each column and the CHECK stand behind their own guard; the
 * columns are appended (no `after()`) so MariaDB takes ALGORITHM=INSTANT.
 *
 * ROLLBACK LOSES the recorded year, method and refund; the withholding and
 * the net pay stay on the payslip.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        if (! Schema::hasColumn('payslips', 'tax_year')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->unsignedSmallInteger('tax_year')->nullable());
        }
        if (! Schema::hasColumn('payslips', 'tax_method')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->string('tax_method', 12)->nullable());
        }
        if (! Schema::hasColumn('payslips', 'tax_refund')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->decimal('tax_refund', 12, 2)->default(0));
        }
        if (! Schema::hasIndex('payslips', 'psl_tax_year_idx')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->index(['employee_id', 'tax_year'], 'psl_tax_year_idx'));
        }

        if ($this->isMySql()) {
            if (! $this->hasCheck('psl_tax_year_chk')) {
                DB::statement('ALTER TABLE `payslips` ADD CONSTRAINT `psl_tax_year_chk` CHECK (`tax_year` IS NULL OR `tax_year` BETWEEN 2000 AND 2100)');
            }
            if (! $this->hasCheck('psl_tax_method_chk')) {
                DB::statement("ALTER TABLE `payslips` ADD CONSTRAINT `psl_tax_method_chk` CHECK (`tax_method` IS NULL OR `tax_method` IN ('TABLE','ANNUALIZED','FIXED','PERCENT','WHOLE_YEAR','NONE'))");
            }
            if (! $this->hasCheck('psl_tax_refund_chk')) {
                DB::statement('ALTER TABLE `payslips` ADD CONSTRAINT `psl_tax_refund_chk` CHECK (`tax_refund` >= 0)');
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        if ($this->isMySql()) {
            foreach (['psl_tax_refund_chk', 'psl_tax_method_chk', 'psl_tax_year_chk'] as $name) {
                if ($this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `payslips` DROP CONSTRAINT `{$name}`");
                }
            }
        }
        if (Schema::hasIndex('payslips', 'psl_tax_year_idx')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->dropIndex('psl_tax_year_idx'));
        }
        foreach (['tax_refund', 'tax_method', 'tax_year'] as $column) {
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
