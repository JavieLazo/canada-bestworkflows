<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterScheduleChange;
use App\Modules\Timekeeping\Contracts\Views\ScheduleDayView;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Support\HrisFormat;

/**
 * Each date's schedule with its basis, for a batch of employees: what
 * decided the hours (`ShiftRoster::explainedForEmployees`) judged against
 * the employee's BASELINE markers (`ScheduleBasis`).
 *
 * Four reads whatever the batch size. Internal and trusted, like
 * `ShiftRoster`: the public query and the payroll contract authorize the
 * actor and the employees before they call this.
 */
final class ScheduleDayReader
{
    /** The longest range answered: a year of rows is already more than any screen shows. */
    public const MAX_DAYS = 366;

    public function __construct(private readonly ShiftRoster $roster) {}

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, ScheduleDayView>> employee id => 'Y-m-d' => view; empty for a range that is not one
     */
    public function between(array $employeeIds, string $start, string $end): array
    {
        if (PayrollPeriod::isPayrollDate($start) && PayrollPeriod::isPayrollDate($end)) {
            $end = min($end, PayrollPeriod::addCalendarDays($start, self::MAX_DAYS - 1));
        }
        $explained = $this->roster->explainedForEmployees($employeeIds, $start, $end);
        if ($explained === []) {
            return [];
        }
        $baselines = RosterScheduleChange::query()->whereIn('employee_id', $employeeIds)
            ->where('source', RosterScheduleChange::SOURCE_BASELINE)
            ->get(['employee_id', 'assumed_before', 'may_differ_through'])->keyBy('employee_id');

        $out = [];
        foreach ($explained as $employeeId => $answer) {
            $schedule = $answer['schedule'] ?? DomainShift::default();
            $baseline = $baselines->get($employeeId);
            $assumedBefore = $baseline?->assumed_before?->format('Y-m-d');
            $mayDiffer = $baseline?->may_differ_through?->format('Y-m-d');
            $days = [];
            foreach ($answer['sources'] as $date => $source) {
                $shift = $schedule->forDate($date);
                $days[$date] = new ScheduleDayView(
                    date: $date,
                    source: $source,
                    basis: ScheduleBasis::of($date, $source, $assumedBefore, $mayDiffer),
                    works: ShiftResolver::worksOnDate($date, $schedule),
                    hours: $shift->isFlexible()
                        ? 'Flexible · '.HrisFormat::hoursPerDay($shift->requiredHoursOrDefault())
                        : HrisFormat::hm($shift->startTime).'–'.HrisFormat::hm($shift->endTime),
                );
            }
            $out[$employeeId] = $days;
        }

        return $out;
    }
}
