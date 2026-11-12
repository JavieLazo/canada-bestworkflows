<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Credits\LeaveCashOutLimits;
use App\Modules\Timekeeping\Domain\Credits\LeaveCashOutPolicy;

/**
 * Cashing leave credits out (LeaveCashOutPolicy): a cashable type, whole or
 * half days above zero, no more than the balance left after the days
 * already spoken for, and within the limits HR set on the type
 * (LeaveCashOutLimits) — the credits needed before converting, the fewest
 * days a request, the days that must remain, the requests a year and the
 * most days a year. A LARGE request (at or above the share of the balance
 * the type asks a reason for) needs a note, and is the one request the
 * yearly maximum does not bound. Pure.
 */

/** The limits most cases use: 15 to qualify, 10 a request, 5 kept, 30 a year, a reason for half. */
function lcopLimits(): LeaveCashOutLimits
{
    return new LeaveCashOutLimits(15.0, 10.0, 5.0, 30.0, null, 50);
}

it('refuses what the balance refuses and accepts the rest when no limit is set', function (mixed $days, bool $allowed, ?float $available, ?string $expected) {
    $error = LeaveCashOutPolicy::error($days, $allowed, $available, new LeaveCashOutLimits, 0.0, 0);
    if ($expected === null) {
        expect($error)->toBeNull();
    } else {
        expect($error)->toContain($expected);
    }
})->with([
    'a type that cannot be cashed out' => ['1', false, 10.0, 'cannot be converted to cash'],
    'no days' => ['', true, 10.0, 'Enter the days'],
    'zero days' => ['0', true, 10.0, 'Enter the days'],
    'a third of a day' => ['0.3', true, 10.0, 'whole or half days'],
    'a quarter day' => ['1.25', true, 10.0, 'whole or half days'],
    'no credits ever entered' => ['1', true, null, 'no credits of this type'],
    'nothing left' => ['0.5', true, 0.0, 'no credits of this type'],
    'more than is left' => ['8', true, 7.5, 'Only 7.5 days'],
    'exactly what is left' => ['7.5', true, 7.5, null],
    'a half day' => ['0.5', true, 7.5, null],
]);

it('holds a request to the type\'s limits', function (string $days, float $available, float $asked, int $requests, string $reason, ?string $expected) {
    $error = LeaveCashOutPolicy::error($days, true, $available, lcopLimits(), $asked, $requests, $reason);
    if ($expected === null) {
        expect($error)->toBeNull();
    } else {
        expect($error)->toBe($expected);
    }
})->with([
    'below the credits needed' => ['10', 14.5, 0.0, 0, '', 'Converting needs at least 15 days of credits; 14.5 days are available.'],
    'at the credits needed' => ['10', 15.0, 0.0, 0, 'Medical bills', null],
    'fewer days than a request takes' => ['9.5', 40.0, 0.0, 0, '', 'Convert at least 10 days at a time.'],
    'leaving less than must remain' => ['16', 20.0, 0.0, 0, 'Saving for school', 'At least 5 days must remain after converting, so up to 15 days can be converted.'],
    'leaving exactly what must remain' => ['15', 20.0, 0.0, 0, 'Saving for school', null],
    'past the yearly maximum' => ['10', 40.0, 25.0, 1, '', 'Cash conversions are limited to 30 days a year; 5 left this year.'],
    'up to the yearly maximum' => ['10', 40.0, 20.0, 1, '', null],
    'half the balance with no reason' => ['20', 40.0, 0.0, 0, '', 'Converting 20 days or more needs a reason. Say why in the note.'],
    'half the balance with a reason' => ['20', 40.0, 0.0, 0, 'Hospital bills', null],
    'a large request past the yearly maximum' => ['100', 200.0, 25.0, 1, 'Hospital bills', null],
    'just under half needs no reason' => ['19.5', 40.0, 0.0, 0, '', null],
    'a blank reason is no reason' => ['20', 40.0, 0.0, 0, '   ', 'Converting 20 days or more needs a reason. Say why in the note.'],
]);

it('counts the requests a year, large ones included', function () {
    $once = new LeaveCashOutLimits(requestsPerYear: 1, reasonShare: 50);
    $twice = new LeaveCashOutLimits(requestsPerYear: 2);

    expect(LeaveCashOutPolicy::error('2', true, 10.0, $once, 0.0, 0))->toBeNull()
        ->and(LeaveCashOutPolicy::error('2', true, 10.0, $once, 2.0, 1))->toBe('Cash conversions of this leave are limited to one a year.')
        ->and(LeaveCashOutPolicy::error('8', true, 10.0, $once, 0.0, 1, 'A reason'))->toBe('Cash conversions of this leave are limited to one a year.')
        ->and(LeaveCashOutPolicy::error('1', true, 10.0, $twice, 2.0, 2))->toBe('Cash conversions of this leave are limited to 2 a year.');
});

it('says when a request is large', function () {
    $limits = new LeaveCashOutLimits(reasonShare: 50);

    expect(LeaveCashOutPolicy::isLarge(5.0, 10.0, $limits))->toBeTrue()
        ->and(LeaveCashOutPolicy::isLarge(4.5, 10.0, $limits))->toBeFalse()
        ->and(LeaveCashOutPolicy::isLarge(10.0, 10.0, new LeaveCashOutLimits))->toBeFalse();
});

it('validates, stores and states the limits, blank for none', function () {
    expect(LeaveCashOutLimits::error(['cash_out_min_days' => '', 'cash_out_per_year' => null]))->toBeNull()
        ->and(LeaveCashOutLimits::error(['cash_out_min_balance' => '0']))->toBe('Credits needed before converting: enter a number of days above zero, or leave it blank for no limit.')
        ->and(LeaveCashOutLimits::error(['cash_out_keep_days' => 'abc']))->toStartWith('Days that must remain: ')
        ->and(LeaveCashOutLimits::error(['cash_out_per_year' => '0']))->toStartWith('Requests a year: ')
        ->and(LeaveCashOutLimits::error(['cash_out_per_year' => '1.5']))->toStartWith('Requests a year: ')
        ->and(LeaveCashOutLimits::error(['cash_out_reason_share' => '101']))->toStartWith('Reason needed from: ');

    $limits = LeaveCashOutLimits::fromInput(['cash_out_min_balance' => '15', 'cash_out_min_days' => '10', 'cash_out_keep_days' => '5', 'cash_out_max_year' => '30', 'cash_out_per_year' => '1', 'cash_out_reason_share' => '50']);
    expect($limits->toArray())->toBe(['cash_out_min_balance' => 15.0, 'cash_out_min_days' => 10.0, 'cash_out_keep_days' => 5.0, 'cash_out_max_year' => 30.0, 'cash_out_per_year' => 1, 'cash_out_reason_share' => 50])
        ->and($limits->toForm())->toBe(['cash_out_min_balance' => '15', 'cash_out_min_days' => '10', 'cash_out_keep_days' => '5', 'cash_out_max_year' => '30', 'cash_out_per_year' => '1', 'cash_out_reason_share' => '50'])
        ->and($limits->lines())->toBe([
            'Hold at least 15 days of credits before converting',
            'Convert at least 10 days at a time',
            'Keep at least 5 days after converting',
            'Up to 30 days a year',
            'Once a year',
            'A reason for half of the balance or more, which may go past the yearly limit',
        ])
        ->and($limits->reasonFrom(40.0))->toBe(20.0)
        ->and(LeaveCashOutLimits::fromColumns('15.0000', null, '0', 'junk', 1000, 50)->toArray())
        ->toBe(['cash_out_min_balance' => 15.0, 'cash_out_min_days' => null, 'cash_out_keep_days' => null, 'cash_out_max_year' => null, 'cash_out_per_year' => null, 'cash_out_reason_share' => 50])
        ->and((new LeaveCashOutLimits)->isNone())->toBeTrue()
        ->and((new LeaveCashOutLimits)->lines())->toBe([]);
});
