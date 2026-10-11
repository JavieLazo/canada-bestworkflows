<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS on `payslips`: the PHP 90,000 exempt-benefit ledger and the
 * taxable compensation a payslip was taxed on.
 *
 * DELIBERATE DIVERGENCE from the reference `model Payslip` (copems
 * prisma/schema.prisma), which has neither: its engine paid no 13th month and
 * no bonus, so nothing could reach the ceiling, and it never needed to say
 * what a payslip's withholding was computed on.
 *
 *  - `exempt_benefits` DECIMAL(12,2) NOT NULL DEFAULT 0 — the part of this
 *    payslip's 13th month pay and tax-exempt benefits that stayed under the
 *    ceiling. The ledger (`ExemptBenefitLedger`) sums it per employee and
 *    calendar year, so it is the ONLY record of how much of the allowance is
 *    spent.
 *  - `taxable_benefits_excess` DECIMAL(12,2) NOT NULL DEFAULT 0 — the part
 *    over the ceiling, which this payslip taxed.
 *  - `taxable_compensation` DECIMAL(12,2) NULL — the taxable base the
 *    payslip's withholding was computed on. NULL means "not recorded": every
 *    payslip posted before this column existed. Recorded so that tax on a
 *    supplementary payment (the part of a 13th month over the ceiling, for
 *    one) can be withheld at the bracket the employee's regular pay sits in.
 *
 * Every existing payslip reads 0 exempt and 0 excess, which is exactly what
 * it paid: EMS posted no exempt benefit before this release. There is no
 * backfill and no index (the ledger's per-employee reads already ride
 * `payslips_employee_period_idx`, 2026_09_01_0017).
 *
 * NO CHECK constraint, on purpose. The values are derived by the engine,
 * never typed, and floored at 0 by `Domain\Benefits\ExemptBenefitCeiling`;
 * adding a CHECK to `payslips` would make MariaDB validate every posted row
 * instead of adding the columns instantly, and the runbook's phpMyAdmin
 * backup drops CHECKs anyway.
 *
 * The columns are appended without `->after()`, which keeps each ADD instant
 * on MariaDB 11.4 (the table is ROW_FORMAT=DYNAMIC, 2026_09_01_0017) instead
 * of rebuilding an append-only table that grows every cut-off.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can be killed between any two statements, and MySQL DDL does not roll
 * back. Each column is its own ALTER behind its own hasColumn() guard, so a
 * run killed after the first resumes at the second. down() mirrors it, one
 * guarded DROP per column.
 *
 * ROLLBACK LOSES the ledger: every employee's allowance silently resets to
 * the full PHP 90,000 for any run computed afterwards, and posted payslips
 * forget what their withholding was computed on. The posted amounts
 * themselves (gross, net, the lines) are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payslips', 'exempt_benefits')) {
            Schema::table('payslips', function (Blueprint $t): void {
                $t->decimal('exempt_benefits', 12, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('payslips', 'taxable_benefits_excess')) {
            Schema::table('payslips', function (Blueprint $t): void {
                $t->decimal('taxable_benefits_excess', 12, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('payslips', 'taxable_compensation')) {
            Schema::table('payslips', function (Blueprint $t): void {
                $t->decimal('taxable_compensation', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['taxable_compensation', 'taxable_benefits_excess', 'exempt_benefits'] as $column) {
            if (Schema::hasColumn('payslips', $column)) {
                Schema::table('payslips', function (Blueprint $t) use ($column): void {
                    $t->dropColumn($column);
                });
            }
        }
    }
};
