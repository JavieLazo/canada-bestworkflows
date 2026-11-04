<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — `payslip_deductions.recurring_line_id` and
 * `payslip_incentives.recurring_line_id`: which recurring line
 * (2026_10_30_0002) a posted line paid, NULL for every line posted before
 * recurring lines existed and for every line that pays none.
 *
 * This is the recurring lines' ledger: what a deduction's total has left is
 * the total less the posted lines naming it. A posted payslip never changes,
 * so the foreign key RESTRICTs: a recurring line anything was posted against
 * is ended, never deleted.
 *
 * RETRY-SAFE: each column, index and foreign key has its own guard. down()
 * drops the columns and so forgets what each recurring line was paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payslip_deductions' => 'psd', 'payslip_incentives' => 'psi'] as $table => $prefix) {
            if (! Schema::hasColumn($table, 'recurring_line_id')) {
                Schema::table($table, static fn (Blueprint $t) => $t->char('recurring_line_id', 26)->nullable());
            }
            if (! Schema::hasIndex($table, "{$prefix}_recurring_line_idx")) {
                Schema::table($table, static fn (Blueprint $t) => $t->index('recurring_line_id', "{$prefix}_recurring_line_idx"));
            }
            if (! Schema::hasForeignKey($table, ['recurring_line_id'])) {
                Schema::table($table, static fn (Blueprint $t) => $t->foreign('recurring_line_id')->references('id')->on('payroll_recurring_lines')->restrictOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (['payslip_deductions' => 'psd', 'payslip_incentives' => 'psi'] as $table => $prefix) {
            if (Schema::hasForeignKey($table, ['recurring_line_id'])) {
                Schema::table($table, static fn (Blueprint $t) => $t->dropForeign(['recurring_line_id']));
            }
            if (Schema::hasIndex($table, "{$prefix}_recurring_line_idx")) {
                Schema::table($table, static fn (Blueprint $t) => $t->dropIndex("{$prefix}_recurring_line_idx"));
            }
            if (Schema::hasColumn($table, 'recurring_line_id')) {
                Schema::table($table, static fn (Blueprint $t) => $t->dropColumn('recurring_line_id'));
            }
        }
    }
};
