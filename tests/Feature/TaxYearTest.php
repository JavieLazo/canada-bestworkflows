<?php

declare(strict_types=1);

use App\Livewire\Records\Support\PaySettingsText;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\GovDeductionInput;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Payroll\Domain\Tax\AnnualWithholding;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * DELIBERATE DIVERGENCE 20, the tax year: a member whose Contributions &
 * tax version picks the whole-year estimate is withheld the year's
 * remaining tax spread over the payrolls left, from the year's posted
 * payslips and approved balances captured in the evidence (never the
 * live tables); the year's last payroll settles the exact remainder and
 * refunds an excess into the net pay; the payslip records its tax year
 * and method; a balance is a DRAFT until it is approved.
 * Every other member keeps the reference's methods and evidence.
 */

/** @param list<string> $modules */
function tyrActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null, string $name = 'Pay Officer'): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Tax '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['name' => $name, 'role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function tyrActAs(Access $actor): Access
{
    test()->actingAs(User::query()->findOrFail((int) $actor->userId));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/*
|--------------------------------------------------------------------------
| The settings
|--------------------------------------------------------------------------
*/

it('offers the whole-year method as one of the one-way methods, round-trips the spread and words it', function () {
    expect(fn () => GovDeductionSettings::fromArray([...GovDeductionSettings::STANDARD, 'whole_year_tax' => true, 'annualize_tax' => true]))->toThrow(InvalidArgumentException::class, 'one way')
        ->and(fn () => GovDeductionSettings::fromArray([...GovDeductionSettings::STANDARD, 'tax_spread' => 'SOMETIME']))->toThrow(InvalidArgumentException::class, 'spread');
    // Figures from before the keys read as the reference's methods.
    $older = GovDeductionSettings::fromArray(array_diff_key(GovDeductionSettings::STANDARD, ['whole_year_tax' => true, 'tax_spread' => true]));
    expect($older->get('whole_year_tax'))->toBeFalse()->and($older->get('tax_spread'))->toBe(AnnualWithholding::SPREAD_EQUAL)->and($older->customized())->toBe([]);

    $settings = GovDeductionSettings::fromArray([...GovDeductionSettings::STANDARD, 'whole_year_tax' => true, 'tax_spread' => AnnualWithholding::SPREAD_DECEMBER]);
    $form = GovDeductionInput::toForm($settings);
    expect($form['tax_method'])->toBe(GovDeductionInput::METHOD_WHOLE_YEAR)->and($form['tax_spread'])->toBe('DECEMBER')
        ->and(PaySettingsText::withholding($settings))->toBe("Projected annual tax with year-end adjustment, all on the year's last payroll")
        ->and($settings->customized())->toBe(['whole_year_tax', 'tax_spread']);
    $figures = GovDeductionInput::figures([...$form, 'tax_spread' => 'NEXT']);
    if (! is_array($figures)) {
        throw new RuntimeException($figures);
    }
    expect($figures['whole_year_tax'])->toBeTrue()->and($figures['tax_spread'])->toBe('NEXT')->and($figures['annualize_tax'])->toBeFalse();
    expect(GovDeductionInput::figures([...$form, 'tax_spread' => 'NEVER']))->toBe('Choose how the remaining tax is spread.');
});

/*
|--------------------------------------------------------------------------
| The payroll
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| The balances
|--------------------------------------------------------------------------
*/
