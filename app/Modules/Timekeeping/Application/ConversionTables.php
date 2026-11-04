<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveConversionTable;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;
use Illuminate\Support\Facades\Schema;

/**
 * The conversion tables as the application reads them: whether they exist
 * yet (the window before the cron migrates 2026_10_31_0004) and which
 * approved version converts a date's minutes — the latest approved version
 * in force on or before the date. None means minutes cannot be charged to
 * leave on that date, which the charge rule reports rather than guesses.
 */
final class ConversionTables
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('leave_conversion_tables'));
    }

    /** The approved version in force on `$date`, or null. */
    public static function inForce(string $date): ?LeaveConversionTable
    {
        if (! self::ready()) {
            return null;
        }

        return LeaveConversionTable::query()
            ->where('status', LeaveConversionTable::APPROVED)
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();
    }

    /**
     * The version in force as a detached entry, the shape the charge rule
     * and the payroll evidence carry: id, date, hours, decimals, rounding
     * and the rows.
     *
     * @return array{version_id: string, effective_from: string, hours_per_day: float, decimals: int, rounding: string, rows: array{minutes: array<int, string>, hours: array<int, string>}}|null
     */
    public static function entry(string $date): ?array
    {
        $row = self::inForce($date);
        if ($row === null) {
            return null;
        }

        return [
            'version_id' => $row->id,
            'effective_from' => $row->effective_from->format('Y-m-d'),
            'hours_per_day' => $row->hours_per_day,
            'decimals' => $row->decimals,
            'rounding' => $row->rounding,
            'rows' => MinuteConversion::normalizeRows($row->rows, $row->hours_per_day, $row->decimals),
        ];
    }
}
