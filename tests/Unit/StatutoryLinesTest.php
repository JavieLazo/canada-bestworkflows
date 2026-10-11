<?php

declare(strict_types=1);

use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutoryLines;
use App\Support\Money;

/**
 * The per-payslip statutory lines, ported from payroll.ts:201-212 and
 * :583-660, are ONE body that the engine and the payroll application's tax
 * re-derivation share. These pin the arithmetic
 * directly: a null, empty or SEMI_MONTHLY mode halves the monthly figure;
 * any other truthy mode charges the whole month only on a payslip whose
 * period ends after the 15th (judged by the END day alone); a fixed figure
 * above 0 replaces the table; the switch and the gross > 0 gate both turn a
 * line off; the taxable base floors at 0; withholding prefers a fixed tax,
 * then a percentage, then the BIR table (annualized when asked). Every line
 * is rounded ONCE at its call site, as the reference does — so the expected
 * figures here are single rounds, never Statutory::semiMonthlyShare's double.
 *
 * Pure PHP; no container, so this runs under tests/Unit.
 */

/** A line as the engine posts it: rounded once at the call site. */
function statLinesPosted(mixed $allow, bool $gross, mixed $fixed, float $table, mixed $mode, bool $endsMonth): float
{
    return Money::round(StatutoryLines::contribution($allow, $gross, $fixed, $table, $mode, $endsMonth));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function statLinesEmployee(array $overrides = []): object
{
    return (object) array_merge([
        'allow_withholding_tax' => true,
        'annualize_tax' => false,
        'fixed_tax' => 0,
        'percent_tax' => 0,
    ], $overrides);
}

/* |---| The month-end test |---| */

it('judges a payslip month-ending by its end day alone', function () {
    expect(StatutoryLines::endsMonth('2026-08-15'))->toBeFalse()
        ->and(StatutoryLines::endsMonth('2026-08-16'))->toBeTrue()
        ->and(StatutoryLines::endsMonth('2026-08-31'))->toBeTrue()
        ->and(StatutoryLines::endsMonth('2026-02-28'))->toBeTrue()
        // A period crossing a month end is judged by where it ends.
        ->and(StatutoryLines::endsMonth('2026-09-03'))->toBeFalse();
});

/* |---| Contributions |---| */

it('halves the monthly figure for a null, empty or SEMI_MONTHLY mode, on either cut-off', function (mixed $mode) {
    expect(statLinesPosted(true, true, 0, 1500.0, $mode, false))->toBe(750.0)
        ->and(statLinesPosted(true, true, 0, 1500.0, $mode, true))->toBe(750.0);
})->with([
    'null' => [null],
    'empty' => [''],
    'semi-monthly' => ['SEMI_MONTHLY'],
]);

it('charges any other truthy mode the whole month on a month-ending payslip and nothing on the other', function (mixed $mode) {
    expect(statLinesPosted(true, true, 0, 1500.0, $mode, true))->toBe(1500.0)
        ->and(statLinesPosted(true, true, 0, 1500.0, $mode, false))->toBe(0.0);
})->with([
    'monthly' => ['MONTHLY'],
    // The form offers it and the server refuses to save it, but legacy rows
    // may carry it; the reference treats it as monthly, and so does this.
    'quarterly' => ['QUARTERLY'],
    // JavaScript truthiness: a '0' string is truthy there.
    'zero string' => ['0'],
]);

it('replaces the table with a fixed figure above zero, rounded once at the call site', function () {
    expect(statLinesPosted(true, true, 800, 1500.0, 'SEMI_MONTHLY', false))->toBe(400.0)
        // Zero or a negative fixed figure is "no override": the table stands.
        ->and(statLinesPosted(true, true, 0, 1500.0, 'SEMI_MONTHLY', false))->toBe(750.0)
        ->and(statLinesPosted(true, true, -10, 1500.0, 'SEMI_MONTHLY', false))->toBe(750.0)
        ->and(statLinesPosted(true, true, 'junk', 1500.0, 'SEMI_MONTHLY', false))->toBe(750.0)
        // An odd centavo halved lands on a half-centavo: 256.03 / 2 is
        // 128.01499999999999 as a double, which the reference's
        // Math.round(x * 100) / 100 takes to 128.01 — one round, not two.
        ->and(statLinesPosted(true, true, 256.03, 0.0, 'SEMI_MONTHLY', false))->toBe(128.01)
        ->and(StatutoryLines::contribution(true, true, 256.03, 0.0, 'SEMI_MONTHLY', false))->toBe(256.03 / 2);
});

it('charges nothing when the switch is off or the payslip has no gross pay', function () {
    expect(statLinesPosted(false, true, 0, 1500.0, null, false))->toBe(0.0)
        ->and(statLinesPosted(null, true, 0, 1500.0, null, false))->toBe(0.0)
        ->and(statLinesPosted(0, true, 0, 1500.0, null, false))->toBe(0.0)
        ->and(statLinesPosted(true, false, 800, 1500.0, 'MONTHLY', true))->toBe(0.0);
});

it('posts the table figures a 30,000 monthly rate implies, half on a semi-monthly payslip', function () {
    $sss = Statutory::sss(30000);
    $philhealth = Statutory::philhealth(30000);
    $pagibig = Statutory::pagibig(30000);

    expect(statLinesPosted(true, true, 0, $sss['employee'], null, false))->toBe(750.0)
        ->and(statLinesPosted(true, true, 0, $sss['employer'], null, false))->toBe(1515.0)
        ->and(statLinesPosted(true, true, 0, $philhealth['employee'], null, false))->toBe(375.0)
        ->and(statLinesPosted(true, true, 0, $pagibig['employee'], null, false))->toBe(100.0)
        ->and(statLinesPosted(true, true, 0, $pagibig['employer'], null, false))->toBe(100.0);
});

/* |---| The taxable base |---| */

it('takes gross less the three employee shares, late and undertime, floored at zero', function () {
    expect(StatutoryLines::taxable(15_000.0, 750.0, 375.0, 100.0, 20.5, 10.25))->toBe(15_000.0 - (750.0 + 375.0 + 100.0) - 20.5 - 10.25)
        ->and(StatutoryLines::taxable(1_000.0, 750.0, 375.0, 100.0, 0.0, 0.0))->toBe(0.0)
        ->and(StatutoryLines::taxable(0.0, 0.0, 0.0, 0.0, 0.0, 0.0))->toBe(0.0);
});

/* |---| Withholding |---| */

it('withholds by the semi-monthly BIR table unless annualization is on', function () {
    expect(StatutoryLines::withholding(statLinesEmployee(), 13_060.0))->toBe(Statutory::semiMonthlyWithholdingTax(13_060.0))
        ->and(StatutoryLines::withholding(statLinesEmployee(), 13_060.0))->toBe(396.45)
        ->and(StatutoryLines::withholding(statLinesEmployee(['annualize_tax' => true]), 13_060.0))
        ->toBe(Statutory::annualizedSemiMonthlyWithholdingTax(13_060.0))
        ->and(StatutoryLines::withholding(statLinesEmployee(), 10_000.0))->toBe(0.0);
});

it('prefers a fixed tax, then a percentage, over the table', function () {
    expect(StatutoryLines::withholding(statLinesEmployee(['fixed_tax' => 500, 'percent_tax' => 10]), 13_060.0))->toBe(500.0)
        ->and(StatutoryLines::withholding(statLinesEmployee(['percent_tax' => 10]), 13_060.0))->toBe(1_306.0)
        // A percentage of an odd base rounds once.
        ->and(StatutoryLines::withholding(statLinesEmployee(['percent_tax' => 3]), 10_000.55))->toBe(Money::round((10_000.55 * 3) / 100));
});

it('withholds nothing when the switch is off, whatever else is set', function () {
    expect(StatutoryLines::withholding(statLinesEmployee(['allow_withholding_tax' => false, 'fixed_tax' => 500]), 13_060.0))->toBe(0.0)
        ->and(StatutoryLines::withholding((object) [], 50_000.0))->toBe(0.0);
});

/* |---| JavaScript truthiness |---| */

it('reads a switch or a mode with JavaScript truthiness', function () {
    expect(StatutoryLines::truthy(null))->toBeFalse()
        ->and(StatutoryLines::truthy(false))->toBeFalse()
        ->and(StatutoryLines::truthy(0))->toBeFalse()
        ->and(StatutoryLines::truthy(0.0))->toBeFalse()
        ->and(StatutoryLines::truthy(NAN))->toBeFalse()
        ->and(StatutoryLines::truthy(''))->toBeFalse()
        ->and(StatutoryLines::truthy('0'))->toBeTrue()
        ->and(StatutoryLines::truthy('MONTHLY'))->toBeTrue()
        ->and(StatutoryLines::truthy(1))->toBeTrue()
        ->and(StatutoryLines::truthy(true))->toBeTrue();
});
