<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;

/** A recurring week and each exception month need a workday for payroll's divisor. */
final class RosterSchedulePolicy
{
    public static function error(?Shift $schedule): ?string
    {
        if (ShiftResolver::workWeek($schedule) === []) {
            return 'Keep at least one working day in the weekly schedule.';
        }
        $months = [];
        foreach (array_keys($schedule->dateShifts ?? []) as $date) {
            $months[substr($date, 0, 7)] = PayrollPeriod::calendarMonthRange($date);
        }
        foreach ($months as $month => $range) {
            if (ShiftResolver::scheduledWorkDaysForShift($range['from'], $range['to'], $schedule) === 0) {
                return "Keep at least one working day in {$month} so payroll can calculate a daily rate.";
            }
        }

        return null;
    }
}
