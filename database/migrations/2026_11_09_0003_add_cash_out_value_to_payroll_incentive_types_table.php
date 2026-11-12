<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT ONE CONVERTED DAY OF LEAVE CREDITS IS WORTH, set on the Incentives &
 * Bonuses type that pays cash conversions (`pays_leave_cash_out`,
 * LeaveCashOutValue):
 *
 *  - `cash_out_day_value` VARCHAR(8): FACTOR (the monthly rate times the
 *    factor — every type starts here) or DAILY (the payroll daily rate);
 *  - `cash_out_factor` DECIMAL(10,7): the factor, 0.0481927 to start.
 *
 * `pit_cash_out_value_chk` pins the two values and a factor above 0 and at
 * most 1. Columns appended without ->after(), so MariaDB 11.4 takes
 * ALGORITHM=INSTANT.
 *
 * A cash conversion already approved and not yet paid is priced by the
 * value in force when its payroll is reviewed, so it moves to the factor
 * with the next preview; a finalized payslip keeps what it paid.
 *
 * RETRY-SAFE: each column and the CHECK stand behind their own guard.
 *
 * ROLLBACK LOSES the setting: every cash conversion is priced at the daily
 * rate again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_incentive_types')) {
            return;
        }
        if (! Schema::hasColumn('payroll_incentive_types', 'cash_out_day_value')) {
            Schema::table('payroll_incentive_types', static fn (Blueprint $t) => $t->string('cash_out_day_value', 8)->default('FACTOR'));
        }
        if (! Schema::hasColumn('payroll_incentive_types', 'cash_out_factor')) {
            Schema::table('payroll_incentive_types', static fn (Blueprint $t) => $t->decimal('cash_out_factor', 10, 7)->default(0.0481927));
        }
        if ($this->isMySql() && ! $this->hasCheck('pit_cash_out_value_chk')) {
            DB::statement("ALTER TABLE `payroll_incentive_types` ADD CONSTRAINT `pit_cash_out_value_chk` CHECK (`cash_out_day_value` IN ('FACTOR','DAILY') AND `cash_out_factor` > 0 AND `cash_out_factor` <= 1)");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_incentive_types')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('pit_cash_out_value_chk')) {
            DB::statement('ALTER TABLE `payroll_incentive_types` DROP CONSTRAINT `pit_cash_out_value_chk`');
        }
        foreach (['cash_out_factor', 'cash_out_day_value'] as $column) {
            if (Schema::hasColumn('payroll_incentive_types', $column)) {
                Schema::table('payroll_incentive_types', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_incentive_types' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
