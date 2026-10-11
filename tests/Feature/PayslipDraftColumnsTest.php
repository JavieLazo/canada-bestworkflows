<?php

declare(strict_types=1);

use App\Domain\Payroll\PayslipCalculator;
use App\Domain\Payroll\PayslipDraft;
use App\Models\Payslip;
use App\Modules\Payroll\Application\CalculatorVersion;
use Illuminate\Support\Facades\Schema;

/**
 * Every line a payslip draft carries reaches the posted payslip.
 * FinalizePayroll fills a `Payslip` from `PayslipDraft::toArray()`, and
 * Eloquent's fill() DROPS a key that is not fillable without a word — so a
 * draft field missing from `$fillable` would post 0 (or null) while the
 * finalization evidence recorded the real figure. For the PHP 90,000 ledger
 * that means undercounting every employee's exempt benefits forever. This
 * file fails the moment a draft key is not a `payslips` column, is not
 * fillable, or has no cast (a money column without `float` reads back as a
 * string).
 *
 * It also pins that the payroll calculation version covers the Payroll
 * module's own pure rules, so a change to the ceiling rule stales every
 * outstanding preview the same way an engine change does.
 */

/** A real draft from the engine: an employee with no attendance, so every figure is its zero. */
function pscolDraft(): PayslipDraft
{
    $employee = (object) [
        'id' => 'emp-pscol',
        'date_hired' => '2024-01-01',
        'date_resigned' => null,
        'monthly_rate' => 30000,
        'hourly_rate' => 0,
    ];

    return PayslipCalculator::compute($employee, [], null, [], '2026-08-01', '2026-08-15', 0.0);
}

it('stores every payslip draft key in a fillable, cast payslips column', function () {
    $payslip = new Payslip;
    $casts = $payslip->getCasts();
    $missing = [];

    foreach (array_keys(pscolDraft()->toArray()) as $key) {
        if (! Schema::hasColumn('payslips', $key)) {
            $missing[] = "{$key}: no payslips column";
        }
        if (! $payslip->isFillable($key)) {
            $missing[] = "{$key}: not fillable";
        }
        if ($key !== 'employee_id' && ! array_key_exists($key, $casts)) {
            $missing[] = "{$key}: no cast";
        }
    }

    expect($missing)->toBe([]);
});

it('casts the exempt-benefit columns to float and keeps the taxable base nullable', function () {
    $casts = (new Payslip)->getCasts();

    expect($casts['exempt_benefits'] ?? null)->toBe('float')
        ->and($casts['taxable_benefits_excess'] ?? null)->toBe('float')
        ->and($casts['taxable_compensation'] ?? null)->toBe('float');

    $draft = pscolDraft();
    expect($draft->exempt_benefits)->toBe(0.0)
        ->and($draft->taxable_benefits_excess)->toBe(0.0)
        ->and($draft->taxable_compensation)->toBeNull();

    // with() carries the three fields like every other: they are ordinary
    // constructor parameters, not side channels.
    $moved = $draft->with(['exempt_benefits' => 1_000.0, 'taxable_benefits_excess' => 250.0, 'taxable_compensation' => 12_000.0]);
    expect($moved->toArray())->toMatchArray(['exempt_benefits' => 1_000.0, 'taxable_benefits_excess' => 250.0, 'taxable_compensation' => 12_000.0])
        ->and($moved->net_pay)->toBe($draft->net_pay);
});

it('fingerprints the Payroll module\'s own rules along with the engines', function () {
    $files = array_map(static fn (string $path): string => str_replace(base_path().'/', '', $path), CalculatorVersion::files());

    expect($files)->toContain('app/Modules/Payroll/Domain/Benefits/ExemptBenefitCeiling.php')
        ->toContain('app/Modules/Payroll/Domain/Benefits/ExemptBenefitSplit.php')
        ->toContain('app/Domain/Payroll/PayslipCalculator.php')
        ->toContain('app/Modules/Payroll/Application/PayrollData.php')
        ->toContain('app/Modules/Payroll/Application/PayrollInputs.php');

    $sorted = $files;
    sort($sorted, SORT_STRING);
    expect($files)->toBe($sorted)
        ->and(CalculatorVersion::current())->toStartWith('payroll-v1:');
});
