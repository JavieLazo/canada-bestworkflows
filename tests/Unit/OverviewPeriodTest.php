<?php

declare(strict_types=1);

use App\Modules\WorkManagement\Application\OverviewPeriod;

/**
 * @param  non-empty-list<array{from: string, to: string}>  $buckets
 * @return array{string, string, int}
 */
function periodEnds(array $buckets): array
{
    return [$buckets[0]['from'], $buckets[array_key_last($buckets)]['to'], count($buckets)];
}

it('splits the day presets into whole days and compares them with the stretch right before', function () {
    $month = OverviewPeriod::resolve('30d', '', '', '2026-11-13');
    expect([$month->key, $month->label, $month->previousLabel, $month->unit])->toBe(['30d', 'Last 30 days', 'Previous 30 days', 'day'])
        ->and(periodEnds($month->buckets))->toBe(['2026-10-15', '2026-11-13', 30])
        ->and(periodEnds($month->previous))->toBe(['2026-09-15', '2026-10-14', 30])
        ->and($month->bucketLabel($month->buckets[0]))->toBe('Thu, Oct 15')
        ->and($month->bucketLabel($month->previous[0]))->toBe('Tue, Sep 15')
        ->and($month->endLabel($month->buckets[29]))->toBe('Nov 13');

    $weeks = OverviewPeriod::resolve('12w', '', '', '2026-11-13');
    expect([$weeks->unit, $weeks->previousLabel])->toBe(['week', 'Previous 12 weeks'])
        ->and($weeks->buckets[0])->toBe(['from' => '2026-08-22', 'to' => '2026-08-28'])
        ->and($weeks->buckets[11])->toBe(['from' => '2026-11-07', 'to' => '2026-11-13'])
        ->and($weeks->previous[0])->toBe(['from' => '2026-05-30', 'to' => '2026-06-05'])
        ->and($weeks->bucketLabel($weeks->buckets[11]))->toBe('Nov 7 to 13')
        ->and($weeks->bucketLabel($weeks->previous[0]))->toBe('May 30 to Jun 5');
});

it('sets twelve calendar months against the same months a year earlier', function () {
    $year = OverviewPeriod::resolve('12m', '', '', '2026-11-13');
    expect([$year->unit, $year->previousLabel])->toBe(['month', 'Previous 12 months'])
        ->and(periodEnds($year->buckets))->toBe(['2025-12-01', '2026-11-13', 12])
        ->and($year->previous[11])->toBe(['from' => '2025-11-01', 'to' => '2025-11-13'])
        ->and($year->bucketLabel($year->buckets[0]))->toBe('Dec 2025')
        ->and($year->bucketLabel($year->buckets[11]))->toBe('Nov 1 to 13, 2026')
        ->and($year->bucketLabel($year->previous[11]))->toBe('Nov 1 to 13, 2025')
        ->and($year->endLabel($year->buckets[11]))->toBe('Nov 13, 2026');
});

it('picks days, weeks or months for a custom range and trims the edge buckets', function () {
    $short = OverviewPeriod::resolve('custom', '2026-11-01', '2026-11-10', '2026-11-13');
    expect([$short->key, $short->label, $short->previousLabel, $short->unit])->toBe(['custom', 'Nov 1 to 10', 'Previous 10 days', 'day'])
        ->and(periodEnds($short->previous))->toBe(['2026-10-22', '2026-10-31', 10]);

    $medium = OverviewPeriod::resolve('custom', '2026-08-06', '2026-11-13', '2026-11-13');
    expect([$medium->unit, count($medium->buckets)])->toBe(['week', 15])
        ->and($medium->buckets[0])->toBe(['from' => '2026-08-06', 'to' => '2026-08-07'])
        ->and($medium->previous[0])->toBe(['from' => '2026-04-28', 'to' => '2026-04-29'])
        ->and($medium->bucketLabel($medium->buckets[0]))->toBe('Aug 6 to 7');

    $long = OverviewPeriod::resolve('custom', '2026-04-28', '2026-11-13', '2026-11-13');
    expect([$long->unit, $long->previousLabel, count($long->buckets)])->toBe(['month', 'Previous 8 months', 8])
        ->and($long->buckets[0])->toBe(['from' => '2026-04-28', 'to' => '2026-04-30'])
        ->and($long->previous[0])->toBe(['from' => '2025-08-28', 'to' => '2025-08-30'])
        ->and($long->previous[7])->toBe(['from' => '2026-03-01', 'to' => '2026-03-13'])
        ->and($long->bucketLabel($long->buckets[1]))->toBe('May 2026')
        ->and($long->bucketLabel($long->buckets[0]))->toBe('Apr 28 to 30, 2026');
});

it('refuses a custom range it cannot draw honestly, and falls back to the default', function (string $from, string $to, ?string $error) {
    expect(OverviewPeriod::customError($from, $to, '2026-11-13'))->toBe($error);
    if ($error !== null) {
        expect(OverviewPeriod::resolve('custom', $from, $to, '2026-11-13')->key)->toBe(OverviewPeriod::DEFAULT);
    }
})->with([
    'missing' => ['', '2026-11-13', 'Pick both dates.'],
    'not a day' => ['2026-02-30', '2026-03-01', 'Pick both dates.'],
    'reversed' => ['2026-11-10', '2026-11-01', 'The first date has to come before the second.'],
    'future' => ['2026-11-01', '2026-11-14', 'Pick dates up to today.'],
    'too long' => ['2025-11-12', '2026-11-13', 'Pick a range of 366 days or fewer.'],
    'a full year' => ['2025-11-13', '2026-11-13', null],
]);

it('turns a Manila day into the UTC instants timestamps are stored in', function () {
    expect(OverviewPeriod::instants(['from' => '2026-11-13', 'to' => '2026-11-13']))->toBe(['2026-11-12 16:00:00', '2026-11-13 16:00:00'])
        ->and(OverviewPeriod::resolve('bogus', '', '', '2026-11-13')->key)->toBe('30d');
});
