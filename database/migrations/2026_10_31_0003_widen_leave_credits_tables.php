<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEAVE CREDITS AS A LEDGER THAT EARNS AND IS CHARGED. Three changes:
 *
 *  - `leave_credits.credits` and `leave_credit_entries.days` widen from
 *    DECIMAL(6,2) to DECIMAL(10,4): credits are not money, and a month's
 *    earning (1.167) or a minute's charge (.002 of a day) needs four
 *    decimals. The allocation may now go BELOW ZERO, as far as the leave
 *    type's limit allows (an attendance charge takes credits the employee
 *    does not have): the range CHECK is symmetric.
 *  - `leave_credit_entries.kind` gains CHARGE (an attendance charge posted
 *    by a finalized payroll, always negative) and LAPSE (what the year-end
 *    rule did not carry, always negative).
 *  - `leave_types` gains the credit rules HR sets per type: whether it
 *    EARNS credits, its UNIT (days or hours), whether lateness and
 *    undertime or absences may be CHARGED to it, the ORDER it is charged
 *    in, and how far BELOW ZERO it may go. Every default repeats today:
 *    nothing earns, nothing is charged.
 *
 * RETRY-SAFE: each CHECK is dropped and added again in its own guarded
 * step, each column change and each new column is guarded on the column's
 * current shape. down() narrows the columns back (values beyond two
 * decimals or below zero would be refused by the narrower CHECKs, so it is
 * for a database that holds none) and drops the leave-type columns.
 */
return new class extends Migration
{
    private const array CREDIT_CHECKS = [
        'lcr_credits_chk' => '`credits` BETWEEN -999999.9999 AND 999999.9999',
    ];

    private const array ENTRY_CHECKS = [
        'lce_kind_chk' => "`kind` IN ('BASELINE','OPENING','ACCRUAL','CARRY_FORWARD','ADJUSTMENT','CHARGE','LAPSE')",
        'lce_days_chk' => "`days` BETWEEN -999999.9999 AND 999999.9999 AND ((`kind` IN ('BASELINE','OPENING') AND `days` >= 0) OR (`kind` IN ('ACCRUAL','CARRY_FORWARD') AND `days` > 0) OR (`kind` = 'ADJUSTMENT' AND `days` <> 0) OR (`kind` IN ('CHARGE','LAPSE') AND `days` < 0))",
    ];

    private const array TYPE_CHECKS = [
        'lt_unit_chk' => "`unit` IN ('DAYS','HOURS')",
        'lt_below_zero_chk' => '`below_zero_limit` >= 0',
    ];

    public function up(): void
    {
        if ($this->isMySql()) {
            foreach (array_keys(self::CREDIT_CHECKS) as $name) {
                $this->dropCheck('leave_credits', $name);
            }
            foreach (array_keys(self::ENTRY_CHECKS) as $name) {
                $this->dropCheck('leave_credit_entries', $name);
            }
        }
        if (! $this->isFourDecimals('leave_credits', 'credits')) {
            Schema::table('leave_credits', static fn (Blueprint $t) => $t->decimal('credits', 10, 4)->change());
        }
        if (! $this->isFourDecimals('leave_credit_entries', 'days')) {
            Schema::table('leave_credit_entries', static fn (Blueprint $t) => $t->decimal('days', 10, 4)->change());
        }
        if (! Schema::hasColumn('leave_types', 'earns_credits')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->boolean('earns_credits')->default(false));
        }
        if (! Schema::hasColumn('leave_types', 'unit')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->string('unit', 10)->default('DAYS'));
        }
        if (! Schema::hasColumn('leave_types', 'charge_late')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->boolean('charge_late')->default(false));
        }
        if (! Schema::hasColumn('leave_types', 'charge_absence')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->boolean('charge_absence')->default(false));
        }
        if (! Schema::hasColumn('leave_types', 'charge_order')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->unsignedSmallInteger('charge_order')->default(0));
        }
        if (! Schema::hasColumn('leave_types', 'below_zero_limit')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->decimal('below_zero_limit', 10, 4)->default(0));
        }
        if ($this->isMySql()) {
            foreach (self::CREDIT_CHECKS as $name => $expression) {
                $this->addCheck('leave_credits', $name, $expression);
            }
            foreach (self::ENTRY_CHECKS as $name => $expression) {
                $this->addCheck('leave_credit_entries', $name, $expression);
            }
            foreach (self::TYPE_CHECKS as $name => $expression) {
                $this->addCheck('leave_types', $name, $expression);
            }
        }
    }

    public function down(): void
    {
        if ($this->isMySql()) {
            foreach (array_keys(self::TYPE_CHECKS) as $name) {
                $this->dropCheck('leave_types', $name);
            }
            foreach (array_keys(self::ENTRY_CHECKS) as $name) {
                $this->dropCheck('leave_credit_entries', $name);
            }
            foreach (array_keys(self::CREDIT_CHECKS) as $name) {
                $this->dropCheck('leave_credits', $name);
            }
        }
        foreach (['below_zero_limit', 'charge_order', 'charge_absence', 'charge_late', 'unit', 'earns_credits'] as $column) {
            if (Schema::hasColumn('leave_types', $column)) {
                Schema::table('leave_types', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        if ($this->isFourDecimals('leave_credit_entries', 'days')) {
            Schema::table('leave_credit_entries', static fn (Blueprint $t) => $t->decimal('days', 6, 2)->change());
        }
        if ($this->isFourDecimals('leave_credits', 'credits')) {
            Schema::table('leave_credits', static fn (Blueprint $t) => $t->decimal('credits', 6, 2)->change());
        }
        if ($this->isMySql()) {
            $this->addCheck('leave_credits', 'lcr_credits_chk', '`credits` BETWEEN 0 AND 9999.99');
            $this->addCheck('leave_credit_entries', 'lce_kind_chk', "`kind` IN ('BASELINE','OPENING','ACCRUAL','CARRY_FORWARD','ADJUSTMENT')");
            $this->addCheck('leave_credit_entries', 'lce_days_chk', "`days` BETWEEN -9999.99 AND 9999.99 AND ((`kind` IN ('BASELINE','OPENING') AND `days` >= 0) OR (`kind` IN ('ACCRUAL','CARRY_FORWARD') AND `days` > 0) OR (`kind` = 'ADJUSTMENT' AND `days` <> 0))");
        }
    }

    /** Whether the column already holds four decimals (sqlite answers from its declared type, MariaDB from information_schema). */
    private function isFourDecimals(string $table, string $column): bool
    {
        if (! Schema::hasColumn($table, $column)) {
            return false;
        }
        if ($this->isMySql()) {
            $row = DB::selectOne('SELECT NUMERIC_SCALE AS s FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);

            return (int) ($row->s ?? 0) === 4;
        }
        foreach (Schema::getColumns($table) as $info) {
            if ($info['name'] === $column) {
                return str_contains(strtolower((string) ($info['type'] ?? '')), ',4)') || str_contains(strtolower((string) ($info['type'] ?? '')), ', 4)');
            }
        }

        return false;
    }

    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function dropCheck(string $table, string $name): void
    {
        if ($this->hasCheck($table, $name)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        }
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! $this->hasCheck($table, $name)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
