<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * One DRAFT payroll for the current local month's first cut-off with every
 * seeded employee on it — the port of seed.ts run-2. The reference's
 * FINALIZED run-1 and its posted payslips are deliberately NOT seeded:
 * finalising is a flow the user will exercise, and a pre-finalized run would
 * pre-empt it.
 *
 * Member rows carry NO overrides (all null): null is "the manager did not
 * enter one", which the calculator distinguishes from an explicit zero.
 *
 * A payroll 1 the user has already FINALIZED is left completely alone — a
 * re-seed must never flip a posted payroll back to draft.
 */
final class PayrollRunSeeder extends PortSeeder
{
    public function run(): void
    {
        $employees = $this->employeeIdsByIndex();

        if ($employees === []) {
            return;
        }

        $match = $this->codeMatch(PayrollRun::class, 'code', 1, 'PR');
        $seededId = $this->seedId(PayrollRun::class, 'run-1');
        $existing = $seededId === null
            ? PayrollRun::query()->where($match)->first()
            : PayrollRun::query()->find($seededId);

        if ($existing !== null && $existing->status === 'FINALIZED') {
            return;
        }

        $month = CarbonImmutable::now(LocalClock::zone())->startOfMonth();
        $periodStart = $month->format('Y-m-d');
        $periodEnd = $month->addDays(14)->format('Y-m-d');

        $run = $this->upsert(PayrollRun::class, $match, [
            'id' => 'run-1',
            'name' => $month->format('F Y').' — 1st cut-off',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'DRAFT',
            'finalized_at' => null,
            'finalized_by' => null,
            'created_at' => $this->at(2, 9, 0),
        ]);

        foreach ($employees as $i => $employeeId) {
            $this->upsert(PayrollRunMember::class, [
                'run_id' => (string) $run->getKey(),
                'employee_id' => $employeeId,
            ], [
                'id' => 'prm-'.($i + 1),
                // calculatePayrollDrafts maps members IN ORDER and the drafts
                // array order is user-visible. Preserve it.
                'position' => $i,
                'allowance' => null,
                'deduction_sss' => null,
                'deduction_philhealth' => null,
                'deduction_pagibig' => null,
                'deduction_withholding_tax' => null,
                'deduction_other' => null,
                'deduction_absence' => null,
                'earning_night_differential' => null,
                'earning_leave_pay' => null,
                'earning_other' => null,
                'overtime_acknowledged' => null,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
        }
    }
}
