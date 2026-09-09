<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

/**
 * The shift-shape half of `src/lib/shift-policy.ts`, as the shift-types API
 * runs it on POST and PATCH (api/records/shift-types/route.ts:19-22).
 *
 * A sibling copy of `App\Livewire\Roster\ShiftPolicy::shapeError()` — this
 * module may only write its own files, so the two must be kept in sync until
 * a shared home exists.
 */
final class ShiftTypePolicy
{
    private function __construct() {}

    /** shift-policy.ts:12-16. */
    public static function isClockTime(string $value): bool
    {
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return (int) $m[1] <= 23 && (int) $m[2] <= 59 && (int) $m[3] <= 59;
    }

    /** shift-policy.ts:18-21. */
    private static function clockMinutes(string $value): float
    {
        $parts = array_map(floatval(...), explode(':', $value));

        return $parts[0] * 60 + ($parts[1] ?? 0.0) + ($parts[2] ?? 0.0) / 60;
    }

    /**
     * Validates combinations that an individual field cannot judge — the port
     * of `shiftShapeError` (shift-policy.ts:24-58), verbatim messages.
     *
     * @param  array<string, mixed>  $shift
     */
    public static function shapeError(array $shift): ?string
    {
        foreach (['start_time', 'end_time', 'break_start', 'break_end'] as $label) {
            $value = $shift[$label] ?? null;

            if ($value !== null && $value !== '' && ! self::isClockTime((string) $value)) {
                return "{$label}: must be a real HH:MM:SS clock time";
            }
        }

        $mode = $shift['mode'] ?? null;
        $start = $shift['start_time'] ?? null;
        $end = $shift['end_time'] ?? null;

        if ($mode === 'FIXED' && (! $start || ! $end)) {
            return 'Fixed shifts require both start and end times.';
        }

        if ($mode === 'FLEXIBLE' && ! ((float) ($shift['required_hours'] ?? 0) > 0)) {
            return 'Flexible shifts require more than zero working hours.';
        }

        if ($mode === 'FLEXIBLE') {
            $workingWindow = (float) ($shift['required_hours'] ?? 0) * 60;
        } elseif ($start && $end) {
            // `(end - start + 1440) % 1440 || 1440` — a zero-length span reads
            // as a full 24-hour day, exactly as the source computes it.
            $span = fmod(self::clockMinutes((string) $end) - self::clockMinutes((string) $start) + 24 * 60, 24 * 60);
            $workingWindow = $span > 0.0 ? $span : 24.0 * 60;
        } else {
            $workingWindow = 0.0;
        }

        if ((float) ($shift['break_minutes'] ?? 0) >= $workingWindow) {
            return 'Break minutes must be shorter than the working shift.';
        }

        if ((bool) ($shift['break_start'] ?? null) !== (bool) ($shift['break_end'] ?? null)) {
            return 'A break needs both a start and an end time.';
        }

        $breakStart = $shift['break_start'] ?? null;
        $breakEnd = $shift['break_end'] ?? null;

        if ($breakStart && $breakEnd) {
            $windowLength = fmod(
                self::clockMinutes((string) $breakEnd) - self::clockMinutes((string) $breakStart) + 24 * 60,
                24 * 60,
            );

            if ($windowLength <= 0.0) {
                return 'The break must end after it starts.';
            }

            // The window IS the unpaid break, so its length is `break_minutes`.
            // Let the two disagree and the calculator charges the window while
            // `paidShiftMinutes` deducts the number: the shift earns phantom
            // overtime, or loses hours, on every day it is worked.
            if (abs($windowLength - (float) ($shift['break_minutes'] ?? 0)) > 0.0001) {
                return 'Break minutes must match the length of the break window.';
            }

            // And the break has to fall inside the shift, or nobody can take
            // it. A 22:00-06:00 night pattern left on the form's default
            // 12:00-13:00 break never overlaps the hours actually worked, so
            // the allowance is never deducted and the day reads as overtime.
            if ($mode !== 'FLEXIBLE' && $start && $end) {
                $offset = fmod(
                    self::clockMinutes((string) $breakStart) - self::clockMinutes((string) $start) + 24 * 60,
                    24 * 60,
                );

                if ($offset + $windowLength > $workingWindow) {
                    return 'The break window must fall inside the shift.';
                }
            }
        }

        if ((bool) ($shift['requires_break_punch'] ?? false)
            && (! ($shift['break_start'] ?? null) || ! ($shift['break_end'] ?? null))) {
            return 'Break punching requires a configured break window.';
        }

        return null;
    }
}
