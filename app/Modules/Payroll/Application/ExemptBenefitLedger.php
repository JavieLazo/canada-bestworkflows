<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\Payslip;
use App\Support\Access;
use App\Support\Money;

/**
 * How much of each employee's PHP 90,000 yearly exemption is already spent.
 *
 * The one read behind `Domain\Benefits\ExemptBenefitCeiling`: the sum of
 * `payslips.ceiling_benefits` — the part of each payslip's exempt benefits
 * that spent the ceiling; a payslip posted before that column records it
 * apart counts its whole `exempt_benefits` — over the calendar year, per
 * employee. Payslip
 * rows exist only for FINALIZED runs (FinalizePayroll inserts them), so a
 * draft never counts; a payslip belongs to the year its period ENDS in,
 * because EMS stores no pay date and the pay date follows the period end.
 * The 13th-month run (1 January to 31 December) lands in the same year
 * either way.
 *
 * A caller MUST read it inside PayrollReadTransaction after
 * PayrollData::lockMembership() and capture the figures in the payroll
 * evidence: two runs for the same employee and year then finalize one after
 * the other, and the second sees what the first spent. Rows are scoped
 * through the actor like every other payroll read.
 */
final class ExemptBenefitLedger
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, float> employee id => exempt pesos already posted this year (0.0 when none)
     */
    public function usedBefore(Access $actor, array $employeeIds, int $year): array
    {
        $used = [];
        foreach ($employeeIds as $id) {
            $used[$id] = 0.0;
        }

        if ($used === []) {
            return [];
        }

        $rows = Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->whereIn('employee_id', array_keys($used))
            ->whereBetween('period_end', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(COALESCE(ceiling_benefits, exempt_benefits)) AS used_total')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $used[(string) $row->employee_id] = Money::round($row->used_total);
        }

        return $used;
    }
}
