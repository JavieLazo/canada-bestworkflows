<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

use InvalidArgumentException;

/**
 * Minutes and hours into fractions of a day, exactly. The formula is
 * `minutes / (hours per day × 60)` settled to a number of decimals under a
 * rounding rule — HALF_UP, HALF_DOWN (the government table's rule: 30
 * minutes of an 8-hour day is .0625, shown as .062), UP or DOWN — done in
 * whole numbers so no float ever decides a tie. A loaded table (a version of
 * `leave_conversion_tables`) may differ from the formula on purpose; the
 * difference report lists each row that does.
 *
 * A charge of several hours and minutes is converted the way the table is
 * read: whole days, then the hours row, then the minutes row, each from the
 * table, summed exactly. Pure: no database, clock or screen.
 */
final class MinuteConversion
{
    public const array ROUNDINGS = ['HALF_UP', 'HALF_DOWN', 'UP', 'DOWN'];

    /** @var array<string, string> */
    public const array ROUNDING_LABELS = [
        'HALF_UP' => 'Half rounds up',
        'HALF_DOWN' => 'Half rounds down',
        'UP' => 'Always up',
        'DOWN' => 'Always down',
    ];

    public const int MAX_DECIMALS = 6;

    private function __construct() {}

    /**
     * The minutes in a day of `$hoursPerDay` hours, as a whole number; a
     * day whose minutes are not whole (7.333 hours) is refused.
     */
    public static function dayMinutes(float $hoursPerDay): int
    {
        if (! is_finite($hoursPerDay) || $hoursPerDay <= 0 || $hoursPerDay > 24) {
            throw new InvalidArgumentException('Hours per day must be above 0 and at most 24.');
        }
        $scaled = (int) number_format($hoursPerDay * 6000, 0, '', '');
        if ($scaled % 100 !== 0) {
            throw new InvalidArgumentException('Hours per day must be a whole number of minutes.');
        }

        return intdiv($scaled, 100);
    }

    /**
     * `$minutes / $dayMinutes` to `$decimals` places under `$rounding`, as
     * a decimal string ("0.0625" → "0.062" under HALF_DOWN at 3).
     */
    public static function fraction(int $minutes, int $dayMinutes, int $decimals, string $rounding): string
    {
        if ($minutes < 0 || $dayMinutes <= 0 || $decimals < 0 || $decimals > self::MAX_DECIMALS || ! in_array($rounding, self::ROUNDINGS, true)) {
            throw new InvalidArgumentException('Invalid conversion inputs.');
        }
        $scale = 10 ** $decimals;
        $numerator = $minutes * $scale;
        $quotient = intdiv($numerator, $dayMinutes);
        $remainder = $numerator % $dayMinutes;
        $twice = $remainder * 2;
        $roundUp = match ($rounding) {
            'HALF_UP' => $twice >= $dayMinutes && $remainder > 0,
            'HALF_DOWN' => $twice > $dayMinutes,
            'UP' => $remainder > 0,
            default => false,
        };
        if ($roundUp) {
            $quotient++;
        }

        return self::text($quotient, $decimals);
    }

    /**
     * The whole table the formula gives: every minute from 1 to 60 and
     * every whole hour up to the day's, each as a fraction of a day.
     *
     * @return array{minutes: array<int, string>, hours: array<int, string>}
     */
    public static function rows(float $hoursPerDay, int $decimals, string $rounding): array
    {
        $dayMinutes = self::dayMinutes($hoursPerDay);
        $minutes = [];
        for ($m = 1; $m <= 60; $m++) {
            $minutes[$m] = self::fraction($m, $dayMinutes, $decimals, $rounding);
        }
        $hours = [];
        for ($h = 1; $h <= self::hoursInDay($hoursPerDay); $h++) {
            $hours[$h] = self::fraction($h * 60, $dayMinutes, $decimals, $rounding);
        }

        return ['minutes' => $minutes, 'hours' => $hours];
    }

    /** The whole hours a table lists: the day's hours, rounded up for a day of 7.5 hours. */
    public static function hoursInDay(float $hoursPerDay): int
    {
        return intdiv(self::dayMinutes($hoursPerDay) + 59, 60);
    }

    /**
     * A duration in days, read from the table the way the government reads
     * it: whole days of `$hoursPerDay`, then the hours row, then the
     * minutes row (60 minutes read the 1-hour row), summed exactly at the
     * table's decimals. Zero minutes are zero days.
     *
     * @param  array{minutes: array<int|string, string|float>, hours: array<int|string, string|float>}  $rows
     */
    public static function days(int $totalMinutes, array $rows, float $hoursPerDay, int $decimals): float
    {
        if ($totalMinutes <= 0) {
            return 0.0;
        }
        $dayMinutes = self::dayMinutes($hoursPerDay);
        $scale = 10 ** $decimals;
        $wholeDays = intdiv($totalMinutes, $dayMinutes);
        $rest = $totalMinutes % $dayMinutes;
        $hours = intdiv($rest, 60);
        $minutes = $rest % 60;
        $scaled = $wholeDays * $scale;
        if ($hours > 0) {
            $scaled += self::scaled(self::row($rows['hours'], $hours), $decimals);
        }
        if ($minutes > 0) {
            $scaled += self::scaled(self::row($rows['minutes'], $minutes), $decimals);
        }

        return LeaveQuantity::normalize($scaled / $scale);
    }

    /**
     * Every row where a loaded table differs from the formula at the
     * table's own decimals and rounding.
     *
     * @param  array{minutes: array<int|string, string|float>, hours: array<int|string, string|float>}  $rows
     * @return list<array{unit: string, value: int, table: string, formula: string}>
     */
    public static function differences(array $rows, float $hoursPerDay, int $decimals, string $rounding): array
    {
        $formula = self::rows($hoursPerDay, $decimals, $rounding);
        $differences = [];
        foreach (['minutes', 'hours'] as $unit) {
            foreach ($formula[$unit] as $value => $expected) {
                $table = $rows[$unit][$value] ?? $rows[$unit][(string) $value] ?? null;
                $tableText = $table === null ? '' : self::text(self::scaled($table, $decimals), $decimals);
                if ($tableText !== $expected) {
                    $differences[] = ['unit' => $unit, 'value' => $value, 'table' => $tableText, 'formula' => $expected];
                }
            }
        }

        return $differences;
    }

    /**
     * Why a set of rows is refused, or null: every minute 1..60 and every
     * hour of the day present, each a fraction from 0 to 1 at the given
     * decimals.
     *
     * @param  array<string, mixed>  $rows
     */
    public static function rowsError(array $rows, float $hoursPerDay, int $decimals): ?string
    {
        foreach (['minutes' => 60, 'hours' => self::hoursInDay($hoursPerDay)] as $unit => $count) {
            $list = $rows[$unit] ?? null;
            if (! is_array($list)) {
                return "The {$unit} rows are missing.";
            }
            for ($i = 1; $i <= $count; $i++) {
                $value = $list[$i] ?? $list[(string) $i] ?? null;
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    return ucfirst($unit)." row {$i} is missing.";
                }
                $text = trim((string) $value);
                if (preg_match('/\A\d+(?:\.\d{1,'.$decimals.'})?\z/', $text) !== 1 && ! ($decimals === 0 && preg_match('/\A\d+\z/', $text) === 1)) {
                    return ucfirst($unit)." row {$i} must be a fraction of a day with up to {$decimals} decimals.";
                }
                if ((float) $text > 1.0) {
                    return ucfirst($unit)." row {$i} cannot exceed one day.";
                }
            }
        }

        return null;
    }

    /**
     * The rows normalized for storage: each value as a decimal string at
     * the table's decimals, keyed 1..n.
     *
     * @param  array<string, mixed>  $rows
     * @return array{minutes: array<int, string>, hours: array<int, string>}
     */
    public static function normalizeRows(array $rows, float $hoursPerDay, int $decimals): array
    {
        $out = ['minutes' => [], 'hours' => []];
        foreach (['minutes' => 60, 'hours' => self::hoursInDay($hoursPerDay)] as $unit => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $value = $rows[$unit][$i] ?? $rows[$unit][(string) $i] ?? 0;
                $out[$unit][$i] = self::text(self::scaled($value, $decimals), $decimals);
            }
        }

        return $out;
    }

    /** @param  array<int|string, string|float>  $list */
    private static function row(array $list, int $index): string|float
    {
        $value = $list[$index] ?? $list[(string) $index] ?? null;
        if ($value === null) {
            throw new InvalidArgumentException("The conversion table has no row {$index}.");
        }

        return $value;
    }

    /** A decimal text or float as a whole number of ten-to-the-decimals. */
    private static function scaled(string|float|int $value, int $decimals): int
    {
        return (int) number_format((float) $value * (10 ** $decimals), 0, '', '');
    }

    private static function text(int $scaled, int $decimals): string
    {
        if ($decimals === 0) {
            return (string) $scaled;
        }
        $digits = str_pad((string) $scaled, $decimals + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }
}
