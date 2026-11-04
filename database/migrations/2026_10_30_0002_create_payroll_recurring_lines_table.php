<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * Recurring lines (Records → Payroll Settings → Employee Pay Settings): one
 * employee's standing payroll line — a typed Payroll Deductions row (a loan
 * amortization, a cash advance) or an Incentives & Bonuses type payroll
 * does not schedule on its own (a fixed allowance) — that every Basic
 * Payroll it is due on proposes by itself (Payroll's RecurringLine).
 *
 * `kind` names which catalog the line charges, and exactly the matching one
 * of `deduction_id` / `incentive_type_id` is set (RESTRICT: a catalog row a
 * line charges cannot be deleted). `amount` is one payroll's figure;
 * `total`, a deduction's only, is the balance it pays off — the posted
 * payslip lines naming the line (`payslip_deductions.recurring_line_id`,
 * 2026_10_30_0003) are what is paid. `frequency` is EVERY payroll or the
 * month's FIRST or SECOND cut-off; `starts_on` is a cut-off's first day and
 * `ends_on` the last day a period may start on (NULL: open-ended). `note`
 * is the officer's reference (a loan number). The employee's lines go with
 * the employee (CASCADE): an employee is deleted only when never paid, so
 * no posted line names them.
 *
 * RETRY-SAFE: the create, the index, each foreign key, the row format and
 * each MariaDB/MySQL CHECK have their own guard.
 *
 * ROLLBACK LOSES every recurring line; 2026_10_30_0003 must be rolled back
 * first (its foreign keys name this table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_recurring_lines')) {
            Schema::create('payroll_recurring_lines', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->string('kind', 10);
                $t->char('deduction_id', 26)->nullable();
                $t->char('incentive_type_id', 26)->nullable();
                $t->decimal('amount', 12, 2);
                $t->decimal('total', 12, 2)->nullable();
                $t->string('frequency', 10);
                $t->date('starts_on');
                $t->date('ends_on')->nullable();
                $t->string('note', 160)->nullable();

                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 200)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('payroll_recurring_lines', 'prl_employee_idx')) {
            Schema::table('payroll_recurring_lines', static fn (Blueprint $t) => $t->index(['employee_id', 'starts_on'], 'prl_employee_idx'));
        }
        if (! Schema::hasIndex('payroll_recurring_lines', 'prl_deduction_idx')) {
            Schema::table('payroll_recurring_lines', static fn (Blueprint $t) => $t->index('deduction_id', 'prl_deduction_idx'));
        }
        if (! Schema::hasIndex('payroll_recurring_lines', 'prl_incentive_type_idx')) {
            Schema::table('payroll_recurring_lines', static fn (Blueprint $t) => $t->index('incentive_type_id', 'prl_incentive_type_idx'));
        }

        if (! Schema::hasForeignKey('payroll_recurring_lines', ['employee_id'])) {
            Schema::table('payroll_recurring_lines', static fn (Blueprint $t) => $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_recurring_lines', ['deduction_id'])) {
            Schema::table('payroll_recurring_lines', static fn (Blueprint $t) => $t->foreign('deduction_id')->references('id')->on('payroll_deductions')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_recurring_lines', ['incentive_type_id'])) {
            Schema::table('payroll_recurring_lines', static fn (Blueprint $t) => $t->foreign('incentive_type_id')->references('id')->on('payroll_incentive_types')->restrictOnDelete());
        }
        if (! Schema::hasForeignKey('payroll_recurring_lines', ['created_by'])) {
            Schema::table('payroll_recurring_lines', static fn (Blueprint $t) => $t->foreign('created_by')->references('id')->on('users')->nullOnDelete());
        }

        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `payroll_recurring_lines` ROW_FORMAT=DYNAMIC');

            $checks = [
                'prl_kind_chk' => "(`kind` = 'DEDUCTION' AND `deduction_id` IS NOT NULL AND `incentive_type_id` IS NULL) OR (`kind` = 'INCENTIVE' AND `incentive_type_id` IS NOT NULL AND `deduction_id` IS NULL AND `total` IS NULL)",
                'prl_amount_chk' => '`amount` > 0 AND (`total` IS NULL OR `total` >= `amount`)',
                'prl_frequency_chk' => "`frequency` IN ('EVERY', 'FIRST', 'SECOND')",
                'prl_dates_chk' => '`ends_on` IS NULL OR `ends_on` >= `starts_on`',
            ];
            foreach ($checks as $name => $condition) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `payroll_recurring_lines` ADD CONSTRAINT `{$name}` CHECK ({$condition})");
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_recurring_lines')) {
            Schema::drop('payroll_recurring_lines');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_recurring_lines' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
