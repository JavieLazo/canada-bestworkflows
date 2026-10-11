<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Attendance;

use App\Domain\Attendance\Minutes;
use App\Support\HrisFormat;

/**
 * What the Daily Time Record may decide about a day's overtime, and how a
 * decision reads afterwards.
 *
 * Overtime is approved per day on the DTR, and payroll pays only the
 * approved minutes (PayslipCalculator divergence 7). This class holds the
 * rules both the command and the screen apply, so the dialog never offers
 * what the save refuses:
 *
 *  - A day is DECIDABLE when it computed at least one whole minute of
 *    overtime (Minutes::round, the same whole-minute boundary as the
 *    engine's cap).
 *  - The CEILING an approver may approve is the smaller of the day's whole
 *    computed minutes and the Formulas OT maximum — the same
 *    `min(computed, Minutes::round(max_ot * 60))` PayslipCalculator applies,
 *    so what is approvable is what is payable. Rejecting is always allowed.
 *  - A decision's STATUS: no row is PENDING; REJECTED stays REJECTED; an
 *    approval whose day has since computed a different number of minutes
 *    (a punch was corrected) is OUTDATED; otherwise APPROVED when it covers
 *    the whole approvable figure and PARTIAL when it covers less.
 *
 * Pure: no clock, database or UI (HrisFormat is a pure formatter).
 */
final class OvertimeApprovalPolicy
{
    public const string PENDING = 'PENDING';

    public const string APPROVED = 'APPROVED';

    public const string PARTIAL = 'PARTIAL';

    public const string REJECTED = 'REJECTED';

    public const string OUTDATED = 'OUTDATED';

    /** The two decisions a row can hold. */
    public const array DECISIONS = [self::APPROVED, self::REJECTED];

    private function __construct() {}

    /** A day's computed overtime as whole minutes, never negative. */
    public static function wholeMinutes(float $computed): int
    {
        return max(0, (int) Minutes::round(is_finite($computed) ? $computed : 0.0));
    }

    /** Only whole minutes can be approved; leaving a day undecided excludes its overtime from payroll. */
    public static function decidable(float $computed): bool
    {
        return self::wholeMinutes($computed) >= 1;
    }

    /** The most an approver may approve: the day's minutes, capped by the Formulas OT maximum. */
    public static function ceiling(float $computed, float $maxOtHours): int
    {
        $cap = (int) Minutes::round(max(0.0, is_finite($maxOtHours) ? $maxOtHours : 0.0) * 60);

        return max(0, min(self::wholeMinutes($computed), $cap));
    }

    /**
     * How a day reads now: null when there is nothing to decide and nothing
     * was decided.
     */
    public static function status(?string $decision, ?int $approved, ?int $computedAtDecision, float $computedNow, int $ceilingNow): ?string
    {
        if ($decision === null) {
            return self::decidable($computedNow) ? self::PENDING : null;
        }

        if ($decision === self::REJECTED) {
            return self::REJECTED;
        }

        if ($computedAtDecision !== self::wholeMinutes($computedNow)) {
            return self::OUTDATED;
        }

        return ($approved ?? 0) >= min($computedAtDecision, $ceilingNow) ? self::APPROVED : self::PARTIAL;
    }

    /**
     * What payroll pays for the day, to the whole minute: an approval capped
     * by what the day computes NOW and by the Formulas maximum (the ceiling)
     * — the engine's own min(computed, approved, cap). A rejection or no
     * decision pays nothing. So an approval left OUTDATED by a shorter day,
     * or by a lowered maximum, reads as what is actually paid.
     */
    public static function payable(?string $decision, ?int $approved, int $ceilingNow): int
    {
        return $decision === self::APPROVED ? max(0, min($approved ?? 0, $ceilingNow)) : 0;
    }

    /**
     * A typed minutes figure as a whole number, or null when it is not one
     * ('90', 90 and ' 90 ' are; '90.5', '1h' and '' are not).
     */
    public static function minutesOf(mixed $minutes): ?int
    {
        if (is_int($minutes)) {
            return $minutes;
        }

        if (is_string($minutes) && preg_match('/^\s*\d{1,4}\s*$/', $minutes) === 1) {
            return (int) trim($minutes);
        }

        return null;
    }

    /** The sentence that refuses a decision, or null when it is acceptable. */
    public static function error(string $decision, mixed $minutes, int $ceiling): ?string
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            return 'Choose whether to approve or reject the overtime.';
        }

        if ($decision === self::REJECTED) {
            return null;
        }

        if ($ceiling < 1) {
            return 'Nothing is approvable under the overtime formula; reject it instead.';
        }

        $whole = self::minutesOf($minutes);
        if ($whole === null || $whole < 1 || $whole > $ceiling) {
            return 'Approve between 1m and '.self::duration($ceiling).' of overtime.';
        }

        return null;
    }

    /**
     * "2h 30m", "45m", "8h" — the DTR's own spelling, HrisFormat::duration,
     * so an audit row or notice reads exactly as the screen. Never negative.
     */
    public static function duration(int $minutes): string
    {
        return HrisFormat::duration((float) max(0, $minutes));
    }
}
