<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * The rules of cashing leave credits out, pure: an employee may turn part
 * of a leave type's balance into pay, in whole or half days, as far as the
 * credits they hold allow (earlier years' credits included) and within the
 * limits HR set on the type (LeaveCashOutLimits). Days already asked for —
 * pending, or approved and not yet paid — are spoken for: they are out of
 * the available balance every limit reads. A LARGE request — at or above
 * the share of that balance the type asks a reason for — must give one,
 * and is the one request the yearly maximum does not bound. Nothing here
 * reads a clock, a database or a screen.
 */
final class LeaveCashOutPolicy
{
    public const float STEP = 0.5;

    /**
     * Why a request is refused, or null when it may be made.
     *
     * @param  mixed  $days  the typed days
     * @param  bool  $typeAllowed  whether the leave type may be cashed out (Leave Settings)
     * @param  float|null  $available  the type's live balance less the days already spoken for; null when no credit was ever entered
     * @param  float  $askedThisYear  the days of the type requested this year that were not refused or withdrawn
     * @param  int  $requestsThisYear  the requests of the type made this year that were not refused or withdrawn
     * @param  string  $reason  the request's note, which is its reason
     */
    public static function error(mixed $days, bool $typeAllowed, ?float $available, LeaveCashOutLimits $limits, float $askedThisYear, int $requestsThisYear, string $reason = ''): ?string
    {
        if (! $typeAllowed) {
            return 'This leave type cannot be converted to cash.';
        }
        $quantityError = LeaveQuantity::error($days);
        if ($quantityError !== null || trim((string) $days) === '') {
            return 'Enter the days to convert to cash.'.($quantityError === null ? '' : ' '.$quantityError);
        }
        $wanted = LeaveQuantity::parse($days) ?? 0.0;
        if ($wanted <= 0.0) {
            return 'Enter the days to convert to cash.';
        }
        if (abs($wanted / self::STEP - round($wanted / self::STEP)) > 1e-9) {
            return 'Convert whole or half days.';
        }
        if ($available === null || $available <= 0.0) {
            return 'There are no credits of this type to convert to cash.';
        }
        if ($limits->minBalance !== null && $available + 1e-9 < $limits->minBalance) {
            return 'Converting needs at least '.self::days($limits->minBalance).' of credits; '.self::days($available).' '.($available === 1.0 ? 'is' : 'are').' available.';
        }
        if ($wanted > $available + 1e-9) {
            return 'Only '.self::days($available).' of this type can be converted to cash.';
        }
        if ($limits->minDays !== null && $wanted + 1e-9 < $limits->minDays) {
            return 'Convert at least '.self::days($limits->minDays).' at a time.';
        }
        if ($limits->keepDays !== null && $available - $wanted + 1e-9 < $limits->keepDays) {
            $most = floor(max(0.0, $available - $limits->keepDays) / self::STEP) * self::STEP;

            return 'At least '.self::days($limits->keepDays).' must remain after converting, so up to '.self::days($most).' can be converted.';
        }
        if ($limits->requestsPerYear !== null && $requestsThisYear >= $limits->requestsPerYear) {
            return $limits->requestsPerYear === 1
                ? 'Cash conversions of this leave are limited to one a year.'
                : 'Cash conversions of this leave are limited to '.$limits->requestsPerYear.' a year.';
        }
        $large = self::isLarge($wanted, $available, $limits);
        if ($large && trim($reason) === '') {
            return 'Converting '.self::days((float) $limits->reasonFrom($available)).' or more needs a reason. Say why in the note.';
        }
        if (! $large && $limits->maxPerYear !== null && $askedThisYear + $wanted > $limits->maxPerYear + 1e-9) {
            $left = LeaveQuantity::normalize(max(0.0, $limits->maxPerYear - $askedThisYear));

            return 'Cash conversions are limited to '.self::days($limits->maxPerYear).' a year; '.LeaveQuantity::format($left).' left this year.';
        }

        return null;
    }

    /** Whether a request for `$wanted` days out of `$available` reaches the share that asks a reason. */
    public static function isLarge(float $wanted, float $available, LeaveCashOutLimits $limits): bool
    {
        $from = $limits->reasonFrom($available);

        return $from !== null && $wanted + 1e-9 >= $from;
    }

    private static function days(float $value): string
    {
        return LeaveQuantity::format($value).' '.($value === 1.0 ? 'day' : 'days');
    }
}
