<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSettlement;
use App\Modules\Payroll\Application\AdvanceSettlements;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Support\Access;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Answers Timekeeping's question from Payroll's own persistence only: a
 * payslip row exists only once FinalizePayroll posted it, so any payslip of
 * the employee whose period covers the date means the day is paid.
 *
 * An ADVANCE PAYROLL's payslip (PayrollAdvance) paid the days after its
 * counted date as scheduled, not as read, so it freezes only the days
 * through `attendance_through`; those later days are frozen by the payslip
 * that settles them (`payslip_settlements`, read beside the payslips here).
 * Before the column exists, every payslip freezes through its period's end,
 * as before.
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
    public function coveredPeriods(Access $actor, array $employeeIds, string $start, string $end, string $module = ModuleCode::EDTR): array
    {
        if (! $actor->hasModule($module)
            || $employeeIds === [] || ! PayrollPeriod::isPayrollDate($start)
            || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return [];
        }

        $advance = AdvanceSettlements::ready();
        $rows = $this->regular()
            ->toEmployee($actor, 'employee_id', $module)
            ->whereIn('employee_id', $employeeIds)
            ->where('period_start', '<=', $end)
            ->where(self::paidThroughColumn($advance), '>=', $start)
            ->orderBy('employee_id')->orderBy('period_start')
            ->get(['employee_id', 'period_start', 'period_end', ...($advance ? ['attendance_through'] : [])]);
        /** @var array<string, list<array{start: string, end: string}>> $periods */
        $periods = [];
        foreach ($rows as $row) {
            $from = max($start, $row->period_start->format('Y-m-d'));
            $to = min($end, self::paidThrough($row));
            // A payslip counted through the day before its period (every day
            // presumed, PayrollAdvance::countedThrough) froze nothing of it.
            if ($from > $to) {
                continue;
            }
            $periods[$row->employee_id][] = ['start' => $from, 'end' => $to];
        }
        if ($advance) {
            // The presumed days a later payslip settled are paid days too.
            $settled = PayslipSettlement::query()
                ->whereIn('payslip_id', $this->regular()->toEmployee($actor, 'employee_id', $module)->whereIn('employee_id', $employeeIds)->select('id'))
                ->where('from_date', '<=', $end)->where('to_date', '>=', $start)
                ->orderBy('employee_id')->orderBy('from_date')
                ->get(['employee_id', 'from_date', 'to_date']);
            foreach ($settled as $row) {
                $periods[$row->employee_id][] = [
                    'start' => max($start, $row->from_date->format('Y-m-d')),
                    'end' => min($end, $row->to_date->format('Y-m-d')),
                ];
            }
            foreach ($periods as &$list) {
                usort($list, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
            }
            unset($list);
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
        $advance = AdvanceSettlements::ready();
        if ($this->posted($employeeId)
            ->where('period_start', '<=', $date)
            ->where(self::paidThroughColumn($advance), '>=', $date)
            ->exists()) {
            return true;
        }

        return $advance && $this->settled($employeeId)
            ->where('from_date', '<=', $date)
            ->where('to_date', '>=', $date)
            ->exists();
    }

    public function lockAndPaidThrough(string $employeeId, string $from, ?string $to = null): ?string
    {
        PayrollData::lockMembership();

        $advance = AdvanceSettlements::ready();
        $column = self::paidThroughColumn($advance);
        $last = $this->posted($employeeId)
            ->where($column, '>=', $from)
            ->when($to !== null, static fn (Builder $query) => $query->where('period_start', '<=', $to))
            ->max($column);
        $last = $last === null ? null : substr((string) $last, 0, 10);
        if ($advance) {
            $settledLast = $this->settled($employeeId)
                ->where('to_date', '>=', $from)
                ->when($to !== null, static fn (Builder $query) => $query->where('from_date', '<=', $to))
                ->max('to_date');
            if ($settledLast !== null) {
                $settledLast = substr((string) $settledLast, 0, 10);
                $last = $last === null ? $settledLast : max($last, $settledLast);
            }
        }

        return $last;
    }

    public function lockAndPaidDates(string $employeeId, string $from, string $to): array
    {
        PayrollData::lockMembership();

        if ($from > $to) {
            return [];
        }
        $advance = AdvanceSettlements::ready();
        $dates = [];
        $rows = $this->posted($employeeId)
            ->where('period_start', '<=', $to)
            ->where(self::paidThroughColumn($advance), '>=', $from)
            ->get(['period_start', 'period_end', ...($advance ? ['attendance_through'] : [])]);
        foreach ($rows as $row) {
            foreach (PayrollPeriod::eachDate(max($from, $row->period_start->format('Y-m-d')), min($to, self::paidThrough($row))) as $date) {
                $dates[$date] = true;
            }
        }
        if ($advance) {
            foreach ($this->settled($employeeId)->where('from_date', '<=', $to)->where('to_date', '>=', $from)->get(['from_date', 'to_date']) as $row) {
                foreach (PayrollPeriod::eachDate(max($from, $row->from_date->format('Y-m-d')), min($to, $row->to_date->format('Y-m-d'))) as $date) {
                    $dates[$date] = true;
                }
            }
        }
        ksort($dates, SORT_STRING);

        return array_keys($dates);
    }

    /**
     * The last day a payslip's period froze: its counted date on an advance
     * payroll (PayrollAdvance), else its period's end. The days after the
     * counted date are presumed, not paid as read, and stay open until the
     * payslip that settles them posts (`settled()`).
     */
    private static function paidThrough(Payslip $row): string
    {
        $through = $row->getAttribute('attendance_through');

        return $through instanceof \DateTimeInterface ? $through->format('Y-m-d') : $row->period_end->format('Y-m-d');
    }

    /** The same answer in SQL, for the WHERE and MAX clauses; `period_end` alone before the column exists. */
    private static function paidThroughColumn(bool $advance): Expression|string
    {
        return $advance ? DB::raw('COALESCE(attendance_through, period_end)') : 'period_end';
    }

    /**
     * The settlements of this employee's presumed days, as a locking read
     * like `posted()`: each names the days a later posted payslip paid as
     * they stood, so they are frozen like any paid day.
     *
     * @return Builder<PayslipSettlement>
     */
    private function settled(string $employeeId): Builder
    {
        return PayslipSettlement::query()->sharedLock()->where('employee_id', $employeeId);
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
