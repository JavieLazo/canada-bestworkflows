<?php

declare(strict_types=1);

namespace App\Livewire\Kit;

/**
 * The parsing half of `<x-kit.time-field>`, in PHP.
 *
 * The stored value stays the `HH:mm` (24-hour) key the tables use, exactly as
 * `src/components/TimeField.tsx` keeps it. The input surface differs: a TYPED
 * clock box plus a separate AM/PM select replaces the source's hour/minute
 * dropdowns — no popover pickers. So `parts()` is the straight port of
 * TimeField.tsx:27-37 reshaped for a typed box, and `toKey()` parses what a
 * typist actually produces: `8:30`, `08:30`, `830`, `1230`, `8`.
 *
 * `<x-kit.time-field>` runs a byte-for-byte JS mirror of these functions in
 * the browser; this class is the server-side source of truth for the same
 * rules — keep the two in sync (the blade component's script cites this file).
 */
final class TimeText
{
    /**
     * `"17:30"` → its typed-clock parts, or null when empty/unparseable.
     * Port of TimeField.tsx:27-37: `%12 === 0 ? 12 : %12`, AM below noon.
     *
     * @return array{clock: string, period: 'AM'|'PM'}|null
     */
    public static function parts(string $value): ?array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $m) !== 1) {
            return null;
        }

        $h24 = (int) $m[1];

        if ($h24 > 23) {
            return null;
        }

        $h12 = $h24 % 12 === 0 ? 12 : $h24 % 12;

        return [
            'clock' => $h12.':'.$m[2],
            'period' => $h24 < 12 ? 'AM' : 'PM',
        ];
    }

    /**
     * Typed clock + AM/PM → the 24-hour `HH:mm` key, or "" when the text does
     * not read as a 12-hour clock time (yet). Accepted spellings:
     *
     *   `8:30` `08:05`   colon form, two-digit minutes
     *   `830` `1230`     bare digits, last two are the minutes
     *   `8`              bare hour, minutes 00
     *
     * Hour must land in 1–12 and minutes in 00–59; anything else is "pending"
     * and leaves the caller's stored value untouched, the same
     * leave-it-alone-until-it-parses rule DateField follows.
     */
    public static function toKey(string $clock, string $period): string
    {
        $raw = trim($clock);

        if ($raw === '') {
            return '';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m) === 1) {
            [$hour, $minute] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{1,2})(\d{2})$/', $raw, $m) === 1) {
            [$hour, $minute] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{1,2})$/', $raw, $m) === 1) {
            [$hour, $minute] = [(int) $m[1], 0];
        } else {
            return '';
        }

        if ($hour < 1 || $hour > 12 || $minute > 59) {
            return '';
        }

        // Port of TimeField.tsx:39-42: 12 AM → 00, 12 PM → 12.
        $h24 = $hour % 12;

        if (strtoupper($period) === 'PM') {
            $h24 += 12;
        }

        return sprintf('%02d:%02d', $h24, $minute);
    }
}
