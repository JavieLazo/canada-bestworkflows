<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Roster\WorkWeek;

/**
 * The week runs Sunday to Saturday, and `WorkWeek` is the one place that
 * says so: the order weekdays are listed in, where each sits in the week,
 * and the Sunday a date's week starts on — across a month's and a year's
 * end as inside one. The stored weekday numbers (0 Sunday … 6 Saturday) are
 * what it orders, never what it changes.
 *
 * Pure PHP over 'Y-m-d' strings; no container, so this runs under tests/Unit.
 */
it('starts the week on Sunday and lists the stored weekday numbers in that order', function () {
    expect(WorkWeek::FIRST_DAY)->toBe(0)
        ->and(WorkWeek::weekdays())->toBe([0, 1, 2, 3, 4, 5, 6]);
});

it('places each weekday in the week: Sunday first, Saturday last', function (int $weekday, int $position) {
    expect(WorkWeek::position($weekday))->toBe($position);
})->with([
    'Sunday' => [0, 0],
    'Monday' => [1, 1],
    'Wednesday' => [3, 3],
    'Saturday' => [6, 6],
]);

it('reads any date as the Sunday its week starts on', function (string $date, string $sunday) {
    expect(WorkWeek::startOf($date))->toBe($sunday);
})->with([
    'a Sunday is its own week start' => ['2026-11-15', '2026-11-15'],
    'the Monday after' => ['2026-11-16', '2026-11-15'],
    'midweek' => ['2026-11-18', '2026-11-15'],
    'the Saturday that ends the week' => ['2026-11-21', '2026-11-15'],
    'the next Sunday opens a new week' => ['2026-11-22', '2026-11-22'],
    'a week that straddles a month' => ['2026-12-01', '2026-11-29'],
    'a week that straddles a year' => ['2027-01-01', '2026-12-27'],
    'the last day of a leap February' => ['2028-02-29', '2028-02-27'],
]);

it('lists the seven dates of a week that straddles a month, Sunday to Saturday', function () {
    expect(WorkWeek::dates('2026-12-02'))->toBe([
        '2026-11-29', '2026-11-30', '2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05',
    ]);
});

it('gives every date of one week the same seven dates', function () {
    $week = WorkWeek::dates('2026-12-27');

    foreach ($week as $date) {
        expect(WorkWeek::dates($date))->toBe($week);
    }
    expect($week[0])->toBe('2026-12-27')->and($week[6])->toBe('2027-01-02');
});
