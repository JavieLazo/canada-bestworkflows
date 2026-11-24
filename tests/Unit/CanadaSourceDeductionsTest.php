<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Modules\Payroll\Domain\Canada\SourceDeductions;
use App\Modules\Payroll\Domain\Canada\TaxInputs;

/**
 * Canada Pension Plan, Employment Insurance and income tax at source, held to
 * what the Canada Revenue Agency publishes.
 *
 * Two kinds of evidence. The formulas guide's own worked figures (a weekly
 * pension contribution, a year's premium, the credit behind each claim code)
 * pin single steps. The agency's printed deduction tables for Alberta pin the
 * whole tax calculation: a row is the tax at the middle of its pay range,
 * rounded to five cents, so the calculator must land within five cents of it.
 * The tables stop being exact once a pay annualized passes the pension
 * ceiling, as their own notes say, so every row used here is below it.
 */
function canDed2026(): SourceDeductions
{
    return new SourceDeductions(DeductionRates::inForceOn('2026-06-15'));
}

function canDed2027(): SourceDeductions
{
    return new SourceDeductions(DeductionRates::inForceOn('2027-01-15'));
}

/**
 * Federal and Alberta tax on a biweekly pay with nothing but the statutory deductions.
 *
 * @return array{float, float}
 */
function canDedBiweeklyTax(float $pay, ?float $federalClaim, ?float $provincialClaim): array
{
    $d = canDed2026();
    $tax = $d->incomeTax(new TaxInputs(26, $pay, $d->pension($pay, 26), 0.0, $d->insurance($pay), 'AB', $federalClaim, $provincialClaim));

    return [$tax->federal, $tax->provincial];
}

/* |---| Which figures are in force |---| */

it('reads the set in force on the pay date', function () {
    expect(DeductionRates::inForceOn('2026-01-01')->effectiveFrom)->toBe('2026-01-01')
        ->and(DeductionRates::inForceOn('2026-12-31')->effectiveFrom)->toBe('2026-01-01')
        ->and(DeductionRates::inForceOn('2027-01-01')->effectiveFrom)->toBe('2027-01-01')
        ->and(DeductionRates::inForceOn('2027-08-20')->effectiveFrom)->toBe('2027-01-01');
});

it('refuses a date before the first published set', function () {
    expect(fn () => DeductionRates::inForceOn('2025-12-31'))->toThrow(InvalidArgumentException::class);
});

it('reads stored figures strictly', function () {
    $figures = DeductionRates::inForceOn('2026-06-15')->toArray();
    expect(DeductionRates::fromArray('2026-01-01', $figures)->pension['rate'])->toBe(0.0595);

    $missing = $figures;
    unset($missing['insurance']['rate']);
    $text = $figures;
    $text['pension']['rate'] = '0.0595';
    $unordered = $figures;
    $unordered['federal']['brackets'] = array_reverse($figures['federal']['brackets']);

    expect(fn () => DeductionRates::fromArray('2026-01-01', $missing))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DeductionRates::fromArray('2026-01-01', $text))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DeductionRates::fromArray('2026-01-01', $unordered))->toThrow(InvalidArgumentException::class);
});

it('names a province it has no tax figures for', function () {
    $d = canDed2026();

    expect(fn () => $d->incomeTax(new TaxInputs(26, 2000.0, 110.0, 0.0, 32.0, 'QC')))->toThrow(InvalidArgumentException::class, 'QC');
});

/* |---| Canada Pension Plan |---| */

it('drops the third decimal of the pension exemption for a pay period', function (int $periods, float $exemption) {
    expect(canDed2026()->pensionExemption($periods))->toBe($exemption);
})->with([
    'monthly' => [12, 291.66], 'semi-monthly' => [24, 145.83], 'biweekly' => [26, 134.61], 'biweekly, 27 pays' => [27, 129.62],
    'weekly' => [52, 67.30], 'weekly, 53 pays' => [53, 66.03], '10 pays' => [10, 350.00], 'hourly' => [2000, 1.75],
]);

it('contributes the rate on the pay above the exemption', function () {
    // The guide's own example: 3,500 in a weekly pay.
    expect(canDed2026()->pension(3500.0, 52))->toBe(204.25)
        ->and(canDed2027()->pension(3500.0, 52))->toBe(197.38)
        ->and(canDed2026()->pension(1000.0, 52))->toBe(55.50)
        ->and(canDed2026()->pension(100.0, 26))->toBe(0.0);
});

it('stops the pension contribution at the year\'s maximum', function () {
    $d = canDed2026();

    expect($d->pension(5000.0, 26, 4200.00))->toBe(30.45)
        ->and($d->pension(5000.0, 26, 4230.45))->toBe(0.0)
        // Six months under the plan: half the maximum.
        ->and($d->pension(5000.0, 26, 2100.00, 6))->toBe(15.23);
});

it('takes the second contribution only on pay above the first ceiling', function () {
    $d = canDed2026();

    expect($d->secondPension(3000.0, 60000.0))->toBe(0.0)
        // 74,000 so far and 2,000 now: 1,400 of it is above 74,600.
        ->and($d->secondPension(2000.0, 74000.0))->toBe(56.00)
        ->and($d->secondPension(2000.0, 80000.0))->toBe(80.00)
        ->and($d->secondPension(2000.0, 84500.0, 400.00))->toBe(16.00)
        ->and($d->secondPension(2000.0, 90000.0, 416.00))->toBe(0.0);
});

/* |---| Employment Insurance |---| */

it('charges the premium rate on insurable pay up to the year\'s maximum', function () {
    expect(canDed2026()->insurance(1000.0))->toBe(16.30)
        ->and(canDed2027()->insurance(1000.0))->toBe(16.40)
        // The guide's example: 30,000 of insurable earnings in 2027.
        ->and(canDed2027()->insurance(30000.0))->toBe(492.00)
        // A half cent on paper rounds up: 2,150.00 at 1.63% is 35.045.
        ->and(canDed2026()->insurance(2150.0))->toBe(35.05)
        ->and(canDed2026()->insurance(3000.0, 1100.00))->toBe(23.07)
        ->and(canDed2026()->insurance(3000.0, 1123.07))->toBe(0.0);
});

it('sets the employer\'s premium at 1.4 times the employee\'s', function () {
    expect(canDed2026()->employerInsurance(16.30))->toBe(22.82)
        ->and(canDed2026()->employerInsurance(1123.07))->toBe(1572.30);
});

/* |---| Income tax |---| */

it('lands on the agency\'s printed tables for Alberta, federal tax', function (float $from, float $to, float $noClaim, float $basicClaim) {
    $pay = ($from + $to) / 2;

    expect(round(abs(canDedBiweeklyTax($pay, 0.0, 0.0)[0] - $noClaim), 2))->toBeLessThanOrEqual(0.05)
        ->and(round(abs(canDedBiweeklyTax($pay, 16452.0, 22769.0)[0] - $basicClaim), 2))->toBeLessThanOrEqual(0.05);
})->with([
    '1,003 to 1,011' => [1003.0, 1011.0, 123.35, 34.75],
    '1,507 to 1,523' => [1507.0, 1523.0, 189.10, 100.50],
    '2,003 to 2,019' => [2003.0, 2019.0, 253.25, 164.65],
    '2,515 to 2,539, second bracket' => [2515.0, 2539.0, 336.45, 247.85],
    '2,851 to 2,875, second bracket' => [2851.0, 2875.0, 402.00, 313.40],
]);

it('lands on the agency\'s printed tables for Alberta, provincial tax', function (float $from, float $to, float $noClaim, float $basicClaim) {
    $pay = ($from + $to) / 2;

    expect(round(abs(canDedBiweeklyTax($pay, 0.0, 0.0)[1] - $noClaim), 2))->toBeLessThanOrEqual(0.05)
        ->and(round(abs(canDedBiweeklyTax($pay, 16452.0, 22769.0)[1] - $basicClaim), 2))->toBeLessThanOrEqual(0.05);
})->with([
    '1,500 to 1,508' => [1500.0, 1508.0, 111.85, 41.80],
    '1,996 to 2,012' => [1996.0, 2012.0, 148.80, 78.75],
    '2,500 to 2,524, second bracket' => [2500.0, 2524.0, 189.05, 119.00],
    '2,836 to 2,860, second bracket' => [2836.0, 2860.0, 220.80, 150.75],
]);

it('reads no filed return as the basic personal amounts', function () {
    expect(canDedBiweeklyTax(2011.0, null, null))->toBe(canDedBiweeklyTax(2011.0, 16452.0, 22769.0));
});

it('reduces the federal basic amount across the fourth bracket', function () {
    $d = canDed2026();

    expect($d->federalBasicAmount(100000.0))->toBe(16452.0)
        ->and($d->federalBasicAmount(181440.0))->toBe(16452.0)
        // Halfway across: half of the 1,623 difference is gone.
        ->and($d->federalBasicAmount(219961.0))->toBe(15640.5)
        ->and($d->federalBasicAmount(258482.0))->toBe(14829.0)
        ->and($d->federalBasicAmount(400000.0))->toBe(14829.0);
});

it('deducts nothing but the requested extra when the taxable income is below zero', function () {
    $tax = canDed2026()->incomeTax(new TaxInputs(26, 500.0, 21.74, 0.0, 8.15, 'AB', annualDeductions: 20000.0, additionalTax: 25.0));

    expect($tax->federal)->toBe(0.0)->and($tax->provincial)->toBe(0.0)->and($tax->total())->toBe(25.0);
});

it('deducts no tax where the credits cover it, and adds the extra tax asked for', function () {
    $d = canDed2026();
    $low = $d->incomeTax(new TaxInputs(26, 600.0, $d->pension(600.0, 26), 0.0, $d->insurance(600.0), 'AB'));
    $withExtra = $d->incomeTax(new TaxInputs(26, 2011.0, $d->pension(2011.0, 26), 0.0, $d->insurance(2011.0), 'AB', additionalTax: 40.0));

    expect($low->federal)->toBe(0.0)->and($low->provincial)->toBe(0.0)
        ->and($withExtra->total())->toBe($withExtra->federal + $withExtra->provincial + 40.0)
        ->and($withExtra->federal)->toBe(canDedBiweeklyTax(2011.0, null, null)[0]);
});

it('takes registered plan contributions and union dues off the taxable income', function () {
    $d = canDed2026();
    $pension = $d->pension(2011.0, 26);
    $premium = $d->insurance(2011.0);
    $plain = $d->incomeTax(new TaxInputs(26, 2011.0, $pension, 0.0, $premium, 'AB'));
    $reduced = $d->incomeTax(new TaxInputs(26, 2011.0, $pension, 0.0, $premium, 'AB', registeredPlan: 100.0, unionDues: 20.0));

    // 120 a period is 3,120 a year less income, in the first bracket of both.
    expect($reduced->annualTaxable)->toBe($plain->annualTaxable - 3120.0)
        ->and($reduced->annualFederal)->toBe(round($plain->annualFederal - 0.14 * 3120.0, 2))
        ->and($reduced->annualProvincial)->toBe(round($plain->annualProvincial - 0.08 * 3120.0, 2));
});

it('keeps the whole pension and premium credit once the year\'s maximum is reached', function () {
    $d = canDed2026();
    // A period after both maximums: nothing is deducted, the credit stays.
    $after = $d->incomeTax(new TaxInputs(26, 4000.0, 0.0, 0.0, 0.0, 'AB', pensionToDate: 4230.45, insuranceToDate: 1123.07));
    $without = $d->incomeTax(new TaxInputs(26, 4000.0, 0.0, 0.0, 0.0, 'AB'));

    expect(round($without->annualFederal - $after->annualFederal, 2))->toBe(round(0.14 * 3519.45 + 0.14 * 1123.07, 2))
        ->and(round($without->annualProvincial - $after->annualProvincial, 2))->toBe(round(0.08 * 3519.45 + 0.08 * 1123.07, 2));
});

it('gives Alberta\'s further credit on credits above its set amount', function () {
    $d = canDed2026();
    $pension = $d->pension(4000.0, 26);
    $premium = $d->insurance(4000.0);
    $basic = $d->incomeTax(new TaxInputs(26, 4000.0, $pension, 0.0, $premium, 'AB', provincialClaim: 22769.0));
    $large = $d->incomeTax(new TaxInputs(26, 4000.0, $pension, 0.0, $premium, 'AB', provincialClaim: 70000.0));

    // A claim of 70,000 is a credit of 5,600; with the pension and premium
    // credits it passes 4,896, and a quarter of the part above is added.
    // At 4,000 a pay both of those credits are at their yearly maximums.
    $shared = round(0.08 * 3519.45 + 0.08 * 1123.07, 2);
    $further = round((5600.0 + $shared - 4896.0) * 0.25, 2);

    expect($further)->toBeGreaterThan(0.0)
        ->and(round($basic->annualProvincial - $large->annualProvincial, 2))->toBe(round(5600.0 - 1821.52 + $further, 2));
});

it('uses the credit rates the guide prints beside each claim code', function () {
    // Claim code 1 in 2027: 16,879 federally is a credit of 2,363.06, and
    // 23,225 in Alberta is 1,858.00.
    $d = canDed2027();
    $none = $d->incomeTax(new TaxInputs(26, 3000.0, 150.0, 0.0, 49.2, 'AB', 0.0, 0.0));
    $basic = $d->incomeTax(new TaxInputs(26, 3000.0, 150.0, 0.0, 49.2, 'AB', 16879.0, 23225.0));

    expect(round($none->annualFederal - $basic->annualFederal, 2))->toBe(2363.06)
        ->and(round($none->annualProvincial - $basic->annualProvincial, 2))->toBe(1858.00);
});
