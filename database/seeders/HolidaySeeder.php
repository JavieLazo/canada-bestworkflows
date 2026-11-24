<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Holiday;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * The Philippine holiday calendar from seed.ts:1539-1561, dated in the current
 * local year exactly as the reference used `new Date().getFullYear()`.
 *
 * `percent` is the WHOLE day's rate, not a premium added to an ordinary day:
 * 200 pays double for working a regular holiday, 130 pays the special
 * non-working rate. Working the day is what earns it — a holiday nobody worked
 * pays nothing.
 */
final class HolidaySeeder extends PortSeeder
{
    private const HOLIDAYS = [
        ["New Year's Day", '01-01', 'REGULAR', 200],
        ['Araw ng Kagitingan', '04-09', 'REGULAR', 200],
        ['Labor Day', '05-01', 'REGULAR', 200],
        ['Independence Day', '06-12', 'REGULAR', 200],
        ['National Heroes Day', '08-25', 'REGULAR', 200],
        ['Bonifacio Day', '11-30', 'REGULAR', 200],
        ['Christmas Day', '12-25', 'REGULAR', 200],
        ['Rizal Day', '12-30', 'REGULAR', 200],
        ['Ninoy Aquino Day', '08-21', 'SPECIAL_NON_WORKING', 130],
        ["All Saints' Day", '11-01', 'SPECIAL_NON_WORKING', 130],
        ['Feast of the Immaculate Conception', '12-08', 'SPECIAL_NON_WORKING', 130],
        ['Last Day of the Year', '12-31', 'SPECIAL_NON_WORKING', 130],
    ];

    public function run(): void
    {
        $year = CarbonImmutable::now(LocalClock::zone())->year;

        foreach (self::HOLIDAYS as $i => [$name, $monthDay, $type, $percent]) {
            $this->upsert(Holiday::class, $this->codeMatch(Holiday::class, 'code', $i + 1, 'HOL'), [
                'id' => 'hol-'.($i + 1),
                'name' => $name,
                'date' => $year.'-'.$monthDay,
                'schedule' => 'ANNUAL',
                'type' => $type,
                'percent' => $percent,
                'created_at' => $this->at(300, 9, 0),
            ]);
        }
    }
}
