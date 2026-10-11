<?php

declare(strict_types=1);

use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;

/**
 * The statutory figures are a value the engine is handed: the built-in
 * schedule reproduces the reference's constants exactly, a schedule is read
 * strictly, and a different schedule changes what the tables compute.
 */
it('computes the reference figures from the built-in schedule and nothing else from a changed one', function () {
    $builtIn = StatutorySchedule::builtIn();
    expect($builtIn->toArray())->toBe(StatutorySchedule::BUILT_IN)
        ->and(Statutory::sss(30000))->toBe(['employee' => 1500.0, 'employer' => 3030.0])
        ->and(Statutory::sss(30000, $builtIn))->toBe(['employee' => 1500.0, 'employer' => 3030.0])
        ->and(Statutory::philhealth(30000, $builtIn))->toBe(['employee' => 750.0, 'employer' => 750.0])
        ->and(Statutory::pagibig(30000, $builtIn))->toBe(['employee' => 200.0, 'employer' => 200.0])
        ->and(Statutory::pagibig(1500, $builtIn))->toBe(['employee' => 15.0, 'employer' => 30.0])
        ->and(Statutory::semiMonthlyWithholdingTax(10417, $builtIn))->toBe(0.0)
        ->and(Statutory::semiMonthlyWithholdingTax(20000, $builtIn))->toBe(1604.1)
        ->and(Statutory::annualIncomeTax(250000, $builtIn))->toBe(0.0)
        ->and(Statutory::annualIncomeTax(1200000, $builtIn))->toBe(202500.0)
        ->and(Statutory::annualizedSemiMonthlyWithholdingTax(20000, $builtIn))->toBe(1604.17);

    // A changed PhilHealth rate changes PhilHealth and nothing else.
    $changed = $builtIn->with(StatutorySchedule::PHILHEALTH, ['floor' => 10000, 'cap' => 100000, 'ee_rate' => 0.03, 'er_rate' => 0.02]);
    expect(Statutory::philhealth(30000, $changed))->toBe(['employee' => 900.0, 'employer' => 600.0])
        ->and(Statutory::sss(30000, $changed))->toBe(['employee' => 1500.0, 'employer' => 3030.0])
        ->and($changed->toArray()[StatutorySchedule::PHILHEALTH])->toBe(['floor' => 10000.0, 'cap' => 100000.0, 'ee_rate' => 0.03, 'er_rate' => 0.02]);

    // A changed SSS credit ceiling and work-injury fee.
    $sss = $builtIn->with(StatutorySchedule::SSS, [...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'msc_max' => 40000.0, 'ec_high' => 50.0]);
    expect(Statutory::sss(50000, $sss))->toBe(['employee' => 2000.0, 'employer' => 4050.0]);

    // A changed withholding bracket list.
    $tax = $builtIn->with(StatutorySchedule::WITHHOLDING_TAX, [
        'semi_monthly' => [[12500.0, 0.0, 0.2], [25000.0, 2500.0, 0.3]],
        'annual' => [[300000.0, 0.0, 0.2]],
    ]);
    expect(Statutory::semiMonthlyWithholdingTax(12500, $tax))->toBe(0.0)
        ->and(Statutory::semiMonthlyWithholdingTax(20000, $tax))->toBe(1500.0)
        ->and(Statutory::semiMonthlyWithholdingTax(30000, $tax))->toBe(4000.0)
        ->and(Statutory::annualIncomeTax(400000, $tax))->toBe(20000.0);
});

it('reads a schedule strictly and names what is wrong with it', function () {
    expect(StatutorySchedule::error(StatutorySchedule::SSS, StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]))->toBeNull()
        ->and(StatutorySchedule::error('GSIS', []))->toBe('Unknown statutory table.')
        ->and(StatutorySchedule::error(StatutorySchedule::SSS, 'x'))->toBe('The SSS figures are missing.')
        ->and(StatutorySchedule::error(StatutorySchedule::SSS, ['msc_min' => 5000]))->toBe('The SSS figures are incomplete.')
        ->and(StatutorySchedule::error(StatutorySchedule::SSS, [...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'extra' => 1]))->toBe('The SSS figures are incomplete.')
        ->and(StatutorySchedule::error(StatutorySchedule::SSS, [...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'ee_rate' => '0.05']))->toBe('Enter a number for ee_rate.')
        ->and(StatutorySchedule::error(StatutorySchedule::SSS, [...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'ee_rate' => 1.5]))->toBe('The ee_rate must be between 0% and 100%.')
        ->and(StatutorySchedule::error(StatutorySchedule::SSS, [...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'step' => 0]))->toBe('The salary credit step must be above zero.')
        ->and(StatutorySchedule::error(StatutorySchedule::SSS, [...StatutorySchedule::BUILT_IN[StatutorySchedule::SSS], 'msc_min' => 40000]))->toBe('The lowest salary credit must not exceed the highest.')
        ->and(StatutorySchedule::error(StatutorySchedule::PHILHEALTH, [...StatutorySchedule::BUILT_IN[StatutorySchedule::PHILHEALTH], 'floor' => 200000]))->toBe('The lowest salary must not exceed the highest.')
        ->and(StatutorySchedule::error(StatutorySchedule::PAGIBIG, [...StatutorySchedule::BUILT_IN[StatutorySchedule::PAGIBIG], 'cap' => -1]))->toBe('The cap cannot be negative.')
        ->and(StatutorySchedule::error(StatutorySchedule::WITHHOLDING_TAX, ['semi_monthly' => [], 'annual' => []]))->toBe('Enter at least one tax bracket.')
        ->and(StatutorySchedule::error(StatutorySchedule::WITHHOLDING_TAX, ['semi_monthly' => [[1, 2]], 'annual' => [[1, 0, 0.1]]]))->toBe('Each tax bracket needs an amount, a fixed tax and a rate.')
        ->and(StatutorySchedule::error(StatutorySchedule::WITHHOLDING_TAX, ['semi_monthly' => [[100, 0, 0.1], [50, 10, 0.2]], 'annual' => [[1, 0, 0.1]]]))->toBe('Tax brackets must rise from one to the next.')
        ->and(StatutorySchedule::error(StatutorySchedule::WITHHOLDING_TAX, ['semi_monthly' => [[100, 0, 2]], 'annual' => [[1, 0, 0.1]]]))->toBe('A tax rate must be between 0% and 100%.');

    expect(fn () => StatutorySchedule::fromArrays([StatutorySchedule::SSS => StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]]))
        ->toThrow(InvalidArgumentException::class, 'The PHILHEALTH table is missing.');
    expect(fn () => StatutorySchedule::fromArrays([...StatutorySchedule::BUILT_IN, 'GSIS' => []]))
        ->toThrow(InvalidArgumentException::class, 'Unknown statutory table.');

    // Integers are read as the floats they name.
    $ints = StatutorySchedule::fromArrays([...StatutorySchedule::BUILT_IN, StatutorySchedule::PAGIBIG => ['cap' => 10000, 'low_line' => 1500, 'ee_low_rate' => 0.01, 'ee_rate' => 0.02, 'er_rate' => 0.02]]);
    expect($ints->pagibig)->toBe(StatutorySchedule::BUILT_IN[StatutorySchedule::PAGIBIG]);
});

it('prices a bracket list exactly as the reference ladder does', function () {
    $brackets = StatutorySchedule::BUILT_IN[StatutorySchedule::WITHHOLDING_TAX]['semi_monthly'];
    foreach ([0, 10417, 10417.01, 16667, 16668, 33333, 50000, 83333, 100000, 333333, 500000] as $amount) {
        $ladder = match (true) {
            $amount <= 10417 => 0.0,
            $amount <= 16667 => ($amount - 10417) * 0.15,
            $amount <= 33333 => 937.5 + ($amount - 16667) * 0.2,
            $amount <= 83333 => 4270.7 + ($amount - 33333) * 0.25,
            $amount <= 333333 => 16770.7 + ($amount - 83333) * 0.3,
            default => 91770.7 + ($amount - 333333) * 0.35,
        };
        expect(StatutorySchedule::bracketTax($brackets, (float) $amount))->toBe((float) $ladder);
    }
});
