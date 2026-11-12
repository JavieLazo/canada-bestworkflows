<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;

/**
 * The dates an employee has NO SHIFT on: nothing decides their hours — no
 * hours of the weekday's own, no assigned default shift and no temporary
 * schedule — so the engine's own default stands in and the date is judged
 * by a yardstick nobody gave them. Exactly the dates `rosteredShift()`
 * finds nothing on, the punch station refuses a Time In on, and the
 * Shift Roster draws as "No shift". A rest day is a schedule, not one of
 * these.
 *
 * Both holds read it: finalizing a payroll and approving a leave or
 * official business wait until every such date has a schedule.
 *
 * Internal and trusted, like `ShiftRoster`: the callers authorize the actor
 * and the employees first. Three batched reads whatever the batch size.
 */
final class NoShiftDates
{
    public function __construct(private readonly ShiftRoster $roster) {}

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, list<string>> employee id => the dates of [$start, $end] with no shift, oldest first; only employees with at least one
     */
    public function between(array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === [] || ! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return [];
        }
        $out = [];
        foreach ($this->roster->explainedForEmployees($employeeIds, $start, $end) as $employeeId => $answer) {
            $dates = array_keys(array_filter($answer['sources'], static fn (string $source): bool => $source === ScheduleBasis::SOURCE_NONE));
            if ($dates !== []) {
                $out[(string) $employeeId] = array_map('strval', $dates);
            }
        }

        return $out;
    }
}
