<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterDayOverride;
use App\Models\RosterScheduleChange;
use App\Modules\Timekeeping\Contracts\ScheduleNotices;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;

/**
 * Answers payroll's schedule notices from the dated schedule rows. Counts
 * and moments only — see the contract.
 */
final class ScheduleNoticeQuery implements ScheduleNotices
{
    public function __construct(private readonly ScheduleDayReader $reader) {}

    public function unscheduledDays(Access $actor, array $employeeIds, string $start, string $end): array
    {
        $employeeIds = $this->allowed($actor, $employeeIds);
        if ($employeeIds === [] || ! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end)) {
            return [];
        }
        // A date still ahead can be given a schedule: only what has been
        // judged already needs review.
        $end = min($end, ManilaClock::todayKey());
        $out = [];
        foreach ($this->reader->between($employeeIds, $start, $end) as $employeeId => $days) {
            $count = count(array_filter($days, static fn ($day): bool => $day->basis === ScheduleBasis::NONE));
            if ($count > 0) {
                $out[$employeeId] = $count;
            }
        }

        return $out;
    }

    public function changedSince(Access $actor, array $employeeIds, string $start, string $end, CarbonImmutable $since): array
    {
        $employeeIds = $this->allowed($actor, $employeeIds);
        if ($employeeIds === [] || ! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end)) {
            return [];
        }
        // A month's daily rate divides by the whole month's working days, so
        // a change anywhere in a touched month can move the amount.
        $monthsFrom = PayrollPeriod::calendarMonthRange($start)['from'];
        $monthsTo = PayrollPeriod::calendarMonthRange($end)['to'];
        $since = $since->utc();
        $latest = [];
        $note = static function (string $employeeId, ?CarbonImmutable $at) use (&$latest, $since): void {
            if ($at !== null && $at->greaterThan($since) && (! isset($latest[$employeeId]) || $at->greaterThan($latest[$employeeId]))) {
                $latest[$employeeId] = $at->utc();
            }
        };

        // A dated change in effect inside the months: saved or cancelled since.
        $changes = RosterScheduleChange::query()->whereIn('employee_id', $employeeIds)
            ->where('source', '!=', RosterScheduleChange::SOURCE_BASELINE)
            ->where('effective_from', '<=', $monthsTo)
            ->where(static fn ($q) => $q->where('created_at', '>', $since)->orWhere('cancelled_at', '>', $since))
            ->get(['employee_id', 'created_at', 'cancelled_at']);
        foreach ($changes as $change) {
            $note($change->employee_id, $change->created_at);
            $note($change->employee_id, $change->cancelled_at);
        }
        // A temporary schedule touching the months: saved, cut short or
        // cancelled since. A rename in place is not a change of hours.
        $assignments = RosterDayOverride::query()->whereIn('employee_id', $employeeIds)
            ->where('date', '<=', $monthsTo)
            ->whereRaw('COALESCE(end_date, date) >= ?', [$monthsFrom])
            ->where(static fn ($q) => $q->where('created_at', '>', $since)->orWhere('cancelled_at', '>', $since)
                ->orWhere(static fn ($cut) => $cut->whereNotNull('cut_after')->where('updated_at', '>', $since)))
            ->get(['employee_id', 'created_at', 'updated_at', 'cancelled_at', 'cut_after']);
        foreach ($assignments as $row) {
            $note($row->employee_id, $row->created_at);
            $note($row->employee_id, $row->cancelled_at);
            if ($row->cut_after !== null) {
                $note($row->employee_id, $row->updated_at);
            }
        }

        return $latest;
    }

    /**
     * The employees this actor may ask about: everyone with all-data on
     * PAYROLL, otherwise only themselves.
     *
     * @param  list<string>  $employeeIds
     * @return list<string>
     */
    private function allowed(Access $actor, array $employeeIds): array
    {
        if (! $actor->hasModule(ModuleCode::PAYROLL)) {
            return [];
        }
        $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (string $id): bool => $id !== '')));
        if ($actor->mayReadAllData(ModuleCode::PAYROLL)) {
            return $employeeIds;
        }

        return $actor->hasEmployeeLink() ? array_values(array_intersect($employeeIds, [$actor->ownEmployeeId()])) : [];
    }
}
