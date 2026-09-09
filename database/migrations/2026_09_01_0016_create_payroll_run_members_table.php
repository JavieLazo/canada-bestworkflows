<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * Replaces `PayrollRun.employee_ids String[]` (prisma/schema.prisma:495) plus
 * the three per-employee draft JSON columns beside it. MySQL has no array type
 * and there is no JSON fallback for the `hasSome` overlap query, so the pivot is
 * forced — but it is also the change the source's own schema comment asked for:
 *
 *     "Kept as an array rather than a join table so the shape matches what
 *      run-workspace.tsx already reads. The cost is no referential integrity:
 *      deleting an employee leaves a dangling id here. Worth revisiting when
 *      the finalize handler is written."
 *
 * Every override column is NULLABLE, and that is load-bearing. The source's
 * draft schemas (src/lib/payroll-run-schema.ts) make every field `.optional()`,
 * and the calculator distinguishes "the manager did not enter one" (fall back to
 * the computed value) from "the manager entered zero" (override to zero). A
 * DEFAULT 0 would collapse the two and silently zero out real contributions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_members', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->char('run_id', 26);
            $t->char('employee_id', 26);

            // calculatePayrollDrafts maps over employee_ids IN ORDER and the
            // resulting drafts array order is user-visible. Preserve it.
            $t->unsignedInteger('position')->default(0);

            // draft_allowances[employee_id] — a bare money value in the source.
            $t->decimal('allowance', 12, 2)->nullable();

            // draft_deductions[employee_id] — the exact key set from
            // src/lib/payroll-run-schema.ts:8-19.
            $t->decimal('deduction_sss', 12, 2)->nullable();
            $t->decimal('deduction_philhealth', 12, 2)->nullable();
            $t->decimal('deduction_pagibig', 12, 2)->nullable();
            $t->decimal('deduction_withholding_tax', 12, 2)->nullable();
            $t->decimal('deduction_other', 12, 2)->nullable();

            // draft_deductions[...].absence_deduction is LEGACY: accepted for
            // drafts created before absence moved into basic pay, and ignored by
            // the calculation layer when it recomputes totals. Carried so an old
            // draft round-trips without surprising anyone.
            $t->decimal('deduction_absence', 12, 2)->nullable();

            // draft_earnings[employee_id] — the earnings nothing derives yet
            // (src/lib/payroll-run-schema.ts:25-33), plus the switch that lets
            // computed overtime into gross pay. Nullable for the same
            // unset-vs-zero reason as the deductions above.
            $t->decimal('earning_night_differential', 12, 2)->nullable();
            $t->decimal('earning_leave_pay', 12, 2)->nullable();
            $t->decimal('earning_other', 12, 2)->nullable();
            $t->boolean('overtime_acknowledged')->nullable();

            // Denormalised from the run so "one payroll per employee per
            // cut-off" (src/lib/payroll-validation.ts:95-122) becomes a database
            // constraint instead of a checked-then-raced read — which matters
            // more here than it did on PostgreSQL, because the
            // pg_advisory_xact_lock that protected it is being replaced by the
            // `locks` table.
            $t->date('period_start');
            $t->date('period_end');

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();

            // Explicit short names: MySQL's identifier limit is 64 characters
            // and the convention-derived name for the first of these is 60+.
            $t->unique(['run_id', 'employee_id'], 'prm_run_employee_unq');
            $t->unique(['employee_id', 'period_start', 'period_end'], 'prm_employee_period_unq');
            $t->index('employee_id', 'prm_employee_idx');

            $t->foreign('run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
            $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
        });

        $this->dynamicRowFormat('payroll_run_members');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_members');
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
