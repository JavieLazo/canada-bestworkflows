<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * A quantity of leave credits: days (or hours, for an hour-unit type) kept
 * to FOUR decimals. Credits are not money, so they do not go through
 * Money::round — a month's earning of 1.167 or a minute's charge of
 * .0021 must survive as entered — and they may be negative: an allocation
 * charged below zero is a real state the type's limit allows.
 *
 * Every typed value, every stored value and every sum passes through
 * normalize(), which settles the fourth decimal the same way everywhere
 * (number_format's half-up), so two readers never disagree by a
 * ten-thousandth. format() shows a value without trailing zeros: 13.75,
 * 1.167, 43.3025, 0.
 *
 * Pure: no database, clock or screen.
 */
final class LeaveQuantity
{
    public const int DECIMALS = 4;

    /** DECIMAL(10,4)'s ceiling, the range the CHECKs pin on MySQL/MariaDB. */
    public const float MAX = 999999.9999;

    public const string FORMAT_ERROR = 'Enter a number of days with up to four decimals.';

    public const string RANGE_ERROR = 'Credits must be between 0 and 999999.9999.';

    public const string SIGNED_RANGE_ERROR = 'Credits must be between -999999.9999 and 999999.9999.';

    private function __construct() {}

    /**
     * Why a typed value is refused, or null when it is acceptable. Wire
     * values arrive as strings ('12.5', '', '0'); a blank means "no value"
     * and passes. Anything that is not a string, int or float holding a
     * number with at most four decimals inside the range is refused (a
     * bool, an array or a comma included), so the CHECK constraint never
     * has to be the thing that says no.
     */
    public static function error(mixed $value, bool $allowNegative = false): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return self::FORMAT_ERROR;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/\A-?(?:\d+(?:\.\d{1,4})?|\.\d{1,4})\z/', $text) !== 1) {
            return self::FORMAT_ERROR;
        }
        $days = (float) $text;
        if ($days > self::MAX || $days < -self::MAX || (! $allowNegative && $days < 0)) {
            return $allowNegative ? self::SIGNED_RANGE_ERROR : self::RANGE_ERROR;
        }

        return null;
    }

    /** The quantity a wire value stands for, once error() has passed: null for a blank, else the normalized float. */
    public static function parse(mixed $value): ?float
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';

        return $text === '' ? null : self::normalize((float) $text);
    }

    /** The value settled to four decimals. */
    public static function normalize(float $value): float
    {
        if (! is_finite($value)) {
            return 0.0;
        }

        return (float) number_format($value, self::DECIMALS, '.', '');
    }

    /** The sum of quantities, settled once at the end. */
    public static function sum(float ...$values): float
    {
        $total = 0.0;
        foreach ($values as $value) {
            $total += $value;
        }

        return self::normalize($total);
    }

    /** As people write it: whole numbers bare, otherwise without trailing zeros (1.25, 1.167, 43.3025). */
    public static function format(float $value): string
    {
        $text = number_format(self::normalize($value), self::DECIMALS, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
