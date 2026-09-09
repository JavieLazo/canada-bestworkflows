<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model Payslip` (prisma/schema.prisma:528-603). 44 columns, 31 of
 * them money.
 *
 * Every amount is DECIMAL in STORAGE. The COMPUTE is in plain PHP floats,
 * matching the JavaScript engine bit for bit through App\Support\Money::round().
 * Those are two different decisions and both are deliberate: the two systems
 * have to reconcile to the centavo, so the arithmetic must be identical, and
 * the stored value must not drift.
 *
 * The Eloquent model must cast these to 'float', NEVER 'decimal:2' — that cast
 * returns a PHP *string*, which is the exact defect src/lib/api/respond.ts:5-8
 * exists to fix.
 *
 * Column order follows the source declaration order, which is payslip order.
 *
 * Append-only: no updated_at. "This row was edited after posting" must stay a
 * meaningful signal, so the model manages timestamps itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();

            // The payroll run that posted it. NULL for payslips posted before
            // runs existed. This nullability is load-bearing — see the unique
            // index at the bottom.
            $t->char('run_id', 26)->nullable();
            $t->char('employee_id', 26);
            $t->date('period_start');
            $t->date('period_end');

            $t->decimal('daily_rate', 12, 2)->default(0);

            // Day counts. Disjoint: a worked holiday is counted in holiday_days
            // only. Fractional (half-day leave), so not integers.
            $t->decimal('work_days', 6, 2)->default(0);
            $t->decimal('holiday_days', 6, 2)->default(0);
            $t->decimal('rest_days', 6, 2)->default(0);
            $t->decimal('absence_days', 6, 2)->default(0);
            $t->decimal('unpaid_leave_days', 5, 2)->default(0);

            // Earnings, in payslip order. basic_pay is the sum of the three day
            // buckets; total_base_pay adds the hand-entered allowance to it.
            $t->decimal('work_days_pay', 12, 2)->default(0);
            $t->decimal('holiday_pay', 12, 2)->default(0);
            $t->decimal('rest_day_pay', 12, 2)->default(0);
            $t->decimal('basic_pay', 12, 2)->default(0);
            $t->decimal('allowances', 12, 2)->default(0);
            $t->decimal('total_base_pay', 12, 2)->default(0);

            // (10,2), NOT (12,2) — hours, not pesos.
            $t->decimal('overtime_hours', 10, 2)->default(0);

            // The computed amount, whether or not it was acknowledged.
            $t->decimal('overtime_pay', 12, 2)->default(0);

            // Overtime reaches gross pay only when this is true.
            $t->boolean('overtime_acknowledged')->default(false);

            // Night shift differential — hand-entered until NSD is computed.
            $t->decimal('night_differential', 12, 2)->default(0);

            // Hand-entered pay for working a day already filed as leave.
            $t->decimal('leave_pay', 12, 2)->default(0);
            $t->decimal('other_earnings', 12, 2)->default(0);
            $t->decimal('bonus', 12, 2)->default(0);
            $t->decimal('thirteenth_month', 12, 2)->default(0);
            $t->decimal('gross_pay', 12, 2)->default(0);

            // Deductions. The *_er columns are the employer share: reported on
            // the payslip, NOT included in total_deductions.
            $t->decimal('sss', 12, 2)->default(0);
            $t->decimal('sss_er', 12, 2)->default(0);
            $t->decimal('philhealth', 12, 2)->default(0);
            $t->decimal('philhealth_er', 12, 2)->default(0);
            $t->decimal('pagibig', 12, 2)->default(0);
            $t->decimal('pagibig_er', 12, 2)->default(0);
            $t->decimal('withholding_tax', 12, 2)->default(0);
            $t->decimal('other_deductions', 12, 2)->default(0);
            $t->integer('late_minutes')->default(0);
            $t->decimal('late_deduction', 12, 2)->default(0);
            $t->integer('undertime_minutes')->default(0);
            $t->decimal('undertime_deduction', 12, 2)->default(0);
            $t->decimal('total_deductions', 12, 2)->default(0);

            // CAN be negative; it is not clamped.
            $t->decimal('net_pay', 12, 2)->default(0);

            // Written by the pre-additive engine, which charged late and
            // undertime as one line and reduced a monthly base for absence.
            // Kept so payslips posted before the change stay readable; nothing
            // writes these now, and the current calculator pins them to 0 so
            // the reduction inside basic_pay is not double-counted.
            $t->decimal('late_undertime_deduction', 12, 2)->default(0);
            $t->decimal('absence_deduction', 12, 2)->default(0);
            $t->decimal('unpaid_leave_deduction', 12, 2)->default(0);

            $t->dateTime('created_at', 3);

            // Stops a re-finalise stacking a second set of payslips.
            //
            // run_id is NULLABLE and part of the key. MySQL/InnoDB and
            // PostgreSQL AGREE here: a UNIQUE index permits unlimited rows where
            // any indexed column is NULL, so pre-run payslips stay distinct
            // exactly as the source intended and as the demo store behaved.
            // DO NOT "improve" this into a NOT NULL column with a sentinel.
            // Key size: 26 + 3 + 3 + 26 = 58 bytes, nowhere near any limit.
            $t->unique(['employee_id', 'period_start', 'period_end', 'run_id'], 'payslips_period_run_unq');
            $t->index(['employee_id', 'period_start'], 'payslips_employee_period_idx');
            $t->index('run_id', 'payslips_run_idx');

            $t->foreign('run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
            $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
        });

        $this->dynamicRowFormat('payslips');
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }
};
