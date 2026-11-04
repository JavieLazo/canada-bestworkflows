<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Credits\LeaveEarningRule;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Modules\Timekeeping\Domain\Credits\MonthFacts;

/**
 * The month-end earning rule: a flat amount, pro-rata to the days present,
 * or a lookup by the days without pay; a part month earns the whole amount,
 * pro-rata to the days employed, or nothing; a cap stops the balance; and
 * the year-end rule carries the closing balance whole, up to a limit, or
 * lets it lapse. Pure arithmetic over facts, at four decimals.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array{amount: float, method: string, table: list<array{up_to: float, credits: float}>, partial: string, cap: ?float}
 */
function lerRule(array $overrides = []): array
{
    return array_merge(['amount' => 1.25, 'method' => 'FLAT', 'table' => [], 'partial' => 'PRO_RATA', 'cap' => null], $overrides);
}

it('earns the flat amount for a whole month and nothing when the month has no scheduled day', function () {
    expect(LeaveEarningRule::month(lerRule(), new MonthFacts(22, 22, 20.0, 2.0, 10.0)))->toBe(['days' => 1.25, 'basis' => '1.25 flat'])
        ->and(LeaveEarningRule::month(lerRule(), new MonthFacts(0, 0, 0.0, 0.0, 10.0))['days'])->toBe(0.0);
});

it('pro-rates to the days present, never above the scheduled days', function () {
    expect(LeaveEarningRule::month(lerRule(['method' => 'PRO_RATA']), new MonthFacts(20, 20, 15.0, 5.0, 0.0))['days'])->toBe(0.9375)
        ->and(LeaveEarningRule::month(lerRule(['method' => 'PRO_RATA']), new MonthFacts(20, 20, 25.0, 0.0, 0.0))['days'])->toBe(1.25);
});

it('looks the days without pay up in the table and earns nothing past its last row', function () {
    $table = [['up_to' => 0.0, 'credits' => 1.25], ['up_to' => 1.0, 'credits' => 1.208], ['up_to' => 2.5, 'credits' => 1.146]];
    $rule = lerRule(['method' => 'TABLE', 'table' => $table]);
    expect(LeaveEarningRule::month($rule, new MonthFacts(22, 22, 22.0, 0.0, 0.0))['days'])->toBe(1.25)
        ->and(LeaveEarningRule::month($rule, new MonthFacts(22, 22, 21.0, 1.0, 0.0))['days'])->toBe(1.208)
        ->and(LeaveEarningRule::month($rule, new MonthFacts(22, 22, 20.0, 2.0, 0.0))['days'])->toBe(1.146)
        ->and(LeaveEarningRule::month($rule, new MonthFacts(22, 22, 19.0, 3.0, 0.0))['days'])->toBe(0.0)
        ->and(LeaveEarningRule::lookup($table, 0.5))->toBe(1.208);
});

it('treats a part month by the partial rule: whole, pro-rata to the days employed, or nothing', function () {
    $facts = new MonthFacts(22, 11, 11.0, 0.0, 0.0);
    expect(LeaveEarningRule::month(lerRule(['partial' => 'FULL']), $facts)['days'])->toBe(1.25)
        ->and(LeaveEarningRule::month(lerRule(['partial' => 'PRO_RATA']), $facts)['days'])->toBe(0.625)
        ->and(LeaveEarningRule::month(lerRule(['partial' => 'PRO_RATA']), $facts)['basis'])->toContain('11 of 22 scheduled days employed')
        ->and(LeaveEarningRule::month(lerRule(['partial' => 'NONE']), $facts))->toBe(['days' => 0.0, 'basis' => 'a part month earns nothing']);
});

it('caps the balance and never earns a negative amount', function () {
    expect(LeaveEarningRule::month(lerRule(['cap' => 300.0]), new MonthFacts(22, 22, 22.0, 0.0, 299.5))['days'])->toBe(0.5)
        ->and(LeaveEarningRule::month(lerRule(['cap' => 300.0]), new MonthFacts(22, 22, 22.0, 0.0, 300.0))['days'])->toBe(0.0)
        ->and(LeaveEarningRule::month(lerRule(['cap' => 300.0]), new MonthFacts(22, 22, 22.0, 0.0, 301.0))['days'])->toBe(0.0);
});

it('closes a year by the year-end rule', function () {
    // Credits never lapse: the whole closing balance carries, a debt below zero included.
    expect(LeaveEarningRule::yearEnd(17.25))->toBe(17.25)
        ->and(LeaveEarningRule::yearEnd(17.25004))->toBe(17.25)
        ->and(LeaveEarningRule::yearEnd(-2.0))->toBe(-2.0)
        ->and(LeaveEarningRule::yearEnd(0.0))->toBe(0.0);
});

it('describes a policy in one line and reads an empty one as earning nothing', function () {
    $policy = LeavePolicy::fromParams([
        'posting_day' => 5, 'unpaid_counts' => ['ABSENCE'],
        'earning' => ['VACATION_LEAVE' => ['amount' => '1.25', 'method' => 'PRO_RATA', 'table' => [], 'partial' => 'FULL', 'cap' => null, 'year_end' => 'CARRY', 'carry_limit' => null]],
        'charges' => ['enabled' => true, 'combine' => 'PAYROLL', 'late' => ['VACATION_LEAVE'], 'absence' => []],
    ]);
    expect($policy->describe(['VACATION_LEAVE' => 'Vacation Leave']))->toBe('Vacation Leave 1.25 a month (pro-rata) · posts on day 5 · charges to credits: lateness Vacation Leave')
        ->and($policy->postingDay())->toBe(5)
        ->and($policy->unpaidCounts())->toBe(['ABSENCE'])
        ->and(LeavePolicy::none()->describe([]))->toBe('earns nothing · posts at month end · attendance charged to pay');
});
