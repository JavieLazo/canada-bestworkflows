<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paid-leave day count, the missing sibling of `unpaid_leave_days`.
 *
 * DIVERGENCE 6 moved approved paid leave off the work-day line and onto the
 * Leave line. `work_days` now counts only days the employee actually punched,
 * so `work_days x daily_rate` finally reconciles to `work_days_pay` — but an
 * employee whose Work days fell by two with an unchanged gross needs a line
 * saying where those two days went. This is that line.
 *
 * IT MUST BE STORED, not derived. A FINALIZED run is read back out of this
 * table and is never recomputed (`PayslipRecords::forRun`), so a derived
 * figure would be visible on a draft and blank on the payslip the employee
 * is actually handed.
 *
 * DECIMAL(5,2), matching `unpaid_leave_days` rather than the DECIMAL(6,2) of
 * `work_days`. A half day is the smallest unit the DTR can produce
 * (`DayRecord::excuseDays` is 0, 0.5 or 1), and no cut-off can reach 1,000
 * days of leave.
 *
 * DEFAULT 0 IS CORRECT HERE, and is not the nullable-override pattern the
 * payroll guide warns about. This is a computed payslip FIGURE like
 * `unpaid_leave_days`, not an operator override on `payroll_run_members`
 * where NULL has to stay distinguishable from a typed zero. Every payslip
 * already written was computed under the old engine, which credited paid
 * leave through `work_days`; zero is the truthful backfill for them.
 *
 * RETRY-SAFE. One DDL step, guarded on its own, so a cron run killed
 * mid-migration resumes cleanly. Calling `up()` twice is a no-op.
 *
 * ROLLBACK LOSES the stored day count on every finalized payslip. Nothing
 * recomputes it: finalized evidence is frozen by design, and the leave rows
 * it came from can be withdrawn or re-reviewed afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payslips', 'paid_leave_days')) {
            Schema::table('payslips', function (Blueprint $t): void {
                $t->decimal('paid_leave_days', 5, 2)->default(0)->after('unpaid_leave_days');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payslips', 'paid_leave_days')) {
            Schema::table('payslips', function (Blueprint $t): void {
                $t->dropColumn('paid_leave_days');
            });
        }
    }
};
