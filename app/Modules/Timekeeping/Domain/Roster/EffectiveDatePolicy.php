<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;

/**
 * WHEN a schedule change may take effect: never in the past, today only
 * until today's shift has begun, and never in a way that leaves one shift
 * still running when the next one starts.
 *
 * Three rules, all pure — 'Y-m-d' strings, minutes past Manila midnight and
 * resolved schedules in, a sentence or a list out:
 *
 *   1. dateError(): a change starts today or later. Backdating is refused.
 *   2. todayCutoff() (D4): a change may apply TODAY only before the shift
 *      already scheduled for today starts, before the shift it proposes for
 *      today starts, and before any punch belongs to today's shift. A
 *      FLEXIBLE shift has no start, so for it only the first punch counts. A
 *      rest day, or an employee with no assigned schedule, has no shift to
 *      have started.
 *   3. overlaps() (D5): a shift belongs wholly to the date it STARTS, so a
 *      night that starts on a period's last date still ends the next morning.
 *      A schedule in which the next date's shift starts before that one's
 *      scheduled end is a conflict for HR to adjust — never cut short here.
 *      Scheduled start and end only; the overtime window is not part of it.
 *
 * Callers refuse with these sentences; nothing here decides to move a date.
 */
final class EffectiveDatePolicy
{
    private const MINUTES_PER_DAY = 1440.0;

    /** Null when `$requested` is a real calendar date, today or later. */
    public static function dateError(string $requested, string $today): ?string
    {
        if (! PayrollPeriod::isPayrollDate($requested)) {
            return 'Choose a valid calendar date.';
        }

        return $requested < $today ? 'A schedule change cannot start in the past. Choose today or a later date.' : null;
    }

    /**
     * Why a change can no longer take effect today, or null while it can.
     *
     * @param  DomainShift|null  $before  the schedule as it stands, resolved around today
     * @param  DomainShift|null  $after  the schedule the change would leave
     * @param  float  $nowMinutes  minutes past Manila midnight, now
     * @param  bool  $punched  whether a punch already belongs to today's shift
     */
    public static function todayCutoff(?DomainShift $before, ?DomainShift $after, string $today, float $nowMinutes, bool $punched): ?string
    {
        if ($punched) {
            return 'a punch is already recorded for today’s shift';
        }
        if (self::started($before, $today, $nowMinutes)) {
            return 'the shift scheduled for today has already started';
        }
        if (self::started($after, $today, $nowMinutes)) {
            return 'the new shift would already have started today';
        }

        return null;
    }

    /**
     * Every date in [`$from`, `$to`] whose shift is still scheduled to be
     * running when the following date's shift starts.
     *
     * @return list<array{date: string, ends: string, next: string, starts: string}>
     */
    public static function overlaps(?DomainShift $schedule, string $from, string $to): array
    {
        $schedule ??= DomainShift::default();
        $found = [];
        foreach ($from <= $to ? CalendarDays::each($from, $to) : [] as $date) {
            $next = CalendarDays::next($date);
            $shift = $schedule->forDate($date);
            $following = $schedule->forDate($next);
            if ($shift->isFlexible() || $following->isFlexible()
                || ! ShiftResolver::worksOnDate($date, $schedule) || ! ShiftResolver::worksOnDate($next, $schedule)) {
                continue;
            }
            $ends = ShiftResolver::geometry($shift)->end;
            $starts = ShiftResolver::geometry($following)->start + self::MINUTES_PER_DAY;
            if (is_finite($ends) && is_finite($starts) && $ends > $starts) {
                $found[] = ['date' => $date, 'ends' => substr($shift->endTime, 0, 5), 'next' => $next, 'starts' => substr($following->startTime, 0, 5)];
            }
        }

        return $found;
    }

    /**
     * Whether a FIXED shift scheduled for today has reached its start. No
     * schedule at all is no shift: the engine's default hours are a
     * fallback nobody assigned, so they cannot have "started".
     */
    private static function started(?DomainShift $schedule, string $today, float $nowMinutes): bool
    {
        if ($schedule === null) {
            return false;
        }
        $shift = $schedule->forDate($today);
        if ($shift->isFlexible() || ! ShiftResolver::worksOnDate($today, $schedule)) {
            return false;
        }
        $start = ShiftResolver::geometry($shift)->start;

        return is_finite($start) && $nowMinutes >= $start;
    }
}
