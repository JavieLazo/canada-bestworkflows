<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single rounding boundary for every peso in the system.
 *
 * This function IS the payroll spec. The reference engine is
 * copems/src/lib/payroll.ts, where `round()` is:
 *
 *     Math.round((Number.isFinite(n) ? n : 0) * 100) / 100
 *
 * applied at ~24 named points.
 *
 * PHP's round($x, 2) is NOT that function. It applies a pre-rounding
 * correction JavaScript does not: round(1.005, 2) is 1.01 in PHP and 1.00
 * in JS. Verified divergence at monthly_rate = 10241.00 — PhilHealth
 * 128.01 vs 128.02, net pay 4642.49 vs 4642.48. Sweeping every whole-peso
 * salary from 10,000 to 100,000, round($x, 2) mispays the PhilHealth
 * employee line on 3,242 of 90,001 salaries (3.6%).
 *
 * The mechanism worth naming: contribution() divides an already-2dp-rounded
 * table value by 2, so every odd-centavo table value manufactures an exact
 * half-centavo. That single line is why 1 employee in 28 is exposed.
 *
 * NEVER call PHP's round() with a precision argument in the payroll domain.
 * An architecture test enforces this.
 */
final class Money
{
    /**
     * A literal transcription of Math.round(n * 100) / 100.
     *
     *  - Floor-based, so it is half-UP (toward +Infinity), matching JS on
     *    negatives too. net_pay is deliberately unclamped and CAN be negative.
     *  - Uses ($scaled - $floor) >= 0.5 rather than floor($scaled + 0.5), so it
     *    reproduces JS's Math.round(0.49999999999999994) === 0. Adding 0.5 to
     *    that value rounds up to exactly 1.0 in IEEE-754 before the floor sees it.
     *  - Accepts mixed, never a typed float: NaN/Inf/garbage must collapse to 0.0
     *    exactly as Number() coercion does. A typed float parameter would throw a
     *    TypeError inside the finalize transaction instead of zeroing one field.
     */
    public static function round(mixed $n): float
    {
        $f = is_numeric($n) ? (float) $n : 0.0;

        if (! is_finite($f)) {
            return 0.0;
        }

        $scaled = $f * 100;
        $floor = floor($scaled);
        $rounded = ($scaled - $floor) >= 0.5 ? $floor + 1 : $floor;

        $out = $rounded / 100;

        // Defensive belt only, NOT a correctness requirement: the golden vectors
        // compare per-field with assertSame rather than by whole-object JSON, so
        // the "-0" string form is never on the comparison path.
        return $out === 0.0 ? 0.0 : $out;
    }

    /**
     * The spreadsheet's ROUND(n, d): half-up at a chosen number of decimals,
     * reading a value a few binary digits short of a half as the half it is
     * in decimal. 181.82 × 1.25 is 227.275 on paper and 227.27499… as a
     * float; ROUND gives 227.28, and so does this.
     *
     * Only for a Pay Basis & Rates version that rounds (PayRules
     * `rounding.decimals`, DIVERGENCE 16): its daily rate, its hourly rate
     * and each premium amount. ALL OTHER PESOS STAY ON round() above, which
     * reproduces the reference and truncates that same value.
     */
    public static function roundTo(mixed $n, int $decimals): float
    {
        $f = is_numeric($n) ? (float) $n : 0.0;
        if (! is_finite($f)) {
            return 0.0;
        }

        $scale = 10 ** max(0, min(6, $decimals));
        $scaled = $f * $scale;
        // Far above a float's error after a few multiplications, far below
        // any real fraction of a centavo.
        $scaled += abs($scaled) * 1e-12;
        $floor = floor($scaled);
        $rounded = ($scaled - $floor) >= 0.5 ? $floor + 1 : $floor;
        $out = $rounded / $scale;

        return $out === 0.0 ? 0.0 : $out;
    }
}
