<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;

/**
 * How long an employee rests between the shift of one date and the shift of
 * the next, by the SCHEDULED times alone — the Shift Roster's warning that a
 * change left someone less than eight hours between two shifts (a Night
 * that ends at 6:00 AM followed by an AM that starts at 6:00 AM rests none).
 *
 * A warning, never a refusal: the schedule may still be saved as it is, and
 * the DTR measures the punches either way. A flexible shift has no fixed
 * start or end, so nothing is judged across one.
 *
 * Pure: two detached shifts in, minutes out.
 */
final class RestBetweenShifts
{
    /** Less rest than this between two shifts is flagged on the roster. */
    public const MIN_REST_MINUTES = 480;

    private function __construct() {}

    /**
     * Minutes from the end of `$previous` (worked on the date before) to the
     * start of `$next`, or null when either has no fixed hours. A shift that
     * ends at or before its start runs past midnight into the next date.
     */
    public static function minutes(DomainShift $previous, DomainShift $next): ?float
    {
        if ($previous->isFlexible() || $next->isFlexible()) {
            return null;
        }
        $previousStart = ShiftResolver::clockMinutes($previous->startTime);
        $previousEnd = ShiftResolver::clockMinutes($previous->endTime);
        $nextStart = ShiftResolver::clockMinutes($next->startTime);
        if (is_nan($previousStart) || is_nan($previousEnd) || is_nan($nextStart)) {
            return null;
        }
        if ($previousEnd <= $previousStart) {
            $previousEnd += 1440;
        }

        return 1440 + $nextStart - $previousEnd;
    }

    /** The minutes of rest when they fall short of the minimum, else null. */
    public static function shortfall(DomainShift $previous, DomainShift $next): ?float
    {
        $minutes = self::minutes($previous, $next);

        return $minutes !== null && $minutes < self::MIN_REST_MINUTES ? max(0.0, $minutes) : null;
    }
}
