<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Attendance;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftDayBoundary;
use App\Domain\Attendance\ShiftGeometry;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;

/**
 * Which Manila calendar date a live punch belongs to — the shift START date
 * a DTR row is keyed on (`.claude/rules/time-and-dtr.md`: "A DTR row
 * belongs to its shift START date").
 *
 * Two rules, held here so the punch command and the station preview
 * (Queries\RosterLocations) read ONE copy:
 *
 *   1. forInstant(): on an OVERNIGHT shift, an instant whose minute of day
 *      is at or before the raw end (a 05:30 on a 22:00–06:00 night) belongs
 *      to the previous date. A day shift, a FLEXIBLE shift (filler clock,
 *      never overnight — see ShiftResolver's header) and the default shift
 *      all key on the instant's own date.
 *   2. forPunch(): a TIME_OUT that closes an open TIME_IN takes THAT
 *      TIME_IN's date, however late it lands — a 06:30 Time Out past the raw
 *      end of a 22:00–06:00 night still ends the night that started the day
 *      before. Everything else is rule 1 on "now".
 *
 * This date also decides WHERE the punch is judged: the roster location of
 * that weekday (roster_locations), so an after-midnight Time Out is measured
 * against the site — or the home — the shift started at, never the next
 * morning's.
 *
 * Date and weekly schedules use forScheduledInstant / forScheduledPunch so rule 1
 * reads yesterday's geometry and rule 2 reads the open Time In's schedule.
 * The geometry-only entry points retain the uniform-schedule rules.
 *
 * Pure: value objects and instants in, a 'Y-m-d' string out. The only
 * framework dependency is ManilaClock, the single timezone boundary.
 */
final class PunchWorkDate
{
    private function __construct() {}

    /**
     * Resolve against the night that STARTED yesterday. Today's weekday may
     * have entirely different hours, so its geometry cannot own the morning
     * tail of yesterday's shift.
     */
    public static function forScheduledInstant(CarbonImmutable $instant, ?DomainShift $schedule): string
    {
        $previous = CalendarDays::previous(ManilaClock::dayKey($instant));

        return self::forInstant($instant, ShiftResolver::geometry($schedule?->forDate($previous)));
    }

    /**
     * A late Time Out closes the actual open Time In under that instant's
     * date's schedule, even when today's shift has changed from night to day.
     *
     * @param  list<DomainLog>  $windowLogs
     */
    public static function forScheduledPunch(array $windowLogs, string $logType, CarbonImmutable $now, ?DomainShift $schedule): string
    {
        $latest = self::latest($windowLogs);
        if ($logType === DomainLog::TIME_OUT && $latest !== null && $latest->logType === DomainLog::TIME_IN) {
            return self::scheduledTimeInDate($windowLogs, $latest, $schedule);
        }
        if ($logType === DomainLog::TIME_IN) {
            return self::scheduledTimeInDate($windowLogs, new DomainLog(DomainLog::TIME_IN, DomainLog::isoFromUtc($now->utc())), $schedule);
        }

        return self::forScheduledInstant($now, $schedule);
    }

    /** @param list<DomainLog> $windowLogs */
    private static function scheduledTimeInDate(array $windowLogs, DomainLog $timeIn, ?DomainShift $schedule): string
    {
        $instant = ManilaClock::instant($timeIn->loggedAt);
        $date = ManilaClock::dayKey($instant);
        if ($schedule !== null && $schedule->hasScheduleOverrides()
            && ShiftDayBoundary::belongsToFollowingShift(
                $windowLogs, $timeIn, $schedule->forDate(CalendarDays::previous($date)), $schedule->forDate($date), $date,
            )) {
            return $date;
        }

        return self::forScheduledInstant($instant, $schedule);
    }

    /**
     * @param  list<DomainLog>  $windowLogs  the employee's punches around now, in any order
     */
    public static function forPunch(array $windowLogs, string $logType, CarbonImmutable $now, ShiftGeometry $geometry): string
    {
        $latest = self::latest($windowLogs);
        if ($logType === DomainLog::TIME_OUT && $latest !== null && $latest->logType === DomainLog::TIME_IN) {
            return self::forInstant(ManilaClock::instant($latest->loggedAt), $geometry);
        }

        return self::forInstant($now, $geometry);
    }

    public static function forInstant(CarbonImmutable $instant, ShiftGeometry $geometry): string
    {
        $date = ManilaClock::dayKey($instant);

        if ($geometry->overnight && ManilaClock::minutesOfDay($instant) <= $geometry->rawEnd) {
            return PayrollPeriod::addCalendarDays($date, -1);
        }

        return $date;
    }

    /**
     * @param  list<DomainLog>  $windowLogs
     */
    private static function latest(array $windowLogs): ?DomainLog
    {
        if ($windowLogs === []) {
            return null;
        }

        usort(
            $windowLogs,
            static fn (DomainLog $a, DomainLog $b): int => ManilaClock::ms(ManilaClock::instant($a->loggedAt))
                <=> ManilaClock::ms(ManilaClock::instant($b->loggedAt)),
        );

        return $windowLogs[count($windowLogs) - 1];
    }
}
