<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

/**
 * How far a date's schedule can be trusted as history (D9, Q7) — the label
 * the Daily Time Record puts beside the shift a date was measured against.
 *
 *   - NONE: nothing was assigned for the date — no default shift, weekday
 *     hours or temporary schedule — so the engine's own default stands in.
 *     Its figures are an UNVERIFIED FALLBACK, never anyone's history. THIS
 *     WINS OVER EVERYTHING BELOW: an employee who was never given a schedule
 *     has nothing to assume.
 *   - MAY_DIFFER: before history was kept, and on or before the last day the
 *     audit trail records a change to this employee's schedule. What the
 *     date was really measured against may not be what is stored now.
 *   - ASSUMED: before history was kept, with no such record. The stored
 *     schedule is taken to have applied; nothing proves it.
 *   - RECORDED: history was kept on the date, so the schedule is the one
 *     that applied.
 *
 * Pure: 'Y-m-d' strings in, a constant out.
 */
final class ScheduleBasis
{
    public const RECORDED = 'RECORDED';

    public const ASSUMED = 'ASSUMED';

    public const MAY_DIFFER = 'MAY_DIFFER';

    public const NONE = 'NONE';

    /** What decided a date's hours. */
    public const SOURCE_TEMPORARY = 'TEMPORARY';

    public const SOURCE_WEEKLY = 'WEEKLY';

    public const SOURCE_DEFAULT = 'DEFAULT';

    public const SOURCE_NONE = 'NONE';

    /**
     * @param  string  $source  one of the SOURCE_* constants
     * @param  string|null  $assumedBefore  the day history began for this employee; null when it was kept from their first day
     * @param  string|null  $mayDifferThrough  the last day, before that, with a recorded schedule change
     */
    public static function of(string $date, string $source, ?string $assumedBefore, ?string $mayDifferThrough): string
    {
        if ($source === self::SOURCE_NONE) {
            return self::NONE;
        }
        if ($assumedBefore === null || $date >= $assumedBefore) {
            return self::RECORDED;
        }

        return $mayDifferThrough !== null && $date <= $mayDifferThrough ? self::MAY_DIFFER : self::ASSUMED;
    }
}
