<?php

declare(strict_types=1);

use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('parses exact signed result values without treating missing or zero as the same input', function () {
    expect(ResultValue::parse(''))->toBeNull()->and(ResultValue::parse('0'))->toBe(0)
        ->and(ResultValue::parse('-12.3456'))->toBe(-123456)->and(ResultValue::format(123456789))->toBe('12,345.6789')
        ->and(ResultValue::input(-10000))->toBe('-1')->and(ResultValue::met(null, 0, 'AT_LEAST'))->toBeNull()
        ->and(ResultValue::met(0, 0, 'AT_LEAST'))->toBeTrue()->and(ResultValue::met(30000, 50000, 'AT_MOST'))->toBeTrue();
});

it('rejects formulas exponents and excessive result precision', function (string $value) {
    expect(fn () => ResultValue::parse($value))->toThrow(ValidationException::class);
})->with(['=1+2', '1e3', '1.00001', '1000000000', 'NaN']);
