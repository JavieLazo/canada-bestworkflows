<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Deductions\PercentDeduction;

/**
 * A percentage deduction's rule is one strict value: the two shares, the
 * base, how often it is taken, a monthly cap and whether it comes before
 * tax. This file pins the pesos each shape takes and what the figures
 * refuse.
 */

/** @param array<string, mixed> $changes */
function percentDeductionWith(array $changes = []): PercentDeduction
{
    return PercentDeduction::fromArray(array_replace([
        'employee' => 3.0, 'employer' => 3.0, 'base' => PercentDeduction::BASE_MONTHLY,
        'frequency' => PercentDeduction::FREQUENCY_EVERY, 'cap' => null, 'before_tax' => false,
    ], $changes));
}

it('takes half of a monthly base on each payroll and the whole of a per-payroll base', function () {
    expect(percentDeductionWith()->sharesFor(30000.0, '2026-09-01'))->toBe(['employee' => 450.0, 'employer' => 450.0])
        ->and(percentDeductionWith()->sharesFor(30000.0, '2026-09-16'))->toBe(['employee' => 450.0, 'employer' => 450.0])
        ->and(percentDeductionWith(['base' => PercentDeduction::BASE_BASIC])->sharesFor(12000.0, '2026-09-01'))->toBe(['employee' => 360.0, 'employer' => 360.0])
        ->and(percentDeductionWith(['base' => PercentDeduction::BASE_GROSS, 'employer' => 0.0])->sharesFor(15000.0, '2026-09-01'))->toBe(['employee' => 450.0, 'employer' => 0.0])
        ->and(percentDeductionWith()->sharesFor(0.0, '2026-09-01'))->toBe(['employee' => 0.0, 'employer' => 0.0]);
});

it('takes the whole month on the second cut-off only when so set', function () {
    $second = percentDeductionWith(['frequency' => PercentDeduction::FREQUENCY_SECOND]);
    expect($second->dueOn('2026-09-01'))->toBeFalse()
        ->and($second->dueOn('2026-09-16'))->toBeTrue()
        ->and($second->sharesFor(30000.0, '2026-09-01'))->toBe(['employee' => 0.0, 'employer' => 0.0])
        ->and($second->sharesFor(30000.0, '2026-09-16'))->toBe(['employee' => 900.0, 'employer' => 900.0]);
});

it('keeps each share under the monthly cap, halved like the base', function () {
    expect(percentDeductionWith(['cap' => 500.0])->sharesFor(30000.0, '2026-09-01'))->toBe(['employee' => 250.0, 'employer' => 250.0])
        ->and(percentDeductionWith(['cap' => 500.0, 'frequency' => PercentDeduction::FREQUENCY_SECOND])->sharesFor(30000.0, '2026-09-16'))->toBe(['employee' => 500.0, 'employer' => 500.0])
        ->and(percentDeductionWith(['cap' => 5000.0])->sharesFor(30000.0, '2026-09-01'))->toBe(['employee' => 450.0, 'employer' => 450.0])
        ->and(percentDeductionWith(['employee' => 2.5, 'employer' => 0.0])->sharesFor(33333.33, '2026-09-01'))->toBe(['employee' => 416.67, 'employer' => 0.0]);
});

it('reads figures strictly and names what is wrong', function () {
    $good = percentDeductionWith()->toArray();
    expect(PercentDeduction::error($good))->toBeNull()
        ->and(PercentDeduction::fromArray($good)->toArray())->toBe($good)
        ->and(PercentDeduction::error('x'))->toBe('The percentage rule is missing.')
        ->and(PercentDeduction::error(['employee' => 101.0] + $good))->toBe('Employee share: enter a percent between 0 and 100.')
        ->and(PercentDeduction::error(['employer' => -1.0] + $good))->toBe('Employer share: enter a percent between 0 and 100.')
        ->and(PercentDeduction::error(['employee' => 0.0, 'employer' => 0.0] + $good))->toBe('Enter an employee share, an employer share or both.')
        ->and(PercentDeduction::error(['base' => 'NET'] + $good))->toBe('Choose what the percentage is based on.')
        ->and(PercentDeduction::error(['frequency' => 'FIRST'] + $good))->toBe('Choose how often it is taken.')
        ->and(PercentDeduction::error(['cap' => 0.0] + $good))->toBe('Enter the monthly cap, or leave it blank.')
        ->and(PercentDeduction::error(['before_tax' => 'yes'] + $good))->toBe('Say whether it is deducted before tax.')
        ->and(fn () => PercentDeduction::fromArray(['base' => 'NET'] + $good))->toThrow(InvalidArgumentException::class);
});
