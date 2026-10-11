<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The parsing half of `src/components/DateField.tsx`, in PHP.
 *
 * `<x-kit.date-field>` runs a byte-for-byte JS mirror of these two functions in
 * the browser so the field can format while it is typed — `toDisplay` and
 * `toKey` in resources/js/date-field.js (the `dateField` Alpine factory).
 * This class is the server-side source of truth for the same rules — use it
 * wherever a module needs to normalise or validate a typed date string, and
 * keep the two in sync (the JS file cites this one).
 *
 * Value in and out stays the `YYYY-MM-DD` key the tables store; what people
 * see and type is `dd MMM yyyy`.
 */
final class DateText
{
    /** @var list<string> */
    private const array MONTHS = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];

    /** `2026-08-20` → `20 Aug 2026`. Empty in, empty out. (DateField.tsx:30-36) */
    public static function toDisplay(string $key): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($key), $m) !== 1) {
            return '';
        }

        $month = self::MONTHS[(int) $m[2] - 1] ?? null;

        return $month === null ? '' : "{$m[3]} {$month} {$m[1]}";
    }

    /**
     * Accepts what people actually type: `20 Aug 2026`, `20/08/2026`,
     * `2026-08-20`, `20 August 2026`. Returns the storage key, or "" when it
     * is not a real date — the 31st of a 30-day month included.
     * (DateField.tsx:42-70)
     */
    public static function toKey(string $text): string
    {
        $raw = trim($text);

        if ($raw === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $raw, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/', $raw, $m) === 1) {
            $month = 0;

            foreach (self::MONTHS as $index => $abbreviation) {
                if (str_starts_with(strtolower($m[2]), strtolower($abbreviation))) {
                    $month = $index + 1;

                    break;
                }
            }

            if ($month === 0) {
                return '';
            }

            [$day, $year] = [(int) $m[1], (int) $m[3]];
        } else {
            return '';
        }

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return '';
        }

        // Rejects the 31st of a 30-day month, which the source catches by
        // round-tripping through `new Date` (DateField.tsx:63-68).
        if (! checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
