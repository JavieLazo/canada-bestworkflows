<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The settlement ledger of the advance payroll (PayrollAdvance,
 * 2026_10_31_0001). One row per presumed-days settlement a posted payslip
 * makes: which earlier payslip's days (`settled_payslip_id`, settled ONCE —
 * `pss_settled_unq`), the days (`from_date`..`to_date`), what was presumed
 * and what the days turned out to be worth, and the difference (`amount`,
 * negative when they were worth less), with both figures itemised by line
 * in `details` (JSON). `settled_code` copies the settled payroll's code so
 * the payslip can name it without a join.
 *
 * Written only by FinalizePayroll in the finalize transaction, and immutable
 * like every payslip line. Both payslip keys RESTRICT: a posted payslip is
 * never deleted, and a settlement must never vanish or lose the payslip it
 * settled (Timekeeping's paid-day freeze reads the settled days from here).
 *
 * RETRY-SAFE: CREATE, each index, each FK and the MariaDB/MySQL CHECK have
 * their own guard; the indexes precede their FKs. down() drops the table and
 * so forgets every settlement's lines; the amounts stay inside each payslip.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payslip_settlements')) {
            Schema::create('payslip_settlements', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('payslip_id', 26);
                $t->char('settled_payslip_id', 26);
                $t->char('employee_id', 26);
                $t->string('settled_code', 40);
                $t->date('from_date');
                $t->date('to_date');
                $t->decimal('presumed_amount', 12, 2);
                $t->decimal('actual_amount', 12, 2);
                $t->decimal('amount', 12, 2);
                $t->json('details');
                $t->dateTime('created_at', 3);
            });
        }
        if (! Schema::hasIndex('payslip_settlements', 'pss_settled_unq')) {
            Schema::table('payslip_settlements', static fn (Blueprint $t) => $t->unique('settled_payslip_id', 'pss_settled_unq'));
        }
        if (! Schema::hasIndex('payslip_settlements', 'pss_payslip_idx')) {
            Schema::table('payslip_settlements', static fn (Blueprint $t) => $t->index('payslip_id', 'pss_payslip_idx'));
        }
        if (! Schema::hasIndex('payslip_settlements', 'pss_employee_dates_idx')) {
            Schema::table('payslip_settlements', static fn (Blueprint $t) => $t->index(['employee_id', 'from_date', 'to_date'], 'pss_employee_dates_idx'));
        }
        if (! Schema::hasForeignKey('payslip_settlements', ['payslip_id'])) {
            Schema::table('payslip_settlements', static fn (Blueprint $t) => $t->foreign('payslip_id')->references('id')->on('payslips')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payslip_settlements', ['settled_payslip_id'])) {
            Schema::table('payslip_settlements', static fn (Blueprint $t) => $t->foreign('settled_payslip_id')->references('id')->on('payslips')->restrictOnDelete());
        }
        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payslip_settlements` ROW_FORMAT=DYNAMIC');
            if (! $this->hasCheck('pss_span_chk')) {
                DB::statement('ALTER TABLE `payslip_settlements` ADD CONSTRAINT `pss_span_chk` CHECK (`to_date` >= `from_date`)');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payslip_settlements')) {
            Schema::drop('payslip_settlements');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payslip_settlements' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
