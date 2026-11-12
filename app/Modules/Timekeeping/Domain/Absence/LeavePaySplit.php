<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Absence;

use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;

/**
 * WHICH DAYS OF A LEAVE ARE PAID when what covers it — the employee's
 * credits, or the leave type's set days — runs short: the counted dates are
 * paid IN DATE ORDER for as long as what is available covers a whole unit
 * of each (one per date, half per date of a half-day request), and every
 * date after that is WITHOUT PAY. A date is never paid in part: a fraction
 * left over (0.375 of a day) stays where it was. Nothing refuses a request;
 * a request nothing covers is without pay whole.
 *
 * Paying the earliest dates first keeps the dates without pay together at
 * the end of the request, so the Daily Time Record reads the request as a
 * paid part followed by a part without pay.
 *
 * Pure: the caller hands in the counted dates (the employee's scheduled
 * working days, or every calendar day, as the leave type counts them) and
 * what is available.
 */
final class LeavePaySplit
{
    private function __construct() {}

    /**
     * @param  array<int, string>  $dates  the counted dates, in any order and with any keys (they are sorted and reindexed)
     * @param  float  $unit  what each date costs: 1, or 0.5 for a half-day request
     * @param  float|null  $available  what covers the request; null when nothing limits it (paid whole)
     * @return array{paid: list<string>, unpaid: list<string>, paid_days: float, unpaid_days: float}
     */
    public static function split(array $dates, float $unit, ?float $available): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates, SORT_STRING);
        $paid = [];
        $unpaid = [];
        $left = $available === null ? null : LeaveQuantity::normalize(max(0.0, $available));
        foreach ($dates as $date) {
            // A tiny tolerance so 0.5 + 0.5 against an available 1.0 is
            // never refused on float noise; credits hold four decimals.
            if ($unpaid === [] && ($left === null || $left + 0.00001 >= $unit)) {
                $paid[] = $date;
                $left = $left === null ? null : LeaveQuantity::normalize($left - $unit);

                continue;
            }
            $unpaid[] = $date;
        }

        return [
            'paid' => $paid,
            'unpaid' => $unpaid,
            'paid_days' => LeaveQuantity::normalize(count($paid) * $unit),
            'unpaid_days' => LeaveQuantity::normalize(count($unpaid) * $unit),
        ];
    }
}
