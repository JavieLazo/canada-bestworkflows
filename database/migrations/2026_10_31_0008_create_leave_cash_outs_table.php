<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEAVE CREDITS CASHED OUT (LeaveCashOutPolicy). An employee asks to turn
 * part of a leave type's balance into pay; a leave manager approves; the
 * employee's next regular payroll pays the days at their daily rate as a
 * line of the Incentives & Bonuses type HR marked for it, and the credits
 * come off the ledger when that payroll posts.
 *
 *  - `leave_cash_outs`: one row per request — the employee, the leave type
 *    token, the days (half days allowed), the status (PENDING, APPROVED,
 *    REJECTED, WITHDRAWN, PAID), who asked and when, who reviewed and when
 *    with a note, and once paid the payslip and the pesos. `payslip_id` is
 *    RESTRICT: a paid cash-out is evidence of the payslip; the employee key
 *    cascades like every leave row. Actor columns are server-set.
 *  - `leave_types.can_cash_out` DEFAULT FALSE: which types may be cashed
 *    out (Leave Credits settings). Nothing may until HR says so.
 *  - `payroll_incentive_types.pays_leave_cash_out` DEFAULT FALSE: the one
 *    Incentives & Bonuses type whose name, tax treatment and 13th-month
 *    flag a cash-out line carries. The command keeps it on one type.
 *  - `leave_credit_entries`: `lce_kind_chk` gains CASH_OUT and `lce_days_chk`
 *    requires it negative, like CHARGE and LAPSE. Both CHECKs are dropped
 *    and re-added with the full literal lists.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT. The
 * CHECKs are created on mysql/mariadb only (sqlite never sees a CHECK).
 *
 * RETRY-SAFE: each table, column, index, key and CHECK has its own guard;
 * the two re-added CHECKs are dropped only while their clause is still the
 * old one. down() drops the table, the two columns and restores the
 * previous CHECK clauses, which a database holding a CASH_OUT entry
 * refuses — roll back only before the first cash-out posts.
 */
return new class extends Migration
{
    private const string KIND_CLAUSE = "`kind` IN ('BASELINE','OPENING','ACCRUAL','CARRY_FORWARD','ADJUSTMENT','CHARGE','LAPSE','CASH_OUT')";

    private const string PREVIOUS_KIND_CLAUSE = "`kind` IN ('BASELINE','OPENING','ACCRUAL','CARRY_FORWARD','ADJUSTMENT','CHARGE','LAPSE')";

    private const string DAYS_CLAUSE = "`days` BETWEEN -999999.9999 AND 999999.9999 AND ((`kind` IN ('BASELINE','OPENING') AND `days` >= 0) OR (`kind` = 'ACCRUAL' AND `days` > 0) OR (`kind` = 'CARRY_FORWARD' AND `days` <> 0) OR (`kind` = 'ADJUSTMENT' AND `days` <> 0) OR (`kind` IN ('CHARGE','LAPSE','CASH_OUT') AND `days` < 0))";

    private const string PREVIOUS_DAYS_CLAUSE = "`days` BETWEEN -999999.9999 AND 999999.9999 AND ((`kind` IN ('BASELINE','OPENING') AND `days` >= 0) OR (`kind` = 'ACCRUAL' AND `days` > 0) OR (`kind` = 'CARRY_FORWARD' AND `days` <> 0) OR (`kind` = 'ADJUSTMENT' AND `days` <> 0) OR (`kind` IN ('CHARGE','LAPSE') AND `days` < 0))";

    public function up(): void
    {
        if (! Schema::hasTable('leave_cash_outs')) {
            Schema::create('leave_cash_outs', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->string('request_type', 64);
                $t->decimal('days', 10, 4);
                $t->string('status', 10)->default('PENDING');
                $t->string('note', 500)->nullable();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->dateTime('requested_at', 3);
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->string('reviewed_by_name', 350)->nullable();
                $t->dateTime('reviewed_at', 3)->nullable();
                $t->string('review_note', 500)->nullable();
                $t->char('payslip_id', 26)->nullable();
                $t->decimal('amount', 12, 2)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
            $this->dynamicRowFormat('leave_cash_outs');
        }
        if (! Schema::hasIndex('leave_cash_outs', 'lco_employee_status_idx')) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->index(['employee_id', 'status'], 'lco_employee_status_idx'));
        }
        if (! Schema::hasIndex('leave_cash_outs', 'lco_payslip_idx')) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->index('payslip_id', 'lco_payslip_idx'));
        }
        if (! Schema::hasForeignKey('leave_cash_outs', ['employee_id'])) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->foreign('employee_id', 'lco_employee_fk')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }
        if (! Schema::hasForeignKey('leave_cash_outs', ['payslip_id'])) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->foreign('payslip_id', 'lco_payslip_fk')->references('id')->on('payslips')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('leave_cash_outs', ['requested_by'])) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->foreign('requested_by', 'lco_requested_by_fk')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('leave_cash_outs', ['reviewed_by'])) {
            Schema::table('leave_cash_outs', static fn (Blueprint $t) => $t->foreign('reviewed_by', 'lco_reviewed_by_fk')->references('id')->on('users')->nullOnDelete());
        }
        if (! Schema::hasColumn('leave_types', 'can_cash_out')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->boolean('can_cash_out')->default(false));
        }
        if (! Schema::hasColumn('payroll_incentive_types', 'pays_leave_cash_out')) {
            Schema::table('payroll_incentive_types', static fn (Blueprint $t) => $t->boolean('pays_leave_cash_out')->default(false));
        }
        if ($this->isMySql()) {
            if (! $this->hasCheck('leave_cash_outs', 'lco_status_chk')) {
                DB::statement("ALTER TABLE `leave_cash_outs` ADD CONSTRAINT `lco_status_chk` CHECK (`status` IN ('PENDING','APPROVED','REJECTED','WITHDRAWN','PAID'))");
            }
            if (! $this->hasCheck('leave_cash_outs', 'lco_days_chk')) {
                DB::statement('ALTER TABLE `leave_cash_outs` ADD CONSTRAINT `lco_days_chk` CHECK (`days` > 0 AND (`amount` IS NULL OR `amount` >= 0))');
            }
            if (! $this->hasCheck('leave_cash_outs', 'lco_paid_chk')) {
                DB::statement("ALTER TABLE `leave_cash_outs` ADD CONSTRAINT `lco_paid_chk` CHECK ((`status` = 'PAID') = (`payslip_id` IS NOT NULL))");
            }
            if (Schema::hasTable('leave_credit_entries')) {
                $this->replaceCheck('leave_credit_entries', 'lce_kind_chk', self::KIND_CLAUSE, 'cash_out');
                $this->replaceCheck('leave_credit_entries', 'lce_days_chk', self::DAYS_CLAUSE, 'cash_out');
            }
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && Schema::hasTable('leave_credit_entries')) {
            $this->replaceCheck('leave_credit_entries', 'lce_days_chk', self::PREVIOUS_DAYS_CLAUSE, null);
            $this->replaceCheck('leave_credit_entries', 'lce_kind_chk', self::PREVIOUS_KIND_CLAUSE, null);
        }
        if (Schema::hasColumn('payroll_incentive_types', 'pays_leave_cash_out')) {
            Schema::table('payroll_incentive_types', static fn (Blueprint $t) => $t->dropColumn('pays_leave_cash_out'));
        }
        if (Schema::hasColumn('leave_types', 'can_cash_out')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->dropColumn('can_cash_out'));
        }
        if (Schema::hasTable('leave_cash_outs')) {
            Schema::drop('leave_cash_outs');
        }
    }

    /**
     * Drops the named CHECK while its clause lacks `$marker` (null: while it
     * holds it) and adds it with `$clause` while absent — each step guarded,
     * so a run killed between them resumes at the add.
     */
    private function replaceCheck(string $table, string $name, string $clause, ?string $marker): void
    {
        if ($this->hasCheck($table, $name)) {
            $current = strtolower(str_replace(['`', ' ', '"'], '', $this->clause($table, $name)));
            $wanted = $marker === null ? ! str_contains($current, 'cash_out') : str_contains($current, $marker);
            if (! $wanted) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
            }
        }
        if (! $this->hasCheck($table, $name)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$clause})");
        }
    }

    private function clause(string $table, string $name): string
    {
        $row = DB::selectOne(
            'SELECT CHECK_CLAUSE AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $name],
        );

        return (string) ($row->c ?? '');
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
