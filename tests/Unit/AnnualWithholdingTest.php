<?php

declare(strict_types=1);

use App\Domain\Payroll\Statutory;
use App\Modules\Payroll\Domain\Tax\AnnualWithholding;

/**
 * DELIBERATE DIVERGENCE 20, the whole-year withholding estimate: the
 * year's projected taxable pay is taxed by the yearly table, what the year
 * already withheld comes off, and the remainder is spread equally, all on
 * the next payroll or all on the year's last — which always takes the
 * exact remainder, refunding an excess. Pesos through Money::round, never
 * below zero.
 */
it('projects the year, spreads the remainder and settles the exact remainder on the last payroll', function () {
    // 300,000 posted + 25,000 now + 25,000 × 5 left = 450,000 → 22,500 + 20% of 50,000 = 32,500; 10,000 withheld; 22,500 left over 6 payrolls.
    $equal = AnnualWithholding::compute(300000.0, 25000.0, 5, 10000.0, AnnualWithholding::SPREAD_EQUAL);
    expect($equal['projected'])->toBe(450000.0)
        ->and($equal['annual_tax'])->toBe(Statutory::annualIncomeTax(450000.0))->toBe(32500.0)
        ->and($equal['remaining'])->toBe(22500.0)
        ->and($equal['withholding'])->toBe(3750.0)
        ->and($equal['refund'])->toBe(0.0);
    expect(AnnualWithholding::compute(300000.0, 25000.0, 5, 10000.0, AnnualWithholding::SPREAD_NEXT)['withholding'])->toBe(22500.0)
        ->and(AnnualWithholding::compute(300000.0, 25000.0, 5, 10000.0, AnnualWithholding::SPREAD_DECEMBER)['withholding'])->toBe(0.0);
    // The last payroll: the exact remainder whatever the spread, and the odd centavo lands here.
    $last = AnnualWithholding::compute(425000.0, 25000.0, 0, 32499.99, AnnualWithholding::SPREAD_DECEMBER);
    expect($last['withholding'])->toBe(0.01)->and($last['refund'])->toBe(0.0);
    // Too much withheld: nothing more is taken and the excess is the refund, only on the last payroll.
    $over = AnnualWithholding::compute(425000.0, 25000.0, 0, 40000.0, AnnualWithholding::SPREAD_EQUAL);
    expect($over['withholding'])->toBe(0.0)->and($over['refund'])->toBe(7500.0);
    $early = AnnualWithholding::compute(300000.0, 25000.0, 5, 40000.0, AnnualWithholding::SPREAD_EQUAL);
    expect($early['withholding'])->toBe(0.0)->and($early['refund'])->toBe(0.0);
    // Below the exempt band nothing is owed; junk below zero reads as zero.
    expect(AnnualWithholding::compute(0.0, 10000.0, 23, 0.0, AnnualWithholding::SPREAD_EQUAL)['withholding'])->toBe(0.0)
        ->and(AnnualWithholding::compute(-5.0, -1.0, -3, -2.0, 'junk')['withholding'])->toBe(0.0);
});
