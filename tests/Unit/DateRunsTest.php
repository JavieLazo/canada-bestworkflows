<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Roster\DateRuns;

/**
 * A refusal names its dates the way a person writes them: consecutive dates
 * as one run, the month once while it holds, the year after the last run of
 * that year, and past four runs the rest counted — one readable line however
 * many dates there are.
 */
it('writes dates as runs, the month once and the year at the end of each year', function (string $dates, string $text) {
    expect(DateRuns::describe(explode(' ', $dates)))->toBe($text);
})->with([
    'one date' => ['2026-11-17', 'Nov 17, 2026'],
    'a run' => ['2026-11-17 2026-11-18 2026-11-19', 'Nov 17–19, 2026'],
    'unsorted with a repeat' => ['2026-11-19 2026-11-17 2026-11-18 2026-11-17', 'Nov 17–19, 2026'],
    'two runs in a month' => ['2026-11-03 2026-11-04 2026-11-10', 'Nov 3–4, 10, 2026'],
    'a run across months' => ['2026-11-30 2026-12-01 2026-12-02', 'Nov 30–Dec 2, 2026'],
    'the month said again after a change' => ['2026-11-28 2026-12-01 2026-12-03', 'Nov 28, Dec 1, 3, 2026'],
    'a run across years' => ['2026-12-31 2027-01-01 2027-01-02', 'Dec 31, 2026–Jan 2, 2027'],
    'two years' => ['2026-12-30 2027-01-04', 'Dec 30, 2026; Jan 4, 2027'],
    'the rest counted' => ['2026-09-01 2026-09-03 2026-09-05 2026-09-07 2026-09-09 2026-09-10 2026-09-12', 'Sep 1, 3, 5, 7, 2026 and 3 more dates'],
    'one more' => ['2026-09-01 2026-09-03 2026-09-05 2026-09-07 2026-09-09', 'Sep 1, 3, 5, 7, 2026 and 1 more date'],
]);

it('groups consecutive dates into runs in date order', function () {
    expect(DateRuns::runs(['2026-09-03', '2026-09-01', '2026-09-02', '2026-09-05']))
        ->toBe([['2026-09-01', '2026-09-03'], ['2026-09-05', '2026-09-05']])
        ->and(DateRuns::runs([]))->toBe([])
        ->and(DateRuns::describe([]))->toBe('');
});
