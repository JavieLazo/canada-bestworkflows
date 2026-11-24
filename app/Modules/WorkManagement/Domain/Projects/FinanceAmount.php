<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Projects;

use Illuminate\Validation\ValidationException;

/** Cash and budget arithmetic uses integer minor units; payroll keeps its own rounding policy. */
final class FinanceAmount
{
    public const LIMIT = 999_999_999_999;

    public const CURRENCIES = ['CAD' => 'CAD', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'AUD' => 'AUD', 'SGD' => 'SGD', 'PHP' => 'PHP'];

    public static function minor(string $value, string $field, bool $positive = false): int
    {
        $amount = self::scaled($value, 2, $field, self::LIMIT);
        if ($positive && $amount === 0) {
            throw ValidationException::withMessages([$field => 'Enter an amount greater than zero.']);
        }

        return $amount;
    }

    public static function signed(string $value, string $field): int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $amount = self::minor($negative ? substr($value, 1) : $value, $field);

        return $negative ? -$amount : $amount;
    }

    public static function quantity(string $value): int
    {
        $quantity = self::scaled($value, 4, 'quantity', 100_000_000);
        if ($quantity === 0) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity greater than zero.']);
        }

        return $quantity;
    }

    public static function calculated(int $quantity, int $frequency, int $unit): int
    {
        $ceiling = self::LIMIT * 10_000;
        if ($frequency < 1 || $frequency > 1000 || $quantity < 1 || $unit < 0
            || $unit > intdiv($ceiling, $frequency) || $unit * $frequency > intdiv($ceiling, $quantity)) {
            throw ValidationException::withMessages(['amount' => 'The calculated cost is too large.']);
        }

        return intdiv($quantity * $frequency * $unit + 5000, 10_000);
    }

    public static function format(int $minor): string
    {
        $digits = ltrim((string) $minor, '-');
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -2);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        return ($minor < 0 ? '-' : '').$grouped.'.'.substr($digits, -2);
    }

    public static function input(int $minor): string
    {
        return str_replace(',', '', self::format($minor));
    }

    public static function quantityInput(int $scaled): string
    {
        return rtrim(rtrim(intdiv($scaled, 10000).'.'.str_pad((string) ($scaled % 10000), 4, '0', STR_PAD_LEFT), '0'), '.');
    }

    private static function scaled(string $value, int $places, string $field, int $maximum): int
    {
        $value = trim($value);
        if (! preg_match('/^\d{1,12}(?:\.\d{1,'.$places.'})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Enter a valid non-negative number with up to '.$places.' decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $scaled = (int) $whole * (10 ** $places) + (int) str_pad($fraction, $places, '0');
        if ($scaled > $maximum) {
            throw ValidationException::withMessages([$field => 'This value is too large.']);
        }

        return $scaled;
    }
}
