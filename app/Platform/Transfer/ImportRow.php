<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use App\Support\DateText;
use RuntimeException;

/**
 * One data row of an import, read cell by cell in the shapes the owners'
 * commands take: trimmed text, `YYYY-MM-DD` dates, `HH:MM` times, plain
 * numeric money strings and booleans.
 *
 * Every reader throws a RuntimeException naming the column, so a row error
 * reads "Birthday: 31/02/1990 is not a date." The readers exist because a
 * CSV that went through Excel is not the file that was exported: Excel turns
 * `2026-09-28` into the locale's `9/28/2026`, a long account number into
 * `1.23457E+11`, and `08:00` into `8:00 AM`.
 *
 * Slashed dates are ambiguous. {@see ImportRunner} settles day-first or
 * month-first ONCE per file from the cells themselves (any first part over
 * 12 means day-first, any second part over 12 means month-first) and passes
 * the answer in. A file that mixes both is refused; one that never tells
 * (4/9/2026 and nothing else) is read month-first, as Excel writes dates in
 * the Philippines, and the preview says so.
 */
final class ImportRow
{
    public const string MONTH_FIRST = 'mdy';

    public const string DAY_FIRST = 'dmy';

    public const string MIXED = 'mixed';

    /** @param array<string, string> $cells keyed by the layout's column spelling */
    public function __construct(
        public readonly int $line,
        private readonly array $cells,
        private readonly string $dateOrder = self::MONTH_FIRST,
    ) {}

    public function has(string $column): bool
    {
        return array_key_exists($column, $this->cells);
    }

    /** The trimmed cell; "" when blank or when the column is not part of the layout. */
    public function text(string $column): string
    {
        return $this->cells[$column] ?? '';
    }

    /** Text that must not have been through Excel's number formatting (IDs, account and phone numbers). */
    public function identifier(string $column): string
    {
        $value = $this->text($column);
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $value) === 1) {
            throw new RuntimeException("{$column}: Excel shortened this number to {$value}. Format the column as Text before saving the file.");
        }

        return $value;
    }

    /** `YYYY-MM-DD`, or null for a blank cell. */
    public function date(string $column): ?string
    {
        $value = $this->text($column);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $value, $m) === 1) {
            if (strlen($m[3]) !== 4) {
                throw new RuntimeException("{$column}: write the year of {$value} in full, like 2026-09-28.");
            }
            if ($this->dateOrder === self::MIXED) {
                throw new RuntimeException("{$column}: this file mixes day-first and month-first dates. Write dates like 2026-09-28.");
            }
            [$day, $month] = $this->dateOrder === self::DAY_FIRST ? [(int) $m[1], (int) $m[2]] : [(int) $m[2], (int) $m[1]];
            if (! checkdate($month, $day, (int) $m[3])) {
                throw new RuntimeException("{$column}: {$value} is not a date.");
            }

            return sprintf('%04d-%02d-%02d', (int) $m[3], $month, $day);
        }

        // ISO and "28 Sep 2026" / "28 September 2026", as the date fields take them.
        $key = DateText::toKey($value);
        if ($key === '' && preg_match('/^([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})$/', $value, $m) === 1) {
            $key = DateText::toKey("{$m[2]} {$m[1]} {$m[3]}");
        }
        if ($key === '') {
            throw new RuntimeException("{$column}: {$value} is not a date. Write dates like 2026-09-28.");
        }

        return $key;
    }

    /** `HH:MM` on the 24-hour clock, or null for a blank cell. Takes 8:00, 08:00:00 and 8:00 PM. */
    public function time(string $column): ?string
    {
        $value = $this->text($column);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?(?:\s*([AaPp])\.?\s*[Mm]\.?)?$/', $value, $m) !== 1) {
            throw new RuntimeException("{$column}: {$value} is not a time. Write times like 08:00 or 17:30.");
        }
        [$hour, $minute] = [(int) $m[1], (int) $m[2]];
        $period = strtoupper($m[3] ?? '');
        if ($period !== '') {
            if ($hour < 1 || $hour > 12) {
                throw new RuntimeException("{$column}: {$value} is not a time.");
            }
            $hour = ($hour % 12) + ($period === 'P' ? 12 : 0);
        }
        if ($hour > 23 || $minute > 59) {
            throw new RuntimeException("{$column}: {$value} is not a time.");
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * A plain numeric string, as the money inputs send it, or "" for a blank
     * cell. Takes Excel's thousands separators, a dollar sign or CAD prefix,
     * and accounting-style (1,000.00) negatives.
     */
    public function money(string $column): string
    {
        $value = $this->text($column);
        if ($value === '') {
            return '';
        }
        $number = self::number((string) preg_replace('/CAD|\$|[\s\x{00A0}]/iu', '', $value));
        if ($number === null) {
            throw new RuntimeException("{$column}: {$value} is not an amount.");
        }

        return $number;
    }

    /** A whole number, or null for a blank cell. */
    public function integer(string $column): ?int
    {
        $value = $this->text($column);
        if ($value === '') {
            return null;
        }
        $number = self::number($value);
        if ($number === null || preg_match('/^-?\d+(\.0+)?$/', $number) !== 1) {
            throw new RuntimeException("{$column}: {$value} is not a whole number.");
        }

        return (int) $number;
    }

    /** Yes/No, True/False, Y/N or 1/0; null for a blank cell. */
    public function flag(string $column): ?bool
    {
        $value = strtolower($this->text($column));

        return match ($value) {
            '' => null,
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => throw new RuntimeException("{$column}: write Yes or No, not {$this->text($column)}."),
        };
    }

    /**
     * The value whose label matches the cell, ignoring case — the way a pill
     * or select label is exported back to its stored code. "" for a blank cell.
     *
     * @param  array<string, string>  $labels  stored value => label
     */
    public function choice(string $column, array $labels): string
    {
        $value = $this->text($column);
        if ($value === '') {
            return '';
        }
        foreach ($labels as $stored => $label) {
            if (strcasecmp($label, $value) === 0 || strcasecmp((string) $stored, $value) === 0) {
                return (string) $stored;
            }
        }

        throw new RuntimeException("{$column}: {$value} is not one of ".implode(', ', array_values($labels)).'.');
    }

    /**
     * `-1234.5` from `-1,234.5` or `(1,234.5)`; null when it is not a number.
     * A comma must be a thousands separator: `1000,50` from a comma-decimal
     * locale is refused rather than read as 100050.
     */
    private static function number(string $value): ?string
    {
        $negative = preg_match('/^\((.+)\)$/', $value, $m) === 1;
        if ($negative) {
            $value = $m[1];
        }
        if (str_starts_with($value, '-')) {
            $negative = true;
            $value = substr($value, 1);
        }
        if (str_contains($value, ',')) {
            if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $value) !== 1) {
                return null;
            }
            $value = str_replace(',', '', $value);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return ($negative ? '-' : '').$value;
    }
}
