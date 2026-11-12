<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Absence;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;

/**
 * The pure rules of leave credits (leave_credits, 2026_09_26_0003): which
 * leave types carry an entitlement, what a request costs against one, which
 * requests count, and what a typed credit may be. The reference has no
 * counterpart — only two numbers on the employee record that nothing
 * computes from.
 *
 * Nothing here reads a clock, a database or a screen: the query hands in the
 * requests, the command hands in the wire values, and both get the same
 * answers. A SHORT BALANCE NEVER REFUSES A FILING: the request is filed,
 * the employee is told which days the credits do not cover, and approval
 * pays the days the credits cover and leaves the rest without pay
 * (LeavePaySplit). Pending requests do not reserve credits.
 *
 * A REQUEST COSTS ITS SCHEDULED WORKING DAYS (the owner's decision, PPMC's
 * leave-card practice): `workingDates` keeps only the dates of the range the
 * employee's schedule works that are not holidays — the same per-day test
 * the DTR pays leave by, so a Monday-to-Friday fortnight costs 10, not 14 —
 * and `ReviewAbsence` freezes that count on the request when it approves.
 * `coveredDays`, every calendar day of the range, is what the rows approved
 * before that column existed are still read by.
 */
final class LeaveCreditPolicy
{
    public const int YEAR_MIN = 2000;

    public const int YEAR_MAX = 2100;

    /** DECIMAL(10,4)'s ceiling, the range `lcr_credits_chk` pins on MySQL/MariaDB (LeaveQuantity). */
    public const float MAX_CREDITS = LeaveQuantity::MAX;

    /** A typed credit holds up to four decimals: credits are not money, and a month's earning is 1.167. */
    public const string STEP_ERROR = LeaveQuantity::FORMAT_ERROR;

    public const string RANGE_ERROR = LeaveQuantity::RANGE_ERROR;

    /**
     * Whether a Leave Settings name is LEAVE at all: business travel is
     * official business, filed apart and never leave (the portal's own
     * exclusion). Which leave types hold credits is how HR set each one to
     * be filed (LeaveTypeCredits::FILING_CREDITS), never its name.
     */
    public static function creditable(string $leaveTypeName): bool
    {
        return preg_match('/business|travel/i', $leaveTypeName) !== 1;
    }

    /** What one counted date of a request costs: half for a half-day request, else one. */
    public static function unit(?string $dayType): float
    {
        return $dayType === 'HALF_DAY' ? 0.5 : 1.0;
    }

    /**
     * What a request costs: every calendar day it covers, inclusive, at 0.5
     * for a HALF_DAY request and 1 otherwise — the engine's per-day
     * `excuseDays` unit (a half-day range charges 0.5 per covered day, not
     * 0.5 in total). Calendar days by design: rest days and holidays inside
     * the range count, because the entitlement is entered in the same terms.
     * A null day type is a whole day, exactly as the DTR reads a missing one.
     * Cancelled dates no longer consume credits; the original range and
     * its start year remain the approval evidence.
     * Takes validated 'Y-m-d' strings; `start > end` costs nothing.
     *
     * @param  list<string>  $cancelledDates
     */
    public static function coveredDays(?string $dayType, string $start, string $end, array $cancelledDates = []): float
    {
        return self::selectedDays($dayType, self::activeDays($start, $end, $cancelledDates));
    }

    /**
     * Credit units for a date selection use the same whole/half-day rule as usage.
     *
     * @param  list<string>  $dates
     */
    public static function selectedDays(?string $dayType, array $dates): float
    {
        return LeaveQuantity::normalize(count(array_unique($dates)) * self::unit($dayType));
    }

    /**
     * The dates of a range that cost credits: those the schedule works
     * (`ShiftResolver::worksOnDate`; no schedule means `Shift::default()`,
     * exactly as the DTR judges an unrostered employee) and that are not
     * holidays, less the dates handed in as excluded — cancelled dates, or
     * dates a posted payroll already charged to credits as an absence.
     * Takes validated 'Y-m-d' strings; `start > end` is empty.
     *
     * @param  list<HolidaySetting>  $holidays
     * @param  list<string>  $excluded
     * @return list<string>
     */
    public static function workingDates(?Shift $schedule, array $holidays, string $start, string $end, array $excluded = []): array
    {
        $schedule ??= Shift::default();
        $out = [];
        foreach (self::activeDays($start, $end, $excluded) as $date) {
            if (ShiftResolver::worksOnDate($date, $schedule) && HolidaySetting::on($date, $holidays) === null) {
                $out[] = $date;
            }
        }

        return $out;
    }

    /**
     * Covered dates that still belong to a request, including its rest days.
     *
     * @param  list<string>  $cancelledDates
     * @return list<string>
     */
    public static function activeDays(string $start, string $end, array $cancelledDates = []): array
    {
        return array_values(array_diff(CalendarDays::each($start, $end), $cancelledDates));
    }

    /**
     * Overlap needs only the first active date, not an expanded long range.
     *
     * @param  list<string>  $cancelledDates
     */
    public static function hasActiveDate(string $start, string $end, array $cancelledDates = []): bool
    {
        $cancelled = array_fill_keys($cancelledDates, true);
        for ($date = $start; $date <= $end; $date = CalendarDays::next($date)) {
            if (! isset($cancelled[$date])) {
                return true;
            }
        }

        return false;
    }

    /** Leave, as opposed to official business, which is worked time off-site. */
    public static function isLeave(string $requestType): bool
    {
        return $requestType !== AbsencePolicy::OFFICIAL_BUSINESS;
    }

    /**
     * Whether a request of this type and pay state draws on credits once it
     * is (or has been) approved: leave, and not "without pay". Branch on
     * official business FIRST — its `is_paid` is null by design, and null on
     * a leave row reads as paid, exactly as `DtrCalculator` reads it.
     */
    public static function wouldConsume(string $requestType, ?bool $isPaid): bool
    {
        return self::isLeave($requestType) && $isPaid !== false;
    }

    /** A request that counts against the balance NOW: approved, and one that would consume. */
    public static function consumes(string $status, string $requestType, ?bool $isPaid): bool
    {
        return $status === 'APPROVED' && self::wouldConsume($requestType, $isPaid);
    }

    /**
     * The ONE credit key a request is charged to: its exact token when a
     * credit carries it, else the `_LEAVE` spelling when that one does, else
     * none. Exact wins so that an employee holding both a stale `SICK` row
     * and a current `SICK_LEAVE` row is never charged twice for one request.
     *
     * @param  list<string>  $creditKeys
     */
    public static function resolveKey(string $requestType, array $creditKeys): ?string
    {
        if (in_array($requestType, $creditKeys, true)) {
            return $requestType;
        }
        $suffixed = $requestType.'_LEAVE';

        return in_array($suffixed, $creditKeys, true) ? $suffixed : null;
    }

    /**
     * The year a request is charged to: the year of its START date. A range
     * across New Year is charged whole to the year it starts in — one rule,
     * stated here, rather than a split nobody could see on either screen.
     */
    public static function yearOf(string $startDate): int
    {
        return (int) substr($startDate, 0, 4);
    }

    /**
     * Why a typed credit is refused, or null when it is acceptable. Wire
     * values arrive as strings ('12.5', '', '0'); a blank means "no row" and
     * passes, an explicit '0' is a row of 0. Anything that is not a string,
     * int or float holding a number of days with up to four decimals
     * between 0 and 999999.9999 is refused (a bool or an array included),
     * so the CHECK constraint never has to be the thing that says no
     * (LeaveQuantity).
     */
    public static function creditError(mixed $value): ?string
    {
        return LeaveQuantity::error($value);
    }

    /**
     * The credit a wire value stands for, once `creditError` has passed: null
     * for a blank (delete the row), else the days settled to four decimals.
     */
    public static function credit(mixed $value): ?float
    {
        return LeaveQuantity::parse($value);
    }

    /**
     * A day count as people write it: whole days bare, otherwise without
     * trailing zeros (1.25, 1.167, 43.3025) — LeaveQuantity's rule, stated
     * here because module code may not import a Livewire helper.
     */
    public static function format(float $days): string
    {
        return LeaveQuantity::format($days);
    }
}
