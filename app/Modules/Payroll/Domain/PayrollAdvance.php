<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Payroll\PayrollPeriod;

/**
 * The rule of an ADVANCE PAYROLL: a regular payroll processed before its
 * period ends, so that people are paid on the pay day. Its "attendance
 * counted through" date is the last day whose attendance it reads; the
 * scheduled working days after it are paid as full days
 * (PayslipCalculator, DELIBERATE DIVERGENCE 14) and what they turn out to be
 * worth is settled on the employee's next regular payroll.
 *
 * The date must lie inside the period and must be a day that is over —
 * yesterday at the latest, in Manila — so every counted day is read from
 * finished attendance. Blank, or the period's last day, means no advance:
 * the whole period is read from attendance, as the reference reads it.
 *
 * Pure: the caller passes today (ManilaClock::todayKey()).
 */
final class PayrollAdvance
{
    private function __construct() {}

    /**
     * The date as the run stores it: null for no advance (blank, or the
     * period's own last day, which counts everything).
     */
    public static function normalize(?string $through, string $end): ?string
    {
        if ($through === null || trim($through) === '') {
            return null;
        }
        $through = trim($through);

        return $through === $end ? null : $through;
    }

    /**
     * Null when `$through` (already normalized) may count a payroll over
     * `$start`..`$end` processed on `$today`; otherwise the refusal.
     */
    public static function error(?string $through, string $start, string $end, string $today): ?string
    {
        if ($through === null) {
            return null;
        }
        if (! PayrollPeriod::isPayrollDate($through)) {
            return 'Choose a valid date to count attendance through.';
        }
        if ($through < $start || $through > $end) {
            return 'Attendance can only be counted through a day inside the payroll period.';
        }
        if ($through >= $today) {
            return 'Attendance can be counted through yesterday at the latest: today\'s attendance is still being recorded.';
        }

        return null;
    }

    /**
     * The date a new draft starts with: yesterday while the period is still
     * running and yesterday is inside it; null when the period has ended, or
     * has not reached its second day.
     */
    public static function defaultThrough(string $start, string $end, string $today): ?string
    {
        if (! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $today > $end) {
            return null;
        }
        $yesterday = CalendarDays::previous($today);

        return $yesterday >= $start && $yesterday < $end ? $yesterday : null;
    }

    /**
     * ONE MEMBER's counted date: the run's counted date, pulled back to the
     * day before the member's first UNFINISHED day — a Time In without a
     * Time Out, which the Daily Time Record reads as INCOMPLETE — on or
     * before it. An unfinished day is assumed to finish: that day, and every
     * counted day after it, is presumed and settled on the member's next
     * payroll like the days after the counted date, instead of blocking the
     * finalization as an unpaired punch. The member's own date is what their
     * payslip carries, so the freeze and the settlement follow it.
     *
     * Null stays null: without an advance nothing is presumed, so an
     * unfinished day blocks as before. A first day unfinished gives the day
     * before the period, which presumes every day.
     *
     * @param  iterable<object{date: string, status: string}>  $days  the member's DTR rows (DayRecord), in any order
     */
    public static function countedThrough(?string $through, iterable $days): ?string
    {
        if ($through === null) {
            return null;
        }
        $counted = $through;
        foreach ($days as $day) {
            if ($day->status === 'INCOMPLETE' && strcmp($day->date, $counted) <= 0) {
                $counted = CalendarDays::previous($day->date);
            }
        }

        return $counted;
    }

    /**
     * The presumed days of a run: the day after the counted date through the
     * period's end, or null when nothing is presumed.
     *
     * @return array{from: string, to: string}|null
     */
    public static function presumedRange(?string $through, string $end): ?array
    {
        if ($through === null || $through >= $end) {
            return null;
        }

        return ['from' => CalendarDays::next($through), 'to' => $end];
    }
}
