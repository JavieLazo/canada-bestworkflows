<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\LeaveRequest;
use Illuminate\Support\Facades\Schema;

/**
 * Which half of a half-day request is off (DtrCalculator divergence 21):
 * whether the column exists yet (the window before the cron migrates
 * 2026_11_02_0015), the column list a leave reader adds it to, and the
 * value a filing may carry.
 */
final class LeaveHalves
{
    /** The two halves, with their names on screen; a half day may also say neither. */
    public const array HALVES = [LeaveRequest::HALF_AM => 'Morning off', LeaveRequest::HALF_PM => 'Afternoon off'];

    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_travel_requests', 'half'));
    }

    /**
     * The leave columns a reader selects, with `half` once it exists.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function columns(array $columns): array
    {
        return self::ready() ? [...$columns, 'half'] : $columns;
    }

    /** The stored half, read leniently: AM, PM or null. */
    public static function of(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, self::HALVES) ? $value : null;
    }
}
