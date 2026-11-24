<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The currency every pay, rate and cost figure is in, and the one way such a
 * figure is written for a reader.
 *
 * Amounts are stored as plain numbers with no currency of their own, so the
 * symbol exists only where a figure is shown or exported. Keeping it here
 * means a screen, a payslip and a CSV cannot disagree about it. Project
 * finance is the exception: its rows carry their own currency code.
 */
final class Currency
{
    /** ISO 4217 code, for a column heading or a note that names the currency. */
    public const CODE = 'CAD';

    public const SYMBOL = '$';

    /**
     * `$1,234.50`: the symbol, thousands separators, always two decimals. A
     * value that is not a finite number reads as zero, as the figure it would
     * have been stored as does.
     */
    public static function format(mixed $amount): string
    {
        $value = is_numeric($amount) ? (float) $amount : 0.0;

        if (! is_finite($value)) {
            $value = 0.0;
        }

        return self::SYMBOL.number_format($value, 2, '.', ',');
    }

    /** A typed or exported figure with its currency marks and separators taken off: `$1,234.50` reads `1234.50`. */
    public static function strip(string $text): string
    {
        return str_replace([',', self::CODE.' ', self::SYMBOL], '', $text);
    }
}
