<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Absence;

use App\Domain\Attendance\CalendarDays;
use App\Support\HrisFormat;

/**
 * The pure rules of leave credits (leave_credits, 2026_09_26_0003): which
 * leave types carry an entitlement, what a request costs against one, which
 * requests count, and what a typed credit may be. The reference has no
 * counterpart — only two numbers on the employee record that nothing
 * computes from.
 *
 * Nothing here reads a clock, a database or a screen: the query hands in the
 * requests, the command hands in the wire values, and both get the same
 * answers. DELIBERATE DIVERGENCE — filing requires enough remaining credits
 * for the selected leave type and start year, including Leave Without Pay.
 * Official business is exempt. Approval and the rules for counting usage
 * remain separate: pending requests do not reserve credits.
 */
final class LeaveCreditPolicy
{
    public const int YEAR_MIN = 2000;

    public const int YEAR_MAX = 2100;

    /** DECIMAL(6,2)'s ceiling, the range `lcr_credits_chk` pins on MySQL/MariaDB. */
    public const float MAX_CREDITS = 9999.99;

    public const string STEP_ERROR = 'Enter a number of days in half-day steps.';

    public const string RANGE_ERROR = 'Credits must be between 0 and 9999.99.';

    /**
     * Whether a Leave Settings type is one an employee can hold credits for.
     * Business travel is official business, not leave (the portal's own
     * exclusion), and a "without pay" type is by definition not an
     * entitlement — a column of credits for it would describe nothing.
     */
    public static function creditable(string $leaveTypeName): bool
    {
        return preg_match('/business|travel|without pay/i', $leaveTypeName) !== 1;
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
        return count(array_unique($dates)) * ($dayType === 'HALF_DAY' ? 0.5 : 1.0);
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

    /** The filing guard shared by the form and the command's fresh balance check. */
    public static function filingError(?float $remaining, float $needed, int $year): ?string
    {
        if ($remaining === null || $remaining <= 0) {
            return "You have no available leave credits for the selected leave type in {$year}.";
        }
        if ($needed > $remaining) {
            $neededDays = self::format($needed).' '.($needed === 1.0 ? 'day' : 'days');
            $availableDays = self::format($remaining).' '.($remaining === 1.0 ? 'day' : 'days');

            return "This request needs {$neededDays}, but your available balance for this leave type in {$year} is {$availableDays}.";
        }

        return null;
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
     * Whether a credit's key names a stored request token — the ONE `_LEAVE`
     * tolerance (`HrisFormat::requestTypeMatches`), so a legacy `SICK` row
     * counts against `SICK_LEAVE` credits by the same rule that labels it.
     */
    public static function matches(string $creditKey, string $requestType): bool
    {
        return HrisFormat::requestTypeMatches($creditKey, $requestType);
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
     * passes, an explicit '0' is a row of 0.00. Anything that is not a string,
     * int or float holding a number of whole or half days between 0 and
     * 9999.99 is refused (a bool or an array included), so the CHECK
     * constraint never has to be the thing that says no.
     */
    public static function creditError(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return self::STEP_ERROR;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (! is_numeric($text)) {
            return self::STEP_ERROR;
        }
        $days = (float) $text;
        if ($days < 0 || $days > self::MAX_CREDITS) {
            return self::RANGE_ERROR;
        }
        if (fmod($days, 0.5) !== 0.0) {
            return self::STEP_ERROR;
        }

        return null;
    }

    /**
     * The credit a wire value stands for, once `creditError` has passed: null
     * for a blank (delete the row), else the days as a float.
     */
    public static function credit(mixed $value): ?float
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';

        return $text === '' ? null : (float) $text;
    }

    /**
     * A day count as people write it: whole days bare, a half with one
     * decimal — `PortalLabels::dayCount`'s rule, stated here because module
     * code may not import a Livewire helper.
     */
    public static function format(float $days): string
    {
        return floor($days) === $days ? (string) (int) $days : number_format($days, 1, '.', '');
    }
}
