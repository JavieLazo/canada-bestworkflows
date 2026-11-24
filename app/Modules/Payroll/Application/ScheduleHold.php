<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Timekeeping\Contracts\ScheduleNotices;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Access;
use App\Support\EmployeeName;

/**
 * A REGULAR PAYROLL IS NOT FINALIZED while a member has dates with NO SHIFT
 * in its period. Such a date is judged by the engine's own default week,
 * 08:00–17:00 Monday to Friday, which is nobody's schedule: a weekday with
 * no punches reads as an unpaid absence, and the employee could not have
 * punched, because a Time In needs a shift. The officer sets the schedule
 * in Shift Roster — a past date with no shift can be given one there — and
 * the draft follows.
 *
 * Only dates inside the member's employment count (hired to resigned): a
 * payslip leaves the others out. Every date of the period counts, the days
 * still ahead too, because a payroll counted through an earlier day pays
 * them as scheduled. The dates come from Timekeeping (`ScheduleNotices::noShiftDates`);
 * the workspace shows the hold and `FinalizePayroll` refuses by it.
 */
final class ScheduleHold
{
    /**
     * Members with dates with no shift inside their employment, and how many.
     *
     * @param  iterable<EmployeePayData>  $employees  the members
     * @return array<string, int> employee id => dates; only members with at least one
     */
    public static function noShiftDays(Access $actor, iterable $employees, string $start, string $end): array
    {
        $windows = [];
        foreach ($employees as $employee) {
            $windows[$employee->id] = [
                PayrollPeriod::dateKeyFromDate($employee->date_hired),
                $employee->date_resigned !== null ? PayrollPeriod::dateKeyFromDate($employee->date_resigned) : null,
            ];
        }
        if ($windows === []) {
            return [];
        }
        $out = [];
        foreach (app(ScheduleNotices::class)->noShiftDates($actor, array_keys($windows), $start, $end) as $employeeId => $dates) {
            [$hired, $resigned] = $windows[$employeeId] ?? [null, null];
            $inside = array_filter($dates, static fn (string $date): bool => ($hired === null || $date >= $hired) && ($resigned === null || $date <= $resigned));
            if ($inside !== []) {
                $out[$employeeId] = count($inside);
            }
        }

        return $out;
    }

    /**
     * "Ana Cruz (3 days)" for each held member, sorted by name.
     *
     * @param  iterable<EmployeePayData>  $employees
     * @param  array<string, int>  $days  from noShiftDays()
     * @return list<string>
     */
    public static function names(iterable $employees, array $days): array
    {
        $names = [];
        foreach ($employees as $employee) {
            if (isset($days[$employee->id])) {
                $count = $days[$employee->id];
                $names[] = EmployeeName::display($employee).' ('.$count.' '.($count === 1 ? 'day' : 'days').')';
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /** @param  list<string>  $names  from names() */
    public static function message(array $names): string
    {
        return 'Some dates in this period have no shift for '.implode(', ', $names).'. Set their schedule in Shift Roster before finalizing.';
    }
}
