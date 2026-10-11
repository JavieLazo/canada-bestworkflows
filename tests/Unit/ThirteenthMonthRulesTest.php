<?php

declare(strict_types=1);

use App\Domain\Payroll\Statutory;
use App\Modules\Payroll\Domain\Tax\SupplementaryWithholding;
use App\Modules\Payroll\Domain\ThirteenthMonth\ThirteenthMonthPay;
use App\Support\Money;

/**
 * The 13th month's two pure rules: the amount is one twelfth of the year's
 * basic pay — EMS's own plus what was typed as earned outside it — and the
 * part over the PHP 90,000 exemption is taxed at the employee's marginal
 * rate over their latest regular taxable pay, through the engine's own
 * withholding function.
 */

/** @param array<string, mixed> $overrides */
function thirteenEmployee(array $overrides = []): object
{
    return (object) array_merge([
        'allow_withholding_tax' => true, 'annualize_tax' => false, 'fixed_tax' => 0, 'percent_tax' => 0,
    ], $overrides);
}

it('pays one twelfth of the basic pay earned in the year', function () {
    expect(ThirteenthMonthPay::basis(180_000, 60_000))->toBe(240_000.0)
        ->and(ThirteenthMonthPay::amount(180_000, 60_000))->toBe(20_000.0)
        // Rounded to the centavo, once.
        ->and(ThirteenthMonthPay::amount(100_000, 0))->toBe(8_333.33)
        ->and(ThirteenthMonthPay::amount('1000.10', null))->toBe(83.34)
        // Junk and negatives count as nothing.
        ->and(ThirteenthMonthPay::amount(-5_000, 12_000))->toBe(1_000.0)
        ->and(ThirteenthMonthPay::amount('abc', NAN))->toBe(0.0);
});

it('taxes the excess at the marginal rate over the regular taxable pay', function () {
    $employee = thirteenEmployee();

    $expected = Money::round(Statutory::semiMonthlyWithholdingTax(20_000.0) - Statutory::semiMonthlyWithholdingTax(15_000.0));
    expect(SupplementaryWithholding::tax($employee, 15_000, 5_000))->toBe($expected)
        ->and($expected)->toBeGreaterThan(0.0)
        // No excess, no tax; no regular pay, the excess is taxed on its own.
        ->and(SupplementaryWithholding::tax($employee, 15_000, 0))->toBe(0.0)
        ->and(SupplementaryWithholding::tax($employee, 0, 30_000))->toBe(Money::round(Statutory::semiMonthlyWithholdingTax(30_000.0)));
});

it('follows the employee\'s own withholding switches', function () {
    // A fixed tax is owed once per payslip already: nothing more.
    expect(SupplementaryWithholding::tax(thirteenEmployee(['fixed_tax' => 500]), 15_000, 5_000))->toBe(0.0)
        // A percentage applies to the excess alone.
        ->and(SupplementaryWithholding::tax(thirteenEmployee(['percent_tax' => 10]), 15_000, 5_000))->toBe(500.0)
        // Withholding switched off: none.
        ->and(SupplementaryWithholding::tax(thirteenEmployee(['allow_withholding_tax' => false]), 15_000, 5_000))->toBe(0.0)
        // The annualised table when the employee is on it.
        ->and(SupplementaryWithholding::tax(thirteenEmployee(['annualize_tax' => true]), 15_000, 5_000))
        ->toBe(max(0.0, Money::round(Statutory::annualizedSemiMonthlyWithholdingTax(20_000.0) - Statutory::annualizedSemiMonthlyWithholdingTax(15_000.0))));
});
