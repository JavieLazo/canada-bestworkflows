<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Timekeeping\Contracts\AttendanceInputs;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\MonthFacts;

/**
 * One employee's month as the earning rule reads it (MonthFacts), from the
 * same DTR the Daily Time Record and payroll compute: the scheduled working
 * days of the month (a holiday is not one), those the employee was employed
 * on, those present (worked, on official business, or on paid leave, by
 * the leave's days) and those without pay the policy counts — approved
 * leave without pay, and absences less whatever credits already covered
 * (the charges a payroll posted, handed in by date). The balance before the
 * posting is the type's allocation less the leave used, as the ledger
 * reads it (LeaveBalances).
 */
final class LeaveMonthFacts
{
    private function __construct() {}

    /**
     * @param  array{from: string, to: string}  $month
     * @param  list<HolidaySetting>  $holidays
     * @param  list<string>  $unpaidCounts  LeavePolicy::unpaidCounts()
     * @return array{scheduled: int, employed: int, present: float, unpaid: float, absences: float}
     */
    public static function count(AttendanceInputs $facts, array $month, string $hired, ?string $resigned, array $holidays, array $unpaidCounts): array
    {
        $schedule = $facts->shift ?? Shift::default();
        $from = max($month['from'], $hired);
        $to = $resigned !== null && $resigned < $month['to'] ? $resigned : $month['to'];
        $scheduled = 0;
        foreach (PayrollPeriod::eachDate($month['from'], $month['to']) as $date) {
            if (ShiftResolver::worksOnDate($date, $schedule) && HolidaySetting::on($date, $holidays) === null) {
                $scheduled++;
            }
        }
        $employed = 0;
        $present = 0.0;
        $unpaid = 0.0;
        $absences = 0.0;
        if ($from <= $to) {
            foreach (DtrCalculator::compute($facts->logs, $facts->shift, $facts->leaves, $from, $to, $holidays) as $day) {
                if (! ShiftResolver::worksOnDate($day->date, $schedule) || HolidaySetting::on($day->date, $holidays) !== null) {
                    continue;
                }
                $employed++;
                if ($day->workedMinutes > 0 || $day->status === DayRecord::OFFICIAL_BUSINESS) {
                    $present += 1.0;
                } elseif ($day->excusePaid === true) {
                    $present += $day->excuseDays;
                }
                if ($day->excusePaid === false && in_array('UNPAID_LEAVE', $unpaidCounts, true)) {
                    $unpaid += $day->excuseDays;
                }
                // An absence is poor attendance whether or not a payroll
                // later charged it to credits: it counts in full.
                if ($day->isAbsenceDay($schedule)) {
                    $absences += 1.0;
                    if (in_array('ABSENCE', $unpaidCounts, true)) {
                        $unpaid += 1.0;
                    }
                }
            }
        }

        return [
            'scheduled' => $scheduled,
            'employed' => $employed,
            'present' => LeaveQuantity::normalize($present),
            'unpaid' => LeaveQuantity::normalize($unpaid),
            'absences' => LeaveQuantity::normalize($absences),
        ];
    }

    /**
     * The days without pay of a range, by date, as a waiting period's
     * extension counts them (LeaveAcquisitionRule): a scheduled working day
     * absent counts whole, approved leave without pay by its days, a
     * holiday and a rest day never — the same reading count() gives a
     * month. Lateness and undertime are not days and are not here.
     *
     * @param  list<HolidaySetting>  $holidays
     * @return array<string, float> 'Y-m-d' => the part of the day without pay
     */
    public static function unpaidByDate(AttendanceInputs $facts, string $from, string $to, array $holidays): array
    {
        $out = [];
        if ($from > $to) {
            return $out;
        }
        $schedule = $facts->shift ?? Shift::default();
        foreach (DtrCalculator::compute($facts->logs, $facts->shift, $facts->leaves, $from, $to, $holidays) as $day) {
            if (! ShiftResolver::worksOnDate($day->date, $schedule) || HolidaySetting::on($day->date, $holidays) !== null) {
                continue;
            }
            $days = ($day->excusePaid === false ? $day->excuseDays : 0.0) + ($day->isAbsenceDay($schedule) ? 1.0 : 0.0);
            if ($days > 0.0) {
                $out[$day->date] = min(1.0, $days);
            }
        }

        return $out;
    }

    /**
     * The facts for one leave type: the month's counts plus that type's
     * balance before the posting.
     *
     * @param  array{scheduled: int, employed: int, present: float, unpaid: float, absences: float}  $counts
     */
    public static function forType(array $counts, float $balanceBefore): MonthFacts
    {
        return new MonthFacts($counts['scheduled'], $counts['employed'], $counts['present'], $counts['unpaid'], $balanceBefore);
    }

    /**
     * A type's balance for a year as the ledger reads it: the allocation
     * less the approved leave with pay drawing on that year (LeaveBalances,
     * the one rule every reader shares).
     */
    public static function balance(string $employeeId, int $year, string $token): float
    {
        return LeaveBalances::balance($employeeId, $year, $token);
    }
}
