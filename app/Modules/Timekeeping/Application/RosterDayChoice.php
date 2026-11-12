<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftShapePolicy;
use App\Models\ShiftType;
use App\Modules\Timekeeping\Domain\Roster\CustomHours;
use App\Support\HrisFormat;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use RuntimeException;

/**
 * One weekday's shift choice in the weekly schedule — DEFAULT, REST, KEEP,
 * a pattern id, or custom hours as the dialog's draft array — read, locked
 * and turned into the row values a weekday stores. One body for
 * `SaveRosterShifts` and the rest-day move (`RestDayConversion`), so the two
 * can never store the same choice differently.
 *
 * Every method assumes it runs inside `DatedChange`, after the command has
 * gated the actor.
 */
final class RosterDayChoice
{
    /**
     * One day's choice, trimmed — or custom hours, validated where the day is
     * named.
     *
     * @return string|array<array-key, mixed>
     */
    public static function read(mixed $value, string $day): string|array
    {
        if (is_array($value)) {
            $error = CustomHours::error($value);
            if ($error !== null) {
                throw new RuntimeException("{$day}: {$error}");
            }

            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Pick a shift for {$day}.");
        }

        return trim($value);
    }

    /**
     * The patterns the choices name, read FOR UPDATE in id order — the lock
     * every pattern edit takes first, so a copy cannot miss the edit.
     *
     * @param  list<string|array<array-key, mixed>>  $choices
     * @return EloquentCollection<string, ShiftType>
     */
    public static function lockPatterns(array $choices): EloquentCollection
    {
        $patternIds = array_values(array_unique(array_filter($choices, static fn (string|array $value): bool => is_string($value) && ! in_array($value, ['DEFAULT', 'REST', 'KEEP'], true))));
        sort($patternIds);
        $patterns = ShiftType::query()->whereKey($patternIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($patterns->count() !== count($patternIds)) {
            throw new RuntimeException('That shift pattern no longer exists.');
        }

        return $patterns;
    }

    /**
     * The row values one day's choice stores — REST, a pattern's copy or
     * custom hours — working (or resting) on `$weekday` alone. DEFAULT and
     * KEEP store nothing new and are the caller's.
     *
     * @param  string|array<array-key, mixed>  $value
     * @param  EloquentCollection<string, ShiftType>|null  $patterns  from `lockPatterns()`
     * @return array{is_rest_day: bool, shift_type_id: string|null, shift_name: string, shift_data: array<string, mixed>}
     */
    public static function entry(string|array $value, int $weekday, DomainShift $standing, ?EloquentCollection $patterns): array
    {
        if ($value === 'REST') {
            return ['is_rest_day' => true, 'shift_type_id' => null, 'shift_name' => 'Rest day', 'shift_data' => array_replace(DomainShift::restDay($standing)->toArray(), ['shift_type_id' => null])];
        }
        if (is_array($value)) {
            $error = CustomHours::error($value);
            if ($error !== null) {
                throw new RuntimeException(HrisFormat::weekday($weekday, short: false).': '.$error);
            }

            return ['is_rest_day' => false, 'shift_type_id' => null, 'shift_name' => CustomHours::NAME,
                'shift_data' => DomainShift::fromArray(array_replace(CustomHours::attributes($value), ['work_days' => [$weekday]]))->toArray()];
        }
        $pattern = $patterns?->get($value);
        if (! $pattern instanceof ShiftType) {
            throw new RuntimeException('That shift pattern no longer exists.');
        }
        $copy = ShiftPolicy::attributesFromPattern($pattern);
        $copy['work_days'] = [$weekday];
        $error = ShiftShapePolicy::shapeError($copy);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        return ['is_rest_day' => false, 'shift_type_id' => $pattern->id, 'shift_name' => $pattern->name, 'shift_data' => DomainShift::fromArray($copy)->toArray()];
    }
}
