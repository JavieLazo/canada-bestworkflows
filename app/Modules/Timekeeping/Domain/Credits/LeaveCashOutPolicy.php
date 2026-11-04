<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * The rules of cashing leave credits out, pure: an employee may turn part
 * of a leave type's balance into pay, in whole or half days, at any time,
 * as far as the credits they hold allow (earlier years' credits included,
 * since credits never lapse) and within the yearly maximum their policy
 * sets. Days already asked for — pending, or approved and not yet paid —
 * are spoken for and count against both limits. Nothing here reads a
 * clock, a database or a screen.
 */
final class LeaveCashOutPolicy
{
    public const float STEP = 0.5;

    /**
     * Why a request is refused, or null when it may be made.
     *
     * @param  mixed  $days  the typed days
     * @param  bool  $typeAllowed  whether the leave type may be cashed out (Leave Credits settings)
     * @param  float|null  $available  the type's live balance less the days already spoken for; null when no credit was ever entered
     * @param  float|null  $yearlyMax  the policy's yearly maximum; null for none
     * @param  float  $askedThisYear  the days requested this year that were not refused or withdrawn
     */
    public static function error(mixed $days, bool $typeAllowed, ?float $available, ?float $yearlyMax, float $askedThisYear): ?string
    {
        if (! $typeAllowed) {
            return 'This leave type cannot be cashed out.';
        }
        $quantityError = LeaveQuantity::error($days);
        if ($quantityError !== null || trim((string) $days) === '') {
            return 'Enter the days to cash out.'.($quantityError === null ? '' : ' '.$quantityError);
        }
        $wanted = LeaveQuantity::parse($days) ?? 0.0;
        if ($wanted <= 0.0) {
            return 'Enter the days to cash out.';
        }
        if (abs($wanted / self::STEP - round($wanted / self::STEP)) > 1e-9) {
            return 'Cash out whole or half days.';
        }
        if ($available === null || $available <= 0.0) {
            return 'There are no credits of this type to cash out.';
        }
        if ($wanted > $available + 1e-9) {
            return 'Only '.LeaveQuantity::format($available).' '.($available === 1.0 ? 'day' : 'days').' of this type can be cashed out.';
        }
        if ($yearlyMax !== null && $askedThisYear + $wanted > $yearlyMax + 1e-9) {
            $left = LeaveQuantity::normalize(max(0.0, $yearlyMax - $askedThisYear));

            return 'Cash-outs are limited to '.LeaveQuantity::format($yearlyMax).' days a year; '.LeaveQuantity::format($left).' left this year.';
        }

        return null;
    }
}
