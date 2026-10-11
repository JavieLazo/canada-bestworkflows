<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Benefits\ExemptBenefitCeiling;
use App\Modules\Payroll\Domain\Benefits\ExemptBenefitSplit;

/**
 * The PHP 90,000 exempt-benefit ceiling (NIRC 32(B)(7)(e) as amended by
 * TRAIN) is ONE pure rule: an amount splits into the part still under the
 * ceiling and the part over it; the two always add back up to the amount;
 * only the exempt part is spent from the allowance; and several lines of one
 * payslip spend it in order, so the line that crosses the ceiling carries the
 * excess. Negative or junk input collapses to 0, never refunds the allowance.
 *
 * Pure PHP; no container, so this runs under tests/Unit.
 */

/**
 * The split as a plain array, for one readable expectation per case.
 *
 * @return array{exempt: float, excess: float, used: float}
 */
function exceilParts(ExemptBenefitSplit $split): array
{
    return ['exempt' => $split->exempt, 'excess' => $split->taxableExcess, 'used' => $split->usedAfter];
}

/* |---| One amount |---| */

it('keeps an amount wholly exempt while the year stays under the ceiling', function () {
    expect(exceilParts(ExemptBenefitCeiling::split(0.0, 25_000.0)))->toBe(['exempt' => 25_000.0, 'excess' => 0.0, 'used' => 25_000.0])
        ->and(exceilParts(ExemptBenefitCeiling::split(40_000.0, 50_000.0)))->toBe(['exempt' => 50_000.0, 'excess' => 0.0, 'used' => 90_000.0]);
});

it('taxes exactly the part over the ceiling, to the centavo', function () {
    expect(exceilParts(ExemptBenefitCeiling::split(80_000.0, 15_000.0)))->toBe(['exempt' => 10_000.0, 'excess' => 5_000.0, 'used' => 90_000.0])
        ->and(exceilParts(ExemptBenefitCeiling::split(89_999.99, 0.02)))->toBe(['exempt' => 0.01, 'excess' => 0.01, 'used' => 90_000.0])
        ->and(exceilParts(ExemptBenefitCeiling::split(12_345.67, 80_000.00)))->toBe(['exempt' => 77_654.33, 'excess' => 2_345.67, 'used' => 90_000.0]);
});

it('leaves no allowance once the year has reached or passed the ceiling', function () {
    expect(exceilParts(ExemptBenefitCeiling::split(90_000.0, 1_000.0)))->toBe(['exempt' => 0.0, 'excess' => 1_000.0, 'used' => 90_000.0])
        // A ledger above the ceiling (a figure typed outside EMS, say) still
        // leaves nothing, and never a negative allowance.
        ->and(exceilParts(ExemptBenefitCeiling::split(120_000.0, 1_000.0)))->toBe(['exempt' => 0.0, 'excess' => 1_000.0, 'used' => 120_000.0]);
});

it('always splits an amount into parts that add back up to it', function (float $used, float $amount) {
    $split = ExemptBenefitCeiling::split($used, $amount);

    expect($split->exempt + $split->taxableExcess)->toEqualWithDelta($amount, 0.001)
        ->and($split->exempt)->toBeGreaterThanOrEqual(0.0)
        ->and($split->taxableExcess)->toBeGreaterThanOrEqual(0.0)
        ->and($split->exempt)->toBeLessThanOrEqual(max(0.0, ExemptBenefitCeiling::CEILING - $used) + 0.001);
})->with([
    'nothing used, small' => [0.0, 0.01],
    'nothing used, over the whole ceiling' => [0.0, 125_000.50],
    'half used' => [45_000.25, 45_000.25],
    'one centavo left' => [89_999.99, 500.0],
    'odd centavos' => [33_333.33, 66_666.67],
]);

it('treats a negative or junk amount or ledger as zero', function () {
    expect(exceilParts(ExemptBenefitCeiling::split(0.0, -500.0)))->toBe(['exempt' => 0.0, 'excess' => 0.0, 'used' => 0.0])
        ->and(exceilParts(ExemptBenefitCeiling::split(-10_000.0, 1_000.0)))->toBe(['exempt' => 1_000.0, 'excess' => 0.0, 'used' => 1_000.0])
        ->and(exceilParts(ExemptBenefitCeiling::split('junk', 'nope')))->toBe(['exempt' => 0.0, 'excess' => 0.0, 'used' => 0.0])
        ->and(exceilParts(ExemptBenefitCeiling::split(null, NAN)))->toBe(['exempt' => 0.0, 'excess' => 0.0, 'used' => 0.0]);
});

/* |---| Several lines of one payslip |---| */

it('spends the allowance line by line, so the crossing line carries the excess and later lines are taxed whole', function () {
    $splits = ExemptBenefitCeiling::allocate(70_000.0, [15_000.0, 10_000.0, 2_500.0]);

    expect(array_map(exceilParts(...), $splits))->toBe([
        ['exempt' => 15_000.0, 'excess' => 0.0, 'used' => 85_000.0],
        ['exempt' => 5_000.0, 'excess' => 5_000.0, 'used' => 90_000.0],
        ['exempt' => 0.0, 'excess' => 2_500.0, 'used' => 90_000.0],
    ]);
});

it('allocates nothing for no lines and matches split for a single line', function () {
    expect(ExemptBenefitCeiling::allocate(1_000.0, []))->toBe([]);

    $single = ExemptBenefitCeiling::allocate(88_000.0, [4_000.0]);
    expect($single)->toHaveCount(1)
        ->and(exceilParts($single[0]))->toBe(exceilParts(ExemptBenefitCeiling::split(88_000.0, 4_000.0)));
});
