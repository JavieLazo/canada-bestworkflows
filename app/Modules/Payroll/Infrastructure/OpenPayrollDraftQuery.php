<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\PayrollRun;
use App\Modules\Timekeeping\Contracts\OpenPayrollDrafts;
use Illuminate\Database\Eloquent\Builder;

/**
 * Names the drafts a schedule change can move, for the roster's confirmation.
 * Run code, name and period only — no amount and no other member leaves
 * payroll. Trusted internal read: the roster command has authorized the
 * actor and names one employee it may already manage.
 */
final class OpenPayrollDraftQuery implements OpenPayrollDrafts
{
    public function touching(string $employeeId, string $from, ?string $to = null): array
    {
        $runs = PayrollRun::query()
            ->where('status', PayrollRunStatus::DRAFT)
            ->where('kind', PayrollRunKind::REGULAR)
            ->whereHas('members', static fn (Builder $members) => $members->where('employee_id', $employeeId))
            ->orderBy('period_start')->orderBy('id')
            ->get(['id', 'code', 'name', 'period_start', 'period_end']);

        $touched = [];
        foreach ($runs as $run) {
            $start = $run->period_start->format('Y-m-d');
            $end = $run->period_end->format('Y-m-d');
            // The day's value comes from each month the period touches.
            $monthsFrom = PayrollPeriod::calendarMonthRange($start)['from'];
            $monthsTo = PayrollPeriod::calendarMonthRange($end)['to'];
            if ($monthsTo >= $from && ($to === null || $monthsFrom <= $to)) {
                $touched[] = ['code' => $run->code, 'name' => $run->name, 'period_start' => $start, 'period_end' => $end];
            }
        }

        return $touched;
    }
}
