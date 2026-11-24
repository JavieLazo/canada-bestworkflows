<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Support\LocalClock;

/** A completed overnight row can hand the clock to a different weekday's early Time In. */
final class ShiftDayBoundary
{
    /**
     * The same decision governs live punches and scheduled-break carry-over.
     * A changed clock alone is insufficient: the preceding shift must have
     * its complete one-pair or two-pair sequence before this new Time In.
     *
     * @param  list<AttendanceLog>  $logs  The preceding and current calendar days, in any order.
     */
    public static function belongsToFollowingShift(array $logs, AttendanceLog $candidate, Shift $previous, Shift $current, string $date): bool
    {
        if ($candidate->logType !== AttendanceLog::TIME_IN) {
            return false;
        }
        $previousGeometry = ShiftResolver::geometry($previous);
        $currentGeometry = ShiftResolver::geometry($current);
        $previousBreak = ShiftResolver::scheduledBreakWindow($previous, $previousGeometry);
        if (! $previousGeometry->overnight
            || ($previousGeometry == $currentGeometry && $previous->isFlexible() === $current->isFlexible()
                && $previousBreak === ShiftResolver::scheduledBreakWindow($current, $currentGeometry))) {
            return false;
        }
        $instant = LocalClock::instant($candidate->loggedAt);
        if (LocalClock::dayKey($instant) !== $date) {
            return false;
        }
        $minute = LocalClock::minutesOfDay($instant);
        if (! $current->isFlexible()
            && ($minute < $currentGeometry->start - ShiftResolver::EARLY_PUNCH_WINDOW_MINUTES
                || $minute >= $currentGeometry->end)) {
            return false;
        }

        $opens = LocalClock::ms(LocalClock::instant(CalendarDays::previous($date).'T'.$previous->startTime))
            - (ShiftResolver::EARLY_PUNCH_WINDOW_MINUTES * 60000);
        $until = LocalClock::ms($instant);
        usort($logs, static fn (AttendanceLog $a, AttendanceLog $b): int => LocalClock::ms(LocalClock::instant($a->loggedAt))
            <=> LocalClock::ms(LocalClock::instant($b->loggedAt)));
        $pairs = 0;
        $open = false;
        foreach ($logs as $log) {
            $at = LocalClock::ms(LocalClock::instant($log->loggedAt));
            if ($at < $opens || $at >= $until) {
                continue;
            }
            if ($log->logType === AttendanceLog::TIME_IN) {
                $open = true;
            } elseif ($log->logType === AttendanceLog::TIME_OUT && $open) {
                $pairs++;
                $open = false;
            }
        }

        return ! $open && $pairs >= ($previousBreak === null ? 1 : 2);
    }
}
