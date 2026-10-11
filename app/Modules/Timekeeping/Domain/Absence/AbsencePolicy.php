<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Absence;

use App\Domain\Attendance\DtrCalculator;
use Carbon\CarbonImmutable;

/** Calendar rules shared by filing and review, independent of either screen. */
final class AbsencePolicy
{
    public const string OFFICIAL_BUSINESS = DtrCalculator::OFFICIAL_BUSINESS;

    public const array DAY_TYPES = ['WHOLE_DAY' => 'Whole day', 'HALF_DAY' => 'Half day'];

    public static function dateError(string $start, string $end): ?string
    {
        $parse = static function (string $value): ?CarbonImmutable {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
                return null;
            }
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

            return checkdate($month, $day, $year)
                ? CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC')
                : null;
        };
        $from = $parse($start);
        $to = $parse($end);

        if ($from === null || $to === null) {
            return 'Choose valid start and end dates.';
        }

        return $to->lessThan($from) ? 'The end date cannot fall before the start date.' : null;
    }

    public static function overlaps(string $firstStart, string $firstEnd, string $secondStart, string $secondEnd): bool
    {
        return $firstStart <= $secondEnd && $secondStart <= $firstEnd;
    }
}
