<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Benefits\LeaveCashOutValue;

/**
 * What one converted day of leave credits pays (LeaveCashOutValue): under
 * FACTOR, the monthly rate times the days times the factor (0.0481927 to
 * start); under DAILY, or for an employee with no monthly rate, the days
 * times the daily rate. Rounded once. Pure.
 */
it('prices days by the factor or the daily rate', function (LeaveCashOutValue $value, float $days, float $monthly, float $daily, float $expected) {
    expect($value->amount($days, $monthly, $daily))->toBe($expected);
})->with([
    // 30,000 × 2.5 × 0.0481927 = 3,614.4525
    'the standard factor' => [LeaveCashOutValue::standard(), 2.5, 30000.0, 1363.64, 3614.45],
    // 25,000 × 10 × 0.0481927 = 12,048.175
    'ten days' => [LeaveCashOutValue::standard(), 10.0, 25000.0, 1000.0, 12048.18],
    'another factor' => [new LeaveCashOutValue(LeaveCashOutValue::FACTOR, 0.05), 3.0, 20000.0, 900.0, 3000.0],
    'no monthly rate pays the daily rate' => [LeaveCashOutValue::standard(), 2.0, 0.0, 650.0, 1300.0],
    'the daily rate' => [LeaveCashOutValue::daily(), 2.5, 30000.0, 1363.636363, 3409.09],
]);

it('validates what HR types', function (string $method, string $factor, bool $ok) {
    expect(LeaveCashOutValue::error($method, $factor) === null)->toBe($ok);
})->with([
    'the standard factor' => ['FACTOR', '0.0481927', true],
    'a leading dot' => ['FACTOR', '.05', true],
    'one' => ['FACTOR', '1', true],
    'zero' => ['FACTOR', '0', false],
    'above one' => ['FACTOR', '1.5', false],
    'eight decimals' => ['FACTOR', '0.04819270', false],
    'not a number' => ['FACTOR', 'abc', false],
    'daily ignores the factor' => ['DAILY', '', true],
    'an unknown method' => ['HOURLY', '0.05', false],
]);

it('reads stored columns, anything out of shape as the standard value', function () {
    expect(LeaveCashOutValue::fromColumns('DAILY', '0.0481927'))->toEqual(LeaveCashOutValue::daily())
        ->and(LeaveCashOutValue::fromColumns('FACTOR', '0.0500000'))->toEqual(new LeaveCashOutValue('FACTOR', 0.05))
        ->and(LeaveCashOutValue::fromColumns('FACTOR', null))->toEqual(LeaveCashOutValue::standard())
        ->and(LeaveCashOutValue::fromInput('FACTOR', ' 0.0481927 '))->toEqual(LeaveCashOutValue::standard())
        ->and(LeaveCashOutValue::standard()->text())->toBe('Monthly rate × 0.0481927')
        ->and(LeaveCashOutValue::daily()->text())->toBe('Daily rate');
});
