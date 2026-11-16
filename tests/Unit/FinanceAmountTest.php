<?php

declare(strict_types=1);

use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('keeps cash amounts and cost assumptions exact at their supported precision', function () {
    expect(FinanceAmount::minor('1000.50', 'amount'))->toBe(100050)
        ->and(FinanceAmount::calculated(FinanceAmount::quantity('2.5'), 3, FinanceAmount::minor('12.34', 'unit')))->toBe(9255)
        ->and(FinanceAmount::calculated(FinanceAmount::quantity('0.005'), 1, 100))->toBe(1)
        ->and(FinanceAmount::format(-123456))->toBe('-1,234.56')
        ->and(FinanceAmount::format(PHP_INT_MIN))->toBe('-92,233,720,368,547,758.08')
        ->and(FinanceAmount::input(54321))->toBe('543.21')
        ->and(FinanceAmount::minor('9999999999.99', 'amount'))->toBe(FinanceAmount::LIMIT);
});

it('refuses malformed negative over-precise and oversized amounts instead of rounding them', function (string $value) {
    expect(fn () => FinanceAmount::minor($value, 'amount'))->toThrow(ValidationException::class);
})->with(['', '-1', '1.005', '1e3', 'NaN', '1,000.00', '10000000000.00']);

it('refuses zero cash entries and overflowing calculated costs', function () {
    expect(fn () => FinanceAmount::minor('0', 'amount', true))->toThrow(ValidationException::class);
    expect(fn () => FinanceAmount::quantity('0'))->toThrow(ValidationException::class);
    expect(fn () => FinanceAmount::calculated(100_000_000, 1000, FinanceAmount::LIMIT))->toThrow(ValidationException::class);
});
