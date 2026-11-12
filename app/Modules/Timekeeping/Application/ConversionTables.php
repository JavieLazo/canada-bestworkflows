<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;

/**
 * THE minutes-to-days conversion table, FIXED: the Civil Service
 * Commission's standard table — an 8-hour day, three decimals, a half
 * rounding DOWN (30 minutes = .0625, shown as .062) — every minute from 1
 * to 60 and every hour to 8, worked out by MinuteConversion::rows. It is
 * always in use and nobody edits it, so lateness and undertime are charged
 * to whatever leave types are marked for them, with nothing to set up
 * first.
 *
 * The `leave_conversion_tables` rows an earlier build saved are history
 * only: NOTHING READS THEM. Payroll captures the entry with each run, so a
 * finalized payslip keeps the rows it was worked out with.
 */
final class ConversionTables
{
    /** The id payroll evidence records for this table, in place of a stored row's. */
    public const string VERSION_ID = 'CSC_STANDARD';

    public const float HOURS_PER_DAY = 8.0;

    public const int DECIMALS = 3;

    public const string ROUNDING = 'HALF_DOWN';

    private function __construct() {}

    /**
     * The table as a detached entry, the shape the charge rule and the
     * payroll evidence carry: id, hours, decimals, rounding and rows.
     *
     * @return array{version_id: string, hours_per_day: float, decimals: int, rounding: string, rows: array{minutes: array<int, string>, hours: array<int, string>}}
     */
    public static function entry(): array
    {
        return [
            'version_id' => self::VERSION_ID,
            'hours_per_day' => self::HOURS_PER_DAY,
            'decimals' => self::DECIMALS,
            'rounding' => self::ROUNDING,
            'rows' => MinuteConversion::rows(self::HOURS_PER_DAY, self::DECIMALS, self::ROUNDING),
        ];
    }
}
