<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA ONLY: the four computed Payroll Deductions rows of the Philippine
 * payroll — SSS, PhilHealth, Pag-IBIG and Withholding Tax — are DELETED.
 *
 * A computed row on that list is what switches its deduction on and gives it
 * a column on the payroll grid, so removing the rows is what takes the four
 * off every draft payroll. The Canada Pension Plan, Employment Insurance and
 * income tax that replace them are not rows of this list: they are computed
 * for every pay from the published figures.
 *
 * The rows are found by their `calculation`, never by name or id, so a
 * renamed row goes too and a typed row that merely shares a name stays. No
 * typed line can point at a computed row, so nothing references them.
 * Posted payslips are untouched: their amounts are columns of the payslip,
 * and a finalized payroll reads its columns from its own captured evidence.
 * The Late / Undertime row and every typed row stay.
 *
 * RETRY-SAFE: a second run finds nothing to delete.
 *
 * ROLLBACK does not bring the rows back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_deductions') || ! Schema::hasColumn('payroll_deductions', 'calculation')) {
            return;
        }

        DB::table('payroll_deductions')->whereIn('calculation', ['SSS', 'PHILHEALTH', 'PAGIBIG', 'WITHHOLDING_TAX'])->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the four deductions are no longer computed.
    }
};
