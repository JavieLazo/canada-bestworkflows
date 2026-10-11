<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Records\ReferenceListPage;
use App\Models\PayrollDeduction;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Catalogs\Deductions;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Modules\Payroll\Domain\Deductions\PercentDeduction;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * Percentage deductions: a Payroll Deductions row may carry a rule — an
 * employee share, an employer share, a base, a frequency, a cap and
 * whether it comes before tax — saved through the catalog and edited on
 * the Payroll Deductions page; payroll prices the shares on every draft,
 * reports the employer's, takes the before-tax part off the withholding
 * base, lets a typed amount override the employee share, captures the
 * rules in the evidence and posts the employer share with the line.
 */
function pctdRecordsActor(): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Percent deductions '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::RECORDS])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => null]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** @param array<string, mixed> $changes */
function pctdRow(string $code, string $name, array $changes = []): PayrollDeduction
{
    $row = new PayrollDeduction;
    $row->forceFill(['code' => $code, 'name' => $name, 'percent' => array_replace([
        'employee' => 3.0, 'employer' => 3.0, 'base' => PercentDeduction::BASE_MONTHLY,
        'frequency' => PercentDeduction::FREQUENCY_EVERY, 'cap' => null, 'before_tax' => false,
    ], $changes)])->save();

    return $row;
}

/*
|--------------------------------------------------------------------------
| The list
|--------------------------------------------------------------------------
*/

it('saves a percentage rule from the Payroll Deductions page and names each row\'s computation', function () {
    pctdRecordsActor();
    // The computed rows are seeded by the migration; the typed one is this test's.
    $sss = PayrollDeduction::query()->where('calculation', DeductionCalculation::SSS)->firstOrFail();
    $typed = new PayrollDeduction;
    $typed->forceFill(['code' => '6', 'name' => 'Cash Advance'])->save();

    Livewire::test(ReferenceListPage::class, ['slug' => 'payroll-deductions'])
        ->assertSee('Computation')
        ->assertSee('SSS table')
        ->assertSee('Typed per employee')
        ->call('openAdd')
        ->assertSet('formComputation', 'TYPED')
        ->set('formName', 'Provident Fund')
        ->set('formComputation', 'PERCENT')
        ->set('formPercentEmployee', '3')
        ->set('formPercentEmployer', '3')
        ->set('formFrequency', PercentDeduction::FREQUENCY_EVERY)
        ->assertSee('At ₱30,000 a month: employee ₱450.00, employer ₱450.00 each payroll (half of the monthly figure).')
        ->set('formBeforeTax', true)
        ->call('save')
        ->assertSet('formError', null)
        ->assertSet('dialogOpen', false)
        ->assertSee('3% of monthly rate + 3% employer');

    $row = PayrollDeduction::query()->where('name', 'Provident Fund')->sole();
    expect($row->percent)->toEqual(['employee' => 3.0, 'employer' => 3.0, 'base' => 'MONTHLY', 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => true])
        ->and($row->calculation)->toBeNull();

    // Edited back to typed, and refused on a computed row and with unreadable shares.
    Livewire::test(ReferenceListPage::class, ['slug' => 'payroll-deductions'])
        ->call('startEdit', $row->id)
        ->assertSet('formComputation', 'PERCENT')
        ->assertSet('formPercentEmployee', '3')
        ->set('formPercentEmployee', 'lots')
        ->call('save')
        ->assertSet('ruleError', 'Enter the employee and employer shares as percents, 0 for none.')
        ->set('formPercentEmployee', '0')
        ->set('formPercentEmployer', '0')
        ->assertSet('ruleError', null)
        ->assertSee('Enter an employee share, an employer share or both.')
        ->call('save')
        ->assertSet('ruleError', 'Enter an employee share, an employer share or both.')
        ->set('formComputation', 'TYPED')
        ->call('save')
        ->assertSet('formError', null);
    expect($row->fresh()?->percent)->toBeNull();
    expect(fn () => app(Deductions::class)->save(app(Access::class), $sss->id, 'SSS', '', ['percent' => ['employee' => 1.0, 'employer' => 0.0, 'base' => 'MONTHLY', 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => false]]))
        ->toThrow(RuntimeException::class, 'A computed deduction prices itself');
});

/*
|--------------------------------------------------------------------------
| The draft
|--------------------------------------------------------------------------
*/

it('shows a viewer the list and the rule details without any way to change them', function () {
    $role = new Role;
    $role->forceFill(['name' => 'Percent viewer '.Str::random(6), 'all_data' => false, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::RECORDS])->save();
    test()->actingAs(User::factory()->create(['role_id' => $role->id, 'employee_id' => null]));
    app()->forgetInstance(Access::class);
    pctdRow('901', 'Provident Fund', ['frequency' => PercentDeduction::FREQUENCY_SECOND, 'cap' => 1500.0, 'before_tax' => true]);

    Livewire::test(ReferenceListPage::class, ['slug' => 'payroll-deductions'])
        ->assertSee('Your role can view this list.')
        ->assertSee('3% of monthly rate + 3% employer')
        ->assertSee('2nd cut-off only · cap ₱1,500.00 a month per share · before tax')
        ->assertDontSee('wire:click="openAdd"', false)
        ->assertDontSee('>Action<', false);
});

it('prices the shares on every draft, halving a monthly base, honouring the cut-off, the cap and a typed override', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $fund = pctdRow('8', 'Provident Fund');
    $union = pctdRow('9', 'Union dues', ['employee' => 1.0, 'employer' => 0.0, 'frequency' => PercentDeduction::FREQUENCY_SECOND, 'cap' => 200.0]);
    $onGross = pctdRow('10', 'Welfare', ['employee' => 10.0, 'employer' => 0.0, 'base' => PercentDeduction::BASE_GROSS]);

    $first = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $lines = array_column($first['customDeductionLines'][$employee->id], null, 'name');
    // 3% of 30,000 halved; Welfare on this payslip's 2,727.27 gross; the second-cut-off row not yet.
    expect(array_keys($lines))->toBe(['Provident Fund', 'Welfare'])
        ->and($lines['Provident Fund'])->toMatchArray(['type_id' => $fund->id, 'code' => '8', 'amount' => 450.0, 'employer_amount' => 450.0, 'automatic' => true])
        ->and($lines['Welfare'])->toMatchArray(['amount' => 272.73, 'employer_amount' => 0.0, 'automatic' => true])
        ->and($first['drafts'][0]->custom_deductions)->toBe(722.73)
        ->and($first['inputs']->deductionRules[$fund->id]['figures']['employee'])->toBe(3.0)
        ->and(array_keys($first['inputs']->deductionRules))->toBe([$fund->id, $union->id, $onGross->id]);

    $second = PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor);
    $lines = array_column($second['customDeductionLines'][$employee->id], null, 'name');
    // Union dues: 1% of 30,000 is 300, capped at 200 for the month.
    expect($lines['Union dues'])->toMatchArray(['amount' => 200.0, 'employer_amount' => 0.0, 'automatic' => true]);

    // A typed amount on the row is the employee share; the employer's stays the rule's.
    $typed = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, draftCustomDeductions: [$employee->id => [$fund->id => 100]]);
    $lines = array_column($typed['customDeductionLines'][$employee->id], null, 'name');
    expect($lines['Provident Fund'])->toMatchArray(['amount' => 100.0, 'employer_amount' => 450.0, 'automatic' => false])
        ->and($typed['drafts'][0]->custom_deductions)->toBe(372.73);
});

it('takes a before-tax share off the withholding base and posts the employer share with the line', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $before = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['drafts'][0];
    $fund = pctdRow('8', 'Provident Fund', ['before_tax' => true]);

    $calc = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $draft = $calc['drafts'][0];
    expect($draft->taxable_compensation)->toBe(round($before->taxable_compensation - 450.0, 2))
        ->and($calc['statutory'][$employee->id]['withholding_rederived'])->toBeTrue()
        ->and($draft->withholding_tax)->toBeLessThanOrEqual($before->withholding_tax)
        ->and($draft->net_pay)->toBe(round($before->net_pay - 450.0 + ($before->withholding_tax - $draft->withholding_tax), 2));

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSee('3% of monthly rate + 3% employer')
        ->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');
    $posted = Payslip::query()->where('run_id', $run->id)->sole();
    $line = PayslipDeduction::query()->where('payslip_id', $posted->id)->sole();
    expect([$line->deduction_code, $line->name, $line->amount, $line->employer_amount])->toBe(['8', 'Provident Fund', 450.0, 450.0])
        ->and($posted->custom_deductions)->toBe(450.0);
    expect($fund->exists)->toBeTrue();
});

it('captures the rules in the evidence, stales the preview when a rule changes and restores the older shape', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $fund = pctdRow('8', 'Provident Fund');

    $inputs = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['inputs'];
    $data = $inputs->toArray();
    expect(array_keys($data['deduction_rules']))->toBe([$fund->id]);
    $restored = PayrollInputs::restore(PayrollInputs::json($data));
    expect($restored->fingerprint())->toBe($inputs->fingerprint());

    $fund->forceFill(['percent' => [...$fund->percent, 'employee' => 4.0]])->save();
    $again = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['inputs'];
    expect($again->fingerprint())->not->toBe($inputs->fingerprint())
        // The replay prices what it captured, never the list as it stands.
        ->and(PayrollData::calculateInputs($restored, strict: true)['customDeductionLines'][$employee->id][0]['amount'])->toBe(450.0);

    $eight = $data;
    unset($eight['benefit_rules'], $eight['deduction_rules']);
    $eight['schema_version'] = 8;
    $old = PayrollInputs::restore(PayrollInputs::json($eight));
    expect($old->deductionRules)->toBe([])
        ->and(PayrollData::calculateInputs($old, strict: true)['customDeductionLines'])->toBe([]);

    $broken = $data;
    $broken['deduction_rules'][$fund->id]['figures']['base'] = 'NET';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($broken)))->toThrow(RuntimeException::class, 'Invalid payroll deduction rule evidence.');
});

it('applies the migration twice', function () {
    $migration = require base_path('database/migrations/2026_10_12_0004_add_percent_to_payroll_deductions_table.php');
    $migration->up();
    $migration->up();
    expect(PayrollData::percentDeductionsReady())->toBeTrue();
});
