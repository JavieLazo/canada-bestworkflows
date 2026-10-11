<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\Payslip;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answers Timekeeping's question from Payroll's own persistence only: a
 * payslip row exists only once FinalizePayroll posted it, so any payslip of
 * the employee whose period covers the date means the day is paid.
 *
 * The write guards take PayrollData::lockMembership() first, inside the caller's
 * transaction, which serialises the answer with every payroll save and
 * finalize: a decision cannot slip in between a finalize's capture and its
 * commit, and a finalize that follows sees the decision (and asks for a
 * fresh review). Only REGULAR pay counts — a payslip of a REGULAR run, or
 * a legacy one with no run: a 13th month payslip spans 1 January to 31
 * December and pays no attendance, so it must never freeze a day. The
 * DTR's batched display read uses the same coverage without
 * locks; the command rechecks under its lock before any approval.
 */
final class PostedPayrollDateQuery implements PostedPayrollDates
{
    public function coveredPeriods(Access $actor, array $employeeIds, string $start, string $end): array
    {
        if (! $actor->hasModule(ModuleCode::EDTR)
            || $employeeIds === [] || ! PayrollPeriod::isPayrollDate($start)
            || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return [];
        }

        $rows = $this->regular()
            ->toEmployee($actor, 'employee_id', ModuleCode::EDTR)
            ->whereIn('employee_id', $employeeIds)
            ->where('period_start', '<=', $end)
            ->where('period_end', '>=', $start)
            ->orderBy('employee_id')->orderBy('period_start')
            ->get(['employee_id', 'period_start', 'period_end']);
        $periods = [];
        foreach ($rows as $row) {
            $periods[$row->employee_id][] = [
                'start' => max($start, $row->period_start->format('Y-m-d')),
                'end' => min($end, $row->period_end->format('Y-m-d')),
            ];
        }

        return $periods;
    }

    public function lock(): void
    {
        PayrollData::lockMembership();
    }

    public function lockAndCovers(string $employeeId, string $date): bool
    {
        PayrollData::lockMembership();

        // A LOCKING read (LOCK IN SHARE MODE): it always sees the latest
        // committed payslips, even if the caller's snapshot was fixed before
        // the lock was granted — a plain SELECT would read that older
        // snapshot and miss a payslip a finalize just posted.
        return $this->posted($employeeId)
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->exists();
    }

    public function lockAndPaidThrough(string $employeeId, string $from, ?string $to = null): ?string
    {
        PayrollData::lockMembership();

        $last = $this->posted($employeeId)
            ->where('period_end', '>=', $from)
            ->when($to !== null, static fn (Builder $query) => $query->where('period_start', '<=', $to))
            ->max('period_end');

        return $last === null ? null : substr((string) $last, 0, 10);
    }

    /**
     * The employee's posted REGULAR (or no-run) payslips, as a locking read.
     *
     * @return Builder<Payslip>
     */
    private function posted(string $employeeId): Builder
    {
        return $this->regular()
            ->sharedLock()
            ->where('employee_id', $employeeId);
    }

    /** @return Builder<Payslip> */
    private function regular(): Builder
    {
        return Payslip::query()
            ->where(static fn (Builder $query) => $query
                ->whereNull('run_id')
                ->orWhereHas('run', static fn (Builder $run) => $run->where('kind', PayrollRunKind::REGULAR)));
    }
}
