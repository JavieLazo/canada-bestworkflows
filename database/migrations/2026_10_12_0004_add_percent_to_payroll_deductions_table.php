<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Percentage deductions: a Payroll Deductions row may carry `percent`, the
 * JSON figures `App\Modules\Payroll\Domain\Deductions\PercentDeduction`
 * reads — an employee share, an employer share, the base, how often it is
 * taken, a monthly cap and whether it is deducted before tax. The row keeps
 * `calculation` NULL: a percentage row is an officer's own deduction priced
 * by the engine, not one of the statutory tags, so `pded_calculation_unq`
 * still holds and a typed amount on the row stays the officer's override.
 * NULL means a typed row, as before.
 *
 * Posted copies (`payslip_deductions`) gain `employer_amount`: the employer
 * share the line reported when it was paid, NULL on a typed line and on
 * every copy posted before this column.
 *
 * RETRY-SAFE, STEP BY STEP: each column stands behind its own guard.
 * ROLLBACK LOSES the percentage rules (every such row becomes typed) and
 * the posted employer shares.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_deductions', 'percent')) {
            Schema::table('payroll_deductions', static function (Blueprint $t): void {
                $t->json('percent')->nullable();
            });
        }
        if (! Schema::hasColumn('payslip_deductions', 'employer_amount')) {
            Schema::table('payslip_deductions', static function (Blueprint $t): void {
                $t->decimal('employer_amount', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payslip_deductions', 'employer_amount')) {
            Schema::table('payslip_deductions', static fn (Blueprint $t) => $t->dropColumn('employer_amount'));
        }
        if (Schema::hasColumn('payroll_deductions', 'percent')) {
            Schema::table('payroll_deductions', static fn (Blueprint $t) => $t->dropColumn('percent'));
        }
    }
};
