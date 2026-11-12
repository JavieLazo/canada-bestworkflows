<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE LIMITS ON CONVERTING A LEAVE TYPE'S CREDITS TO CASH, set by HR on
 * Leave Settings for every employee (LeaveCashOutLimits). Each is NULL for
 * "no limit", which every type starts at, so nothing changes on upgrade:
 *
 *  - `cash_out_min_balance` DECIMAL(10,4): the available credits an
 *    employee must hold before converting any;
 *  - `cash_out_min_days` DECIMAL(10,4): the fewest days one request takes;
 *  - `cash_out_keep_days` DECIMAL(10,4): the days that must remain after it;
 *  - `cash_out_max_year` DECIMAL(10,4): the most days converted in a
 *    calendar year;
 *  - `cash_out_per_year` SMALLINT UNSIGNED: the most requests in a year;
 *  - `cash_out_reason_share` TINYINT UNSIGNED: the percent of the available
 *    balance from which a request needs a reason — such a request may go
 *    past the yearly maximum.
 *
 * And `paid_on_separation` BOOLEAN: whether HR pays the type's remaining
 * balance out when an employee leaves (terminal leave, FileTerminalLeave).
 * Every type that may already be converted to cash starts with it on.
 *
 * `lt_cash_out_limits_chk` pins the ranges. Columns appended without
 * ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: each column and the CHECK stand behind their own guard; the
 * backfill is an UPDATE that matches nothing the second time.
 *
 * ROLLBACK LOSES every limit and which types are paid out on separation;
 * the requests already made keep their days.
 */
return new class extends Migration
{
    private const array DECIMALS = ['cash_out_min_balance', 'cash_out_min_days', 'cash_out_keep_days', 'cash_out_max_year'];

    private const string CHECK = '(`cash_out_min_balance` IS NULL OR (`cash_out_min_balance` > 0 AND `cash_out_min_balance` <= 999999.9999))'
        .' AND (`cash_out_min_days` IS NULL OR (`cash_out_min_days` > 0 AND `cash_out_min_days` <= 999999.9999))'
        .' AND (`cash_out_keep_days` IS NULL OR (`cash_out_keep_days` > 0 AND `cash_out_keep_days` <= 999999.9999))'
        .' AND (`cash_out_max_year` IS NULL OR (`cash_out_max_year` > 0 AND `cash_out_max_year` <= 999999.9999))'
        .' AND (`cash_out_per_year` IS NULL OR `cash_out_per_year` BETWEEN 1 AND 999)'
        .' AND (`cash_out_reason_share` IS NULL OR `cash_out_reason_share` BETWEEN 1 AND 100)';

    public function up(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        foreach (self::DECIMALS as $column) {
            if (! Schema::hasColumn('leave_types', $column)) {
                Schema::table('leave_types', static fn (Blueprint $t) => $t->decimal($column, 10, 4)->nullable());
            }
        }
        if (! Schema::hasColumn('leave_types', 'cash_out_per_year')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->unsignedSmallInteger('cash_out_per_year')->nullable());
        }
        if (! Schema::hasColumn('leave_types', 'cash_out_reason_share')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->unsignedTinyInteger('cash_out_reason_share')->nullable());
        }
        if (! Schema::hasColumn('leave_types', 'paid_on_separation')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->boolean('paid_on_separation')->default(false));
        }
        if (Schema::hasColumn('leave_types', 'can_cash_out')) {
            DB::table('leave_types')->where('can_cash_out', true)->where('paid_on_separation', false)->update(['paid_on_separation' => true]);
        }
        if ($this->isMySql() && ! $this->hasCheck('lt_cash_out_limits_chk')) {
            DB::statement('ALTER TABLE `leave_types` ADD CONSTRAINT `lt_cash_out_limits_chk` CHECK ('.self::CHECK.')');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('lt_cash_out_limits_chk')) {
            DB::statement('ALTER TABLE `leave_types` DROP CONSTRAINT `lt_cash_out_limits_chk`');
        }
        foreach (['paid_on_separation', 'cash_out_reason_share', 'cash_out_per_year', ...array_reverse(self::DECIMALS)] as $column) {
            if (Schema::hasColumn('leave_types', $column)) {
                Schema::table('leave_types', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_types' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
