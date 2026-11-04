<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Payroll\StatutoryLines;
use App\Models\Payslip;
use App\Models\PayslipIncentive;
use App\Modules\Payroll\Application\AdvanceSettlements;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * What a 13th month run stands on, per employee: the basic pay EMS paid
 * them in the calendar year, and the taxable pay of their latest regular
 * payslip that year.
 *
 * Only REGULAR pay counts: payslips of a REGULAR run, or of none (the
 * earliest payslips carry no run) — never a 13th month payslip. A payslip
 * exists only once its run is FINALIZED, so a draft never counts, and it
 * belongs to the year its period ENDS in, like the exempt-benefit ledger.
 * "Basic pay" is `work_days_pay + leave_pay` (ThirteenthMonthPay's docblock
 * says why).
 *
 * A caller reads it inside PayrollReadTransaction with the rest of the run's
 * inputs, and a finalize after PayrollData::lockMembership(), so a regular
 * payroll finalized meanwhile is either wholly in or wholly out. Rows are
 * scoped through the actor like every payroll read.
 */
final class ThirteenthMonthBasis
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array{basic_pay: float, regular_taxable: ?float}> every id asked for (0.0 / null when none)
     */
    public function forEmployees(Access $actor, array $employeeIds, int $year): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->whereIn('employee_id', $employeeIds)
            ->where('period_end', '>=', sprintf('%04d-01-01', $year))
            ->where('period_end', '<=', sprintf('%04d-12-31', $year))
            ->where(static fn (Builder $query) => $query
                ->whereNull('run_id')
                ->orWhereHas('run', static fn (Builder $run) => $run->where('kind', PayrollRunKind::REGULAR)))
            ->orderBy('period_end')
            ->orderBy('created_at')
            ->get(['id', 'employee_id', 'period_end', 'work_days_pay', 'leave_pay', 'taxable_compensation', 'created_at',
                'gross_pay', 'exempt_benefits', 'sss', 'philhealth', 'pagibig', 'late_deduction', 'undertime_deduction',
                // The settlement of earlier presumed days is basic pay for
                // those days (the advance payroll); 0 before the column exists.
                ...(AdvanceSettlements::ready() ? ['settlement'] : [])]);

        // Posted benefit lines the catalog flagged as basic pay when they
        // were paid (the copy on the line, never the catalog as it stands).
        $counted = [];
        if ($rows->isNotEmpty() && Schema::hasColumn('payslip_incentives', 'counts_toward_13th')) {
            foreach (PayslipIncentive::query()->whereIn('payslip_id', $rows->pluck('id')->all())->where('counts_toward_13th', true)
                ->groupBy('payslip_id')->selectRaw('payslip_id, SUM(amount) AS counted_total')->toBase()->get() as $line) {
                $counted[(string) $line->payslip_id] = (float) $line->counted_total;
            }
        }

        $sums = [];
        $latest = [];
        foreach ($rows as $row) {
            $id = (string) $row->employee_id;
            $sums[$id] = ($sums[$id] ?? 0.0) + $row->work_days_pay + $row->leave_pay + ($counted[(string) $row->id] ?? 0.0) + (float) ($row->getAttribute('settlement') ?? 0.0);
            // Ordered oldest first, so the last one read is the latest. A
            // payslip that predates the `taxable_compensation` column
            // (2026_09_26_0004) has it NULL: its base is worked out again from
            // its stored lines, as PayrollData records it at posting — gross
            // less the tax-exempt part, the three shares, late and undertime.
            // So null means only "no regular payslip that year".
            $latest[$id] = $row->taxable_compensation ?? Money::round(StatutoryLines::taxable(
                $row->gross_pay - $row->exempt_benefits, $row->sss, $row->philhealth, $row->pagibig,
                $row->late_deduction, $row->undertime_deduction,
            ));
        }

        $basis = [];
        foreach ($employeeIds as $id) {
            $basis[$id] = ['basic_pay' => Money::round($sums[$id] ?? 0.0), 'regular_taxable' => $latest[$id] ?? null];
        }

        return $basis;
    }
}
