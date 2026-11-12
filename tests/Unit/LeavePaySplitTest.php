<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Absence\LeavePaySplit;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;

/**
 * A SHORT BALANCE NEVER REFUSES A LEAVE: what covers it — the employee's
 * credits, or the leave type's set days — pays the counted dates IN DATE
 * ORDER, whole units only, and every date after that is without pay
 * (LeavePaySplit). And how a leave type is filed is HR's setting
 * (LeaveTypeCredits): earned credits, set days each year or each time, or
 * without pay — validated strictly, with nothing shipped; a type that holds
 * no credits never earns, is charged or is cashed out.
 *
 * Pure PHP; no container and no clock.
 */

/**
 * A valid settings input, filed against credits unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lpsInput(array $overrides = []): array
{
    return [
        'earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => true, 'charge_absence' => true,
        'charge_order' => '1', 'below_zero_limit' => '0', 'can_cash_out' => true, 'year_end' => 'CARRY',
        ...$overrides,
    ];
}

/* |---| Which days are paid |---| */

it('pays the earliest dates as far as what is available reaches and leaves the rest without pay', function (array $dates, float $unit, ?float $available, array $paid, array $unpaid) {
    $split = LeavePaySplit::split($dates, $unit, $available);

    expect($split['paid'])->toBe($paid)
        ->and($split['unpaid'])->toBe($unpaid)
        ->and($split['paid_days'])->toBe(count($paid) * $unit)
        ->and($split['unpaid_days'])->toBe(count($unpaid) * $unit);
})->with([
    'covered whole' => [['2026-11-10', '2026-11-11'], 1.0, 5.0, ['2026-11-10', '2026-11-11'], []],
    'nothing limits it' => [['2026-11-10', '2026-11-11'], 1.0, null, ['2026-11-10', '2026-11-11'], []],
    'two of five covered' => [['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-16'], 1.0, 2.0, ['2026-11-10', '2026-11-11'], ['2026-11-12', '2026-11-13', '2026-11-16']],
    'a fraction left over pays no part of a day' => [['2026-11-10', '2026-11-11', '2026-11-12'], 1.0, 2.375, ['2026-11-10', '2026-11-11'], ['2026-11-12']],
    'nothing available' => [['2026-11-10', '2026-11-11'], 1.0, 0.0, [], ['2026-11-10', '2026-11-11']],
    'a debt is nothing available' => [['2026-11-10'], 1.0, -3.0, [], ['2026-11-10']],
    'a half day is covered by half a credit' => [['2026-11-10', '2026-11-11'], 0.5, 0.5, ['2026-11-10'], ['2026-11-11']],
    'half a credit does not cover a whole day' => [['2026-11-10'], 1.0, 0.5, [], ['2026-11-10']],
    'dates in any order are paid earliest first' => [['2026-11-12', '2026-11-10', '2026-11-11'], 1.0, 1.0, ['2026-11-10'], ['2026-11-11', '2026-11-12']],
    'no counted date' => [[], 1.0, 0.0, [], []],
]);

it('keeps the days without pay together at the end of the request', function () {
    $split = LeavePaySplit::split(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], 1.0, 3.0);

    expect($split['paid'])->toBe(['2026-11-10', '2026-11-11', '2026-11-12'])
        ->and($split['unpaid'])->toBe(['2026-11-13'])
        ->and(end($split['paid']) < $split['unpaid'][0])->toBeTrue();
});

/* |---| How a leave type is filed |---| */

it('accepts each way of filing, and needs the set days of a type that has them', function (array $overrides, ?string $error) {
    $result = LeaveTypeCredits::error(lpsInput($overrides));

    $error === null ? expect($result)->toBeNull() : expect($result)->toContain($error);
})->with([
    'earned credits, the default' => [[], null],
    'earned credits named' => [['filing' => 'CREDITS'], null],
    'set days each year' => [['filing' => 'YEARLY', 'allowance_days' => '3'], null],
    'set days each time' => [['filing' => 'OCCASION', 'allowance_days' => '105', 'day_count' => 'CALENDAR'], null],
    'without pay' => [['filing' => 'UNPAID'], null],
    'an unknown way' => [['filing' => 'LATER'], 'Choose the payment basis'],
    'an unknown day count' => [['day_count' => 'HOURS'], 'working days or calendar days'],
    'yearly with no days' => [['filing' => 'YEARLY', 'allowance_days' => ''], 'annual allowance'],
    'each time with zero days' => [['filing' => 'OCCASION', 'allowance_days' => '0'], 'allowance per request'],
    'days that are not a number' => [['filing' => 'YEARLY', 'allowance_days' => 'three'], 'Allowance days'],
]);

it('holds no credits for a type not filed against them: it never earns, is charged or is cashed out', function (string $filing) {
    $settings = LeaveTypeCredits::fromInput(lpsInput(['filing' => $filing, 'allowance_days' => '3']));

    expect($settings->filedAgainstCredits())->toBeFalse()
        ->and($settings->earnsCredits)->toBeFalse()
        ->and($settings->chargeLate)->toBeFalse()
        ->and($settings->chargeAbsence)->toBeFalse()
        ->and($settings->canCashOut)->toBeFalse();
})->with(['YEARLY', 'OCCASION', 'UNPAID']);

it('keeps the set days only for a type that has them, and stores how it is filed', function () {
    $credits = LeaveTypeCredits::fromInput(lpsInput(['allowance_days' => '3']));
    $yearly = LeaveTypeCredits::fromInput(lpsInput(['filing' => 'YEARLY', 'allowance_days' => '3', 'day_count' => 'CALENDAR']));

    expect($credits->allowanceDays)->toBeNull()
        ->and($credits->filedAgainstCredits())->toBeTrue()
        ->and($credits->earnsCredits)->toBeTrue()
        ->and($yearly->toArray())->toMatchArray(['filing' => 'YEARLY', 'allowance_days' => 3.0, 'day_count' => 'CALENDAR'])
        ->and($yearly->hasAllowance())->toBeTrue()
        ->and($yearly->filingText())->toBe('3 days each year')
        ->and($yearly->describe())->toBe('filed as 3 days each year · counts calendar days')
        ->and(LeaveTypeCredits::fromInput(lpsInput(['filing' => 'UNPAID']))->describe())->toBe('filed as without pay');
});
