<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftShapePolicy;

/**
 * Hours typed in on the Shift Roster instead of picked from Shifting
 * Settings — for a day whose shift is not one of the company's patterns.
 * They become the same copied snapshot a pattern does (fixed
 * hours, grace, an optional break window whose length is the unpaid
 * break), with no pattern behind them: `shift_type_id` NULL and the name
 * CUSTOM HOURS, which is how a saved day is told apart from a deleted
 * pattern's copy. The shape rules are `ShiftShapePolicy`'s, the same ones a
 * pattern passes.
 *
 * The input is the roster dialog's draft: `start`, `end`, `break_start`,
 * `break_end` as 'HH:MM' (a blank break is no break) and `grace` in minutes.
 *
 * Pure: arrays in, arrays out.
 */
final class CustomHours
{
    public const NAME = 'Custom hours';

    public const DEFAULT_GRACE = 15;

    /** The draft a new set of custom hours starts from. */
    public const BLANK = ['start' => '08:00', 'end' => '17:00', 'break_start' => '', 'break_end' => '', 'grace' => '15'];

    private function __construct() {}

    /** Why the draft cannot be saved, or null when it can. */
    public static function error(mixed $input): ?string
    {
        if (! is_array($input)) {
            return 'Enter the custom hours again.';
        }
        $start = self::clock($input['start'] ?? null);
        $end = self::clock($input['end'] ?? null);
        if ($start === null || $end === null) {
            return 'Custom hours need a start and an end time.';
        }
        $breakStart = self::clock($input['break_start'] ?? null);
        $breakEnd = self::clock($input['break_end'] ?? null);
        if (self::blank($input['break_start'] ?? null) !== self::blank($input['break_end'] ?? null)
            || (! self::blank($input['break_start'] ?? null) && ($breakStart === null || $breakEnd === null))) {
            return 'A break needs both a start and an end time.';
        }
        $grace = $input['grace'] ?? '';
        if (! self::blank($grace) && (! is_numeric($grace) || (float) $grace !== (float) (int) $grace || (int) $grace < 0 || (int) $grace > 600)) {
            return 'Grace must be a whole number of minutes from 0 to 600.';
        }

        return ShiftShapePolicy::shapeError(self::shape($input));
    }

    /**
     * The shift attributes the hours are stored as, `work_days` left for the
     * caller to set to the day they apply on. Call only after `error()`
     * answered null.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    public static function attributes(array $input): array
    {
        return DomainShift::fromArray(self::shape($input))->toArray();
    }

    /**
     * Whether a saved day is custom hours rather than a pattern's copy (or
     * the copy of a pattern since deleted, which keeps its own name).
     *
     * @param  array<string, mixed>|null  $shiftData
     */
    public static function isCustom(?string $shiftTypeId, ?string $shiftName, ?array $shiftData): bool
    {
        return $shiftTypeId === null && $shiftName === self::NAME && $shiftData !== null
            && ($shiftData['mode'] ?? DomainShift::MODE_FIXED) !== DomainShift::MODE_FLEXIBLE;
    }

    /**
     * The draft that reproduces saved custom hours in the dialog.
     *
     * @param  array<string, mixed>  $shiftData
     * @return array{start: string, end: string, break_start: string, break_end: string, grace: string}
     */
    public static function input(array $shiftData): array
    {
        $hm = static fn (mixed $value): string => is_string($value) && strlen($value) >= 5 ? substr($value, 0, 5) : '';

        return [
            'start' => $hm($shiftData['start_time'] ?? null),
            'end' => $hm($shiftData['end_time'] ?? null),
            'break_start' => $hm($shiftData['break_start'] ?? null),
            'break_end' => $hm($shiftData['break_end'] ?? null),
            'grace' => (string) (int) ($shiftData['grace_minutes'] ?? self::DEFAULT_GRACE),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    private static function shape(array $input): array
    {
        $breakStart = self::clock($input['break_start'] ?? null);
        $breakEnd = self::clock($input['break_end'] ?? null);
        $grace = $input['grace'] ?? '';

        return [
            'shift_type_id' => null,
            'mode' => DomainShift::MODE_FIXED,
            'required_hours' => null,
            'work_days' => null,
            'start_time' => self::clock($input['start'] ?? null),
            'end_time' => self::clock($input['end'] ?? null),
            'grace_minutes' => self::blank($grace) || ! is_numeric($grace) ? self::DEFAULT_GRACE : (int) $grace,
            'break_start' => $breakStart,
            'break_end' => $breakEnd,
            // The window IS the unpaid break, so its length is the break.
            'break_minutes' => $breakStart !== null && $breakEnd !== null ? self::minutesBetween($breakStart, $breakEnd) : 0,
            'requires_break_punch' => false,
        ];
    }

    /** 'HH:MM' or 'HH:MM:SS' as 'HH:MM:00', or null when it is not a clock time. */
    private static function clock(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', trim($value), $m) !== 1) {
            return null;
        }
        $clock = "{$m[1]}:{$m[2]}:00";

        return ShiftShapePolicy::isClockTime($clock) ? $clock : null;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** Whole minutes from one clock time to the next, across midnight. */
    private static function minutesBetween(string $from, string $to): int
    {
        $minutes = static fn (string $clock): int => (int) substr($clock, 0, 2) * 60 + (int) substr($clock, 3, 2);

        return (($minutes($to) - $minutes($from)) % 1440 + 1440) % 1440;
    }
}
