<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Projects;

use Illuminate\Validation\ValidationException;

final class ResultValue
{
    public const LIMIT = 9_999_999_999_999;

    public static function parse(string $value, string $field = 'value'): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^-?\d{1,9}(?:\.\d{1,4})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Enter a number with up to nine whole digits and four decimal places.']);
        }
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $scaled = (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');

        return $negative ? -$scaled : $scaled;
    }

    public static function input(int $scaled): string
    {
        $absolute = abs($scaled);
        $value = rtrim(rtrim(intdiv($absolute, 10000).'.'.str_pad((string) ($absolute % 10000), 4, '0', STR_PAD_LEFT), '0'), '.');

        return ($scaled < 0 ? '-' : '').$value;
    }

    public static function format(int $scaled): string
    {
        [$whole, $fraction] = array_pad(explode('.', self::input($scaled), 2), 2, '');

        return (preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole).($fraction === '' ? '' : '.'.$fraction);
    }

    public static function met(?int $actual, ?int $target, string $direction): ?bool
    {
        if ($actual === null || $target === null) {
            return null;
        }

        return match ($direction) {
            'AT_MOST' => $actual <= $target,
            'EXACT' => $actual === $target,
            default => $actual >= $target,
        };
    }
}
