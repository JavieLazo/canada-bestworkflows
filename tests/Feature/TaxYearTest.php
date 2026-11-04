<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Livewire\Records\Support\PaySettingsText;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollTaxBalance;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\ApproveGovDeductionPolicy;
use App\Modules\Payroll\Application\Commands\ApproveTaxBalance;
use App\Modules\Payroll\Application\Commands\DiscardTaxBalance;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SaveGovDeductionPolicy;
use App\Modules\Payroll\Application\Commands\SaveTaxBalance;
use App\Modules\Payroll\Application\GovDeductionInput;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Payroll\Domain\Tax\AnnualWithholding;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

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
        ->and(PaySettingsText::withholding($settings))->toBe("Whole-year estimate, all on the year's last payroll")
        ->and($settings->customized())->toBe(['whole_year_tax', 'tax_spread']);
    $figures = GovDeductionInput::figures([...$form, 'tax_spread' => 'NEXT']);
    if (! is_array($figures)) {
        throw new RuntimeException($figures);
    }
    expect($figures['whole_year_tax'])->toBeTrue()->and($figures['tax_spread'])->toBe('NEXT')->and($figures['annualize_tax'])->toBeFalse();
    expect(GovDeductionInput::figures([...$form, 'tax_spread' => 'NEVER']))->toBe('Choose how the whole-year estimate is spread.');
});

/*
|--------------------------------------------------------------------------
| The payroll
|--------------------------------------------------------------------------
*/

it('withholds the year\'s remaining tax over the payrolls left from captured facts, records the method, replays, and refunds on the last payroll', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    // The reference path first: no year facts, the table's method.
    $reference = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($reference['inputs']->taxYear)->toBeNull()
        ->and($reference['drafts'][0]->tax_method)->toBe('TABLE')
        ->and($reference['drafts'][0]->tax_refund)->toBe(0.0);

    $preparer = tyrActor(name: 'Preparer');
    $policy = app(SaveGovDeductionPolicy::class)->handle($preparer, $employee->id, '2026-09-01', 'Whole year', [...GovDeductionSettings::STANDARD, 'whole_year_tax' => true]);
    app(ApproveGovDeductionPolicy::class)->handle(tyrActor(name: 'Approver'), $policy);
    // An opening balance pushes the year into the taxed brackets; only once approved.
    $balance = PayrollTaxBalance::query()->where('employee_id', $employee->id)->first();
    expect($balance)->toBeNull();
    app(SaveTaxBalance::class)->handle($preparer, $employee->id, 2026, PayrollTaxBalance::KIND_OPENING, '400,000', '20,000', 'Posted elsewhere before go-live');
    $balance = PayrollTaxBalance::query()->where('employee_id', $employee->id)->sole();
    $actor = tyrActAs($actor);
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['inputs']->taxYear['posted'])->toBe([]);
    app(ApproveTaxBalance::class)->handle(tyrActor(name: 'Approver'), $balance->id);
    $actor = tyrActAs($actor);

    $calc = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, runId: $run->id);
    $draft = $calc['drafts'][0];
    $taxable = (float) $draft->taxable_compensation;
    // Seven regular payrolls are left in 2026 after 1–15 September on the standard schedule.
    expect($calc['inputs']->taxYear)->toBe(['year' => 2026, 'payrolls_left' => 7, 'posted' => [$employee->id => ['taxable' => 400000.0, 'withheld' => 20000.0]]])
        ->and($taxable)->toBeGreaterThan(0.0);
    $expected = AnnualWithholding::compute(400000.0, $taxable, 7, 20000.0, AnnualWithholding::SPREAD_EQUAL, $calc['inputs']->schedule());
    expect($draft->withholding_tax)->toBe($expected['withholding'])->toBeGreaterThan(0.0)
        ->and($draft->tax_method)->toBe('WHOLE_YEAR')
        ->and($draft->tax_refund)->toBe(0.0)
        ->and($draft->net_pay)->toBe(round($draft->gross_pay - $draft->total_deductions, 2))
        ->and($calc['statutory'][$employee->id]['withholding_rederived'])->toBeTrue();

    // The evidence replays the captured year, never the table; older evidence carries no year.
    $restored = PayrollInputs::restore(PayrollInputs::json($calc['inputs']->toArray()));
    expect($restored->fingerprint())->toBe($calc['inputs']->fingerprint());
    $balance->forceFill(['taxable_income' => 900000.0])->save();
    expect(PayrollData::calculateInputs($restored, strict: true)['drafts'][0]->withholding_tax)->toBe($expected['withholding'])
        ->and(PayrollInputs::restore(PayrollInputs::json(PayrollEvidenceFixture::asSchema(['schema_version' => 21] + $calc['inputs']->toArray(), 21)))->taxYear)->toBeNull();
    $balance->forceFill(['taxable_income' => 400000.0])->save();

    // Posting writes the year and the method, and the next payroll reads what was posted.
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    $posted = Payslip::query()->where('employee_id', $employee->id)->sole();
    expect($posted->tax_year)->toBe(2026)->and($posted->tax_method)->toBe('WHOLE_YEAR')->and($posted->tax_refund)->toBe(0.0)
        ->and(TaxYearToDate::posted([$employee->id], 2026)[$employee->id])->toBe(['taxable' => round(400000.0 + $taxable, 2), 'withheld' => round(20000.0 + $expected['withholding'], 2)])
        ->and(TaxYearToDate::posted([$employee->id], 2027))->toBe([]);
    $second = PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor);
    expect($second['inputs']->taxYear['payrolls_left'])->toBe(6)
        ->and($second['inputs']->taxYear['posted'][$employee->id]['withheld'])->toBe(round(20000.0 + $expected['withholding'], 2));

    // The year's last payroll: too much withheld becomes a refund in the net pay.
    app(SaveTaxBalance::class)->handle($preparer, $employee->id, 2026, PayrollTaxBalance::KIND_PREVIOUS_EMPLOYER, '0', '60,000', 'Certificate from the previous employer');
    $extra = PayrollTaxBalance::query()->where('kind', PayrollTaxBalance::KIND_PREVIOUS_EMPLOYER)->sole();
    app(ApproveTaxBalance::class)->handle(tyrActor(name: 'Approver'), $extra->id);
    $actor = tyrActAs($actor);
    $last = PayrollData::calculateDrafts([$employee->id], '2026-12-16', '2026-12-31', actor: $actor);
    $lastDraft = $last['drafts'][0];
    $settled = AnnualWithholding::compute(round(400000.0 + $taxable, 2), (float) $lastDraft->taxable_compensation, 0, round(80000.0 + $expected['withholding'], 2), AnnualWithholding::SPREAD_EQUAL, $last['inputs']->schedule());
    expect($last['inputs']->taxYear['payrolls_left'])->toBe(0)
        ->and($lastDraft->withholding_tax)->toBe(0.0)
        ->and($lastDraft->tax_refund)->toBe($settled['refund'])->toBeGreaterThan(0.0)
        ->and($lastDraft->net_pay)->toBe(round($lastDraft->gross_pay - $lastDraft->total_deductions + $settled['refund'], 2));
    // A typed withholding still wins over the estimate.
    $typed = PayrollData::calculateDrafts([$employee->id], '2026-12-16', '2026-12-31', actor: $actor, draftDeductions: [$employee->id => ['withholding_tax' => 123]]);
    expect($typed['drafts'][0]->withholding_tax)->toBe(123.0)->and($typed['drafts'][0]->tax_refund)->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| The balances
|--------------------------------------------------------------------------
*/

it('records, approves and discards tax balances on the pay page, refuses a viewer, approves through the own account, and shows the year', function () {
    $preparer = tyrActor(name: 'Preparer');
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'TAX-1', 'first_name' => 'Tina', 'last_name' => 'Year', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true])->save();
    $save = app(SaveTaxBalance::class);
    expect(fn () => $save->handle($preparer, $employee->id, 'soon', 'OPENING', '1', '0', 'Bad year'))->toThrow(RuntimeException::class, 'four-digit year')
        ->and(fn () => $save->handle($preparer, $employee->id, 2026, 'BONUS', '1', '0', 'Bad kind'))->toThrow(RuntimeException::class, 'opening balance')
        ->and(fn () => $save->handle($preparer, $employee->id, 2026, 'OPENING', '-1', '0', 'Negative'))->toThrow(RuntimeException::class, 'taxable income')
        ->and(fn () => $save->handle($preparer, $employee->id, 2026, 'OPENING', '1', '0', ''))->toThrow(RuntimeException::class, 'reason');

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSeeHtml('data-tax-year')->assertSee('Tax year '.substr(ManilaClock::todayKey(), 0, 4))
        ->call('openBalance')->assertSet('balanceOpen', true)
        ->set('balanceForm.tax_year', '2026')->set('balanceForm.taxable_income', '100,000')->set('balanceForm.tax_withheld', '5,000')->set('balanceForm.reason', 'Go-live')
        ->call('saveBalance')->assertSet('balanceOpen', false)->assertSet('balanceError', null)
        ->assertSee('Awaiting approval')->assertSee('Opening balance');
    $row = PayrollTaxBalance::query()->sole();
    expect($row->status)->toBe('DRAFT')->and($row->taxable_income)->toBe(100000.0)->and($row->prepared_by)->toBe((int) $preparer->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::TAX_BALANCE_RECORDED->value)->where('entity_id', $row->id)->exists())->toBeTrue();

    // A viewer without all data changes nothing.
    tyrActor(['PAYROLL', 'RECORDS'], allData: false, name: 'Viewer');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->set('balanceForm', ['tax_year' => '2026', 'kind' => 'OPENING', 'taxable_income' => '1', 'tax_withheld' => '0', 'reason' => 'No', 'reference' => ''])
        ->call('saveBalance')->call('approveBalance', $row->id)->call('discardBalance', $row->id);
    expect(PayrollTaxBalance::query()->count())->toBe(1)->and($row->refresh()->status)->toBe('DRAFT');

    // The employee's own all-data account approves: one administrator runs the whole flow.
    $approver = tyrActor(employeeId: $employee->id, name: 'Tina Year');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])->call('approveBalance', $row->id)->assertSee('Approved by');
    expect($row->refresh()->status)->toBe('APPROVED')->and($row->approved_by)->toBe((int) $approver->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::TAX_BALANCE_APPROVED->value)->where('entity_id', $row->id)->exists())->toBeTrue()
        ->and(fn () => app(DiscardTaxBalance::class)->handle($approver, $row->id))->toThrow(RuntimeException::class, 'not discarded')
        ->and(TaxYearToDate::posted([$employee->id], 2026)[$employee->id])->toBe(['taxable' => 100000.0, 'withheld' => 5000.0]);

    $save->handle($approver, $employee->id, 2026, PayrollTaxBalance::KIND_PREVIOUS_EMPLOYER, '50,000', '2,000', 'Certificate', 'Form from the previous employer');
    $draft = PayrollTaxBalance::query()->where('status', 'DRAFT')->sole();
    tyrActor(name: 'Second approver');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])->call('discardBalance', $draft->id)->assertDontSee('Previous employer');
    expect(PayrollTaxBalance::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditEvent::TAX_BALANCE_DISCARDED->value)->where('entity_id', $draft->id)->exists())->toBeTrue();
});
