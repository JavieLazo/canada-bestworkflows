<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Modules\Payroll\Domain\Canada\PayDeductions;
use App\Modules\Payroll\Domain\Canada\SourceDeductions;
use App\Modules\Payroll\Domain\Canada\TaxDetails;
use App\Modules\Payroll\Domain\Canada\TaxInputs;

/**
 * One pay's source deductions: the pension contribution, the insurance
 * premium and income tax a payslip carries, with the employer's shares. The
 * year's totals so far stop each contribution at its yearly maximum, an
 * employee the plan or the insurance does not apply to pays none of it, the
 * extra tax an employee asked for joins the federal line, and a pay of
 * nothing deducts nothing.
 */
function cpdRates(): DeductionRates
{
    return DeductionRates::inForceOn('2026-06-15');
}

/** @return array{pensionable: float, pension: float, second_pension: float, insurance: float} */
function cpdToDate(float $pensionable = 0.0, float $pension = 0.0, float $second = 0.0, float $insurance = 0.0): array
{
    return ['pensionable' => $pensionable, 'pension' => $pension, 'second_pension' => $second, 'insurance' => $insurance];
}

it('deducts the pension contribution, the premium and both taxes from a regular pay', function (): void {
    $lines = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 0.0, cpdToDate());

    // (2,000 − 3,500 ÷ 26) × 5.95% and 2,000 × 1.63%.
    expect($lines['cpp'])->toBe(110.99)
        ->and($lines['cpp2'])->toBe(0.0)
        ->and($lines['ei'])->toBe(32.60)
        ->and($lines['pensionable_earnings'])->toBe(2000.00)
        ->and($lines['insurable_earnings'])->toBe(2000.00);

    $tax = (new SourceDeductions(cpdRates()))->incomeTax(new TaxInputs(periods: 26, gross: 2000.00, pension: 110.99, secondPension: 0.0, insurance: 32.60, province: 'AB'));
    expect($lines['federal_tax'])->toBe($tax->federal)
        ->and($lines['provincial_tax'])->toBe($tax->provincial)
        ->and($lines['federal_tax'])->toBeGreaterThan(0.0)
        ->and($lines['provincial_tax'])->toBeGreaterThan(0.0);
});

it('has the employer match the pension contribution and pay 1.4 times the premium', function (): void {
    $lines = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 0.0, cpdToDate());

    expect($lines['cpp_er'])->toBe(110.99)
        ->and($lines['cpp2_er'])->toBe(0.0)
        ->and($lines['ei_er'])->toBe(45.64);
});

it('stops each contribution at what the year has left', function (): void {
    $lines = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 0.0, cpdToDate(pensionable: 70000.00, pension: 4200.00, insurance: 1120.00));

    // Maximums of 4,230.45 and 1,123.07 for the year.
    expect($lines['cpp'])->toBe(30.45)
        ->and($lines['ei'])->toBe(3.07);

    $paidUp = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 0.0, cpdToDate(pensionable: 74000.00, pension: 4230.45, insurance: 1123.07));
    expect($paidUp['cpp'])->toBe(0.0)
        ->and($paidUp['ei'])->toBe(0.0)
        ->and($paidUp['ei_er'])->toBe(0.0);
});

it('takes the second contribution only on pay above the first ceiling', function (): void {
    // 74,600 is the first ceiling: 73,000 so far, so 400 of this 2,000 is above it.
    $lines = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 0.0, cpdToDate(pensionable: 73000.00, pension: 4230.45));

    expect($lines['cpp'])->toBe(0.0)
        ->and($lines['cpp2'])->toBe(16.00)
        ->and($lines['cpp2_er'])->toBe(16.00);
});

it('deducts nothing for the plan or the insurance an employee is outside of', function (): void {
    $details = new TaxDetails('AB', null, null, 0.0, false, false);
    $lines = PayDeductions::forPay(cpdRates(), $details, 26, 2000.00, 0.0, cpdToDate());

    expect($lines['cpp'])->toBe(0.0)
        ->and($lines['cpp2'])->toBe(0.0)
        ->and($lines['ei'])->toBe(0.0)
        ->and($lines['cpp_er'])->toBe(0.0)
        ->and($lines['ei_er'])->toBe(0.0)
        ->and($lines['pensionable_earnings'])->toBe(0.0)
        ->and($lines['insurable_earnings'])->toBe(0.0)
        ->and($lines['federal_tax'])->toBeGreaterThan(0.0);
});

it('adds the extra tax an employee asked for to the federal line', function (): void {
    $plain = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 0.0, cpdToDate());
    $extra = PayDeductions::forPay(cpdRates(), new TaxDetails('AB', null, null, 25.00, true, true), 26, 2000.00, 0.0, cpdToDate());

    expect($extra['federal_tax'])->toBe($plain['federal_tax'] + 25.00)
        ->and($extra['provincial_tax'])->toBe($plain['provincial_tax']);
});

it('taxes less when part of the pay was deducted before tax, and leaves the contributions alone', function (): void {
    $plain = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 0.0, cpdToDate());
    $withPlan = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 200.00, cpdToDate());

    expect($withPlan['federal_tax'])->toBeLessThan($plain['federal_tax'])
        ->and($withPlan['provincial_tax'])->toBeLessThan($plain['provincial_tax'])
        ->and($withPlan['cpp'])->toBe($plain['cpp'])
        ->and($withPlan['ei'])->toBe($plain['ei']);
});

it('deducts nothing from a pay of nothing', function (): void {
    expect(PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 0.0, 0.0, cpdToDate()))->toBe(PayDeductions::NONE)
        ->and(PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, -50.0, 0.0, cpdToDate()))->toBe(PayDeductions::NONE);
});

it('adds up the five lines deducted from the employee', function (): void {
    $lines = PayDeductions::forPay(cpdRates(), TaxDetails::starting(), 26, 2000.00, 0.0, cpdToDate());

    expect(PayDeductions::deducted($lines))->toBe($lines['cpp'] + $lines['cpp2'] + $lines['ei'] + $lines['federal_tax'] + $lines['provincial_tax'])
        ->and(PayDeductions::deducted(PayDeductions::NONE))->toBe(0.0);
});
