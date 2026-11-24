<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Attendance;

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * When a Daily Time Record day is OVER, and which days that are over count
 * on the DTR's Absences tile.
 *
 * The DTR computes through today, and the engine reads a workday with no
 * punch yet as ABSENT and a Time In still waiting for its Time Out as
 * INCOMPLETE. Both are right for a day that has ended, but taken at face
 * value, today would count as an absence before the shift has even
 * started. So a day counts only once it is over: when the latest its work
 * can end has passed, plus every hour of overtime the Formulas will pay for
 * (OT max, the same OpenPunchLimit the punch station expires an abandoned
 * Time In by).
 *
 *   - FIXED: the latest end is the shift's end on the date it starts — the
 *     next morning for a night shift. NOT midnight, because a night shift
 *     is still running then, and NOT the shift's end alone, because
 *     overtime may be.
 *   - FLEXIBLE: no end to measure from. A Time In after midnight is the
 *     next date's, so the latest start is midnight and the latest end is
 *     the owed hours after it. With the OT max on top, that is midnight
 *     plus ShiftResolver::openPunchExpiryMinutes, the station's own limit.
 *
 * Once over, a day counts when it has no complete Time In and Time Out —
 * DayRecord::isAbsenceDay, the same per-day test the payslip's
 * `absence_days` counts with. DtrCalculator and DtrSummary do not apply it.
 *
 * Pure: the schedule, the OT max and the current instant are passed in. A
 * date's hours are the schedule's for that date (Shift::forDate), as the
 * engine reads them.
 */
final class DayClose
{
    private const MINUTES_PER_DAY = 24 * 60;

    private function __construct() {}

    /** The instant `$date`'s attendance day is over, given the shift in force on it. */
    public static function at(string $date, Shift $shift, float $maxOvertimeHours): CarbonImmutable
    {
        $overtime = max(0.0, is_finite($maxOvertimeHours) ? $maxOvertimeHours : 0.0) * 60;

        $workEnds = $shift->isFlexible()
            ? self::MINUTES_PER_DAY + ShiftResolver::paidShiftMinutes($shift)
            : ShiftResolver::geometry($shift)->end;

        // An unreadable clock has no end to measure from. The writers never
        // store one, but a NaN must not decide the day: wait for the whole
        // next calendar day instead.
        if (! is_finite($workEnds)) {
            $workEnds = 2.0 * self::MINUTES_PER_DAY;
        }

        return LocalClock::instant($date.'T00:00:00')->addSeconds((int) floor(($workEnds + $overtime) * 60));
    }

    /** Whether `$date`'s attendance day is over at `$now`. */
    public static function isOver(string $date, Shift $shift, float $maxOvertimeHours, CarbonImmutable $now): bool
    {
        return LocalClock::ms($now) >= LocalClock::ms(self::at($date, $shift, $maxOvertimeHours));
    }

    /**
     * The Absences tile: the computed days that are over at `$now` and are
     * absence days. `$schedule` is the shift the days were computed against;
     * null is the engine's default shift, as it is for DtrCalculator.
     *
     * @param  list<DayRecord>  $days
     */
    public static function absences(array $days, ?Shift $schedule, float $maxOvertimeHours, CarbonImmutable $now): int
    {
        $schedule ??= Shift::default();

        $count = 0;
        foreach ($days as $day) {
            if ($day->isAbsenceDay($schedule) && self::isOver($day->date, $schedule->forDate($day->date), $maxOvertimeHours, $now)) {
                $count++;
            }
        }

        return $count;
    }
}
