<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * JavaScript's `Math.round`, transcribed — the *whole-number* rounding
 * boundary of the DTR engine.
 *
 * copems/src/lib/dtr.ts reaches for `Math.round` eleven times and every one of
 * them rounds to a whole MINUTE, never to two decimals:
 *
 *     dtr.ts:136  Math.round(Number(required_hours ?? 8) * 60)
 *     dtr.ts:269  the same, inside computeDtr
 *     dtr.ts:353  Math.round((outMs - onTheClockFrom(in)) / 60000)   per pair
 *     dtr.ts:366  Math.round(inM - shiftStart)                       lateness
 *     dtr.ts:374  Math.round((outMs - onTheClockFrom(in)) / 60000)   day span
 *     dtr.ts:388  Math.round(requiredMinutes - workedMinutes)        FLEXIBLE
 *     dtr.ts:390  Math.round(shiftEnd - outM)                        undertime
 *     dtr.ts:421  Math.round(shiftLength / 2)                        half day
 *
 * WHY THIS CLASS EXISTS AND `LocalClock::minutesBetween()` IS NOT USED HERE
 * ------------------------------------------------------------------------
 * `LocalClock::minutesBetween()` is documented as
 * `Math.max(0, Math.round((outMs - inMs) / 60000))` but is implemented as
 * `max(0, (int) Money::round($ms / 60000))`. `Money::round()` rounds to TWO
 * DECIMALS and the `(int)` cast then TRUNCATES, so a pair 480.5 minutes long
 * comes back as 480 where JS returns 481. The fractional part of
 * `ms / 60000` is uniformly distributed across real punches, so that is an
 * off-by-one minute on roughly HALF of all in→out pairs — reported upstream.
 *
 * This class does the integer half-up rounding the reference actually
 * performs, while still taking its millisecond inputs from
 * `LocalClock::ms()`, which is the part of `LocalClock` that is correct and
 * which remains the single timestamp boundary.
 *
 * NB: `Money::round()` is untouched and remains THE peso boundary. This is a
 * different boundary — minutes, not centavos — and the two must not be mixed.
 */
final class Minutes
{
    /**
     * `Math.round(n)` — half-UP toward +Infinity, to a whole number.
     *
     *  - Uses `($n - floor($n)) >= 0.5` rather than `floor($n + 0.5)`, for the
     *    same reason Money::round() does: `Math.round(0.49999999999999994)` is
     *    0 in JS, but adding 0.5 to that value rounds up to exactly 1.0 in
     *    IEEE-754 before `floor` ever sees it.
     *  - Half-up, not half-away-from-zero: `Math.round(-0.5)` is `-0`, and
     *    `floor(-0.5) = -1`, `(-0.5 - -1) = 0.5 >= 0.5`, so `-1 + 1 = 0`.
     *    PHP's own `round(-0.5)` would give `-1`. Undertime and lateness are
     *    clamped at 0 downstream, but `Math.round(requiredMinutes -
     *    workedMinutes)` at dtr.ts:388 genuinely does see negatives.
     *  - Returns float, not int. JS numbers are doubles end to end, and a
     *    float return lets NaN/Infinity propagate the way the reference does
     *    instead of raising a TypeError inside a payroll finalize.
     */
    public static function round(float $n): float
    {
        // Math.round(NaN) is NaN and Math.round(±Infinity) is ±Infinity.
        if (is_nan($n) || is_infinite($n)) {
            return $n;
        }

        $floor = floor($n);

        return ($n - $floor) >= 0.5 ? $floor + 1.0 : $floor;
    }
}
