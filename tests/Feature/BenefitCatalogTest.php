<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Records\IncentiveTypesPage;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRunMemberIncentive;
use App\Models\Payslip;
use App\Models\PayslipIncentive;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Payroll\Application\ExemptBenefitLedger;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\Queries\ThirteenthMonthBasis;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * Incentives & Bonuses as a benefits catalog: a benefit's rule is saved
 * through the audited command and edited on the page; a scheduled benefit
 * is proposed on every draft payroll it is due on, for the members who
 * qualify, as an automatic line a typed amount replaces; the four tax
 * categories split as the rule says and only the ceiling's part spends the
 * yearly ceiling; a line flagged as basic pay reaches the 13th month; and
 * the evidence captures the rules, so a replay never reads the catalog.
 */
function bencatStatus(string $name): EmploymentStatusOption
{
    $status = new EmploymentStatusOption;
    $status->forceFill(['code' => (string) (EmploymentStatusOption::query()->count() + 1), 'name' => $name])->save();

    return $status;
}

/**
 * A catalog benefit with a rule.
 *
 * @param  array<string, mixed>  $changes
 */
function bencatBenefit(string $name, array $changes = []): PayrollIncentiveType
{
    $figures = array_replace([
        'amount_type' => BenefitRule::AMOUNT_FIXED, 'amount' => 2000.0, 'frequency' => BenefitRule::FREQUENCY_FIRST, 'month' => null,
        'statuses' => null, 'min_service_months' => 0, 'proration' => BenefitRule::PRORATION_NONE, 'tax' => BenefitRule::TAX_NOT_INCOME,
        'cap' => null, 'approval_reference' => null, 'counts_toward_13th' => false,
    ], $changes);
    app(SaveIncentiveType::class)->handle(app(Access::class), null, $name, null, $figures['tax'] === BenefitRule::TAX_TAXABLE, $figures);

    return PayrollIncentiveType::query()->where('name', $name)->sole();
}

function bencatPayrollActor(): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Benefit catalog '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::PAYROLL])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => null]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/*
|--------------------------------------------------------------------------
| The catalog
|--------------------------------------------------------------------------
*/

it('saves a benefit\'s rule through the page and lists it in words', function () {
    bencatPayrollActor();
    $regular = bencatStatus('Regular');
    bencatStatus('Contract of Service');

    Livewire::test(IncentiveTypesPage::class)
        ->call('openAdd')
        ->assertSet('formAmountType', BenefitRule::AMOUNT_TYPED)
        ->set('formName', 'PERA')
        ->set('formAmountType', BenefitRule::AMOUNT_FIXED)
        ->set('formAmount', '2000')
        ->set('formFrequency', BenefitRule::FREQUENCY_FIRST)
        ->set('formTax', BenefitRule::TAX_NOT_INCOME)
        ->set('formStatusMode', 'SOME')
        ->set("formStatuses.{$regular->id}", true)
        ->set('formMinServiceMonths', '4')
        ->set('formApprovalReference', 'Board resolution 12')
        ->assertSee('At ₱30,000 a month with every day paid: ₱2,000.00 when due.')
        ->call('save')
        ->assertSet('formError', null)
        ->assertSet('dialogOpen', false)
        ->assertSee('₱2,000.00')
        ->assertSee('Monthly · 1st cut-off')
        ->assertSee('Not income')
        ->assertSee('Regular · 4 months of service');

    $type = PayrollIncentiveType::query()->sole();
    expect($type->is_taxable)->toBeFalse()
        ->and($type->tax)->toBe(BenefitRule::TAX_NOT_INCOME)
        ->and($type->amount)->toBe(2000.0)
        ->and($type->frequency)->toBe(BenefitRule::FREQUENCY_FIRST)
        ->and($type->statuses)->toBe([$regular->id])
        ->and($type->min_service_months)->toBe(4)
        ->and($type->approval_reference)->toBe('Board resolution 12')
        ->and(PayrollData::benefitRuleOf($type)->isScheduled())->toBeTrue();

    // A percent with no figure, a de minimis item with no cap, and a ticked-nothing status set are refused.
    Livewire::test(IncentiveTypesPage::class)
        ->call('startEdit', $type->id)
        ->assertSet('formAmount', '2000')
        ->assertSet('formStatusMode', 'SOME')
        ->set('formAmountType', BenefitRule::AMOUNT_PERCENT)
        ->set('formAmount', '')
        ->call('save')
        ->assertSet('formError', 'Enter the percent of the monthly salary.')
        ->set('formAmount', '10')
        ->set('formTax', BenefitRule::TAX_DE_MINIMIS)
        ->set('formCap', '')
        ->call('save')
        ->assertSet('formError', 'Enter the cap up to which it is tax-free.')
        ->set('formCap', '1500')
        ->set("formStatuses.{$regular->id}", false)
        ->call('save')
        ->assertSet('formError', 'Tick at least one employment status, or choose all employees.')
        ->set('formStatusMode', 'ALL')
        ->call('save')
        ->assertSet('formError', null);
    $type->refresh();
    expect($type->amount_type)->toBe(BenefitRule::AMOUNT_PERCENT)
        ->and($type->amount)->toBe(10.0)
        ->and($type->tax)->toBe(BenefitRule::TAX_DE_MINIMIS)
        ->and($type->cap)->toBe(1500.0)
        ->and($type->statuses)->toBeNull();
});

it('keeps the flag and the category one fact on the model and refuses a rule the command cannot read', function () {
    bencatPayrollActor();
    $byFlag = PayrollIncentiveType::create(['code' => '1', 'name' => 'Old shape', 'is_taxable' => false]);
    expect($byFlag->fresh()?->tax)->toBe(BenefitRule::TAX_EXEMPT);
    $byFlag->forceFill(['tax' => BenefitRule::TAX_TAXABLE])->save();
    expect($byFlag->fresh()?->is_taxable)->toBeTrue();

    expect(fn () => app(SaveIncentiveType::class)->handle(app(Access::class), null, 'Broken', null, true, ['amount_type' => 'FIXED']))
        ->toThrow(RuntimeException::class, 'The benefit rule is incomplete');
    expect(PayrollIncentiveType::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Automatic lines on the draft
|--------------------------------------------------------------------------
*/

it('proposes a scheduled benefit on the cut-off it is due on, for the members who qualify, unless typed over', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $regular = bencatStatus('Regular');
    $contract = bencatStatus('Contract of Service');
    $pera = bencatBenefit('PERA');

    $first = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $line = $first['incentiveLines'][$employee->id][0];
    expect($line)->toMatchArray(['type_id' => $pera->id, 'code' => $pera->code, 'name' => 'PERA', 'amount' => 2000.0, 'tax' => 'NOT_INCOME', 'is_taxable' => false, 'automatic' => true, 'over_ceiling' => 0.0])
        ->and($first['drafts'][0]->exempt_benefits)->toBe(2000.0)
        ->and($first['drafts'][0]->ceiling_benefits)->toBe(0.0)
        ->and($first['drafts'][0]->bonus)->toBe(0.0)
        ->and($first['drafts'][0]->gross_pay)->toBe(4727.27)
        ->and($first['statutory'][$employee->id]['benefits'])->toBe(['ytd_before' => 0.0, 'exempt' => 2000.0, 'ceiling' => 0.0, 'taxable_excess' => 0.0])
        ->and($first['inputs']->benefitRules[$pera->id]['figures']['amount'])->toBe(2000.0);

    // The second cut-off is not its day; a typed amount replaces the proposal.
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $actor)['incentiveLines'])->toBe([]);
    $typed = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, draftIncentives: [$employee->id => [$pera->id => 500]]);
    expect($typed['incentiveLines'][$employee->id])->toHaveCount(1)
        ->and($typed['incentiveLines'][$employee->id][0])->toMatchArray(['amount' => 500.0, 'automatic' => false, 'tax' => 'NOT_INCOME']);

    // Who qualifies: the status, then the service.
    $pera->forceFill(['statuses' => [$contract->id]])->save();
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['incentiveLines'])->toBe([]);
    $pera->forceFill(['statuses' => [$regular->id], 'min_service_months' => 48])->save();
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['incentiveLines'])->toBe([]);
    $pera->forceFill(['min_service_months' => 32])->save();
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['incentiveLines'][$employee->id][0]['amount'])->toBe(2000.0);
});

it('prices each amount rule and proration from the member\'s rate, days and service', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $amounts = static function () use ($actor, $employee): array {
        $lines = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['incentiveLines'][$employee->id] ?? [];

        return array_column($lines, 'amount', 'name');
    };

    // Two days worked (one of them on business travel) over 11 scheduled
    // days, at 30,000 a month; hired in 2024, so eight months of the year
    // are served by the period's end.
    $daily = bencatBenefit('Meal allowance', ['amount_type' => BenefitRule::AMOUNT_DAILY, 'amount' => 150.0, 'frequency' => BenefitRule::FREQUENCY_EVERY]);
    $percent = bencatBenefit('Allowance', ['amount_type' => BenefitRule::AMOUNT_PERCENT, 'amount' => 10.0]);
    $month = bencatBenefit('Mid-year bonus', ['amount_type' => BenefitRule::AMOUNT_MONTH, 'amount' => null, 'frequency' => BenefitRule::FREQUENCY_YEARLY, 'month' => 9, 'proration' => BenefitRule::PRORATION_MONTHS, 'tax' => BenefitRule::TAX_EXEMPT]);
    $byDays = bencatBenefit('Rice subsidy', ['amount' => 1100.0, 'proration' => BenefitRule::PRORATION_DAYS]);
    expect($amounts())->toBe(['Meal allowance' => 300.0, 'Allowance' => 3000.0, 'Mid-year bonus' => 20000.0, 'Rice subsidy' => 200.0]);

    // Hired in April: five months of the year served by the period's end.
    EmployeeProfile::query()->whereKey($employee->id)->update(['date_hired' => '2026-04-01']);
    expect($amounts()['Mid-year bonus'])->toBe(12500.0);
    expect([$daily, $percent, $month, $byDays])->toHaveCount(4);
});

it('splits the four categories, spends the ceiling only with the ceiling\'s part and posts the copies', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    bencatBenefit('PERA', ['counts_toward_13th' => true]);
    bencatBenefit('Rice', ['amount' => 1500.0, 'tax' => BenefitRule::TAX_DE_MINIMIS, 'cap' => 1000.0]);
    bencatBenefit('Bonus', ['amount' => 100000.0, 'tax' => BenefitRule::TAX_EXEMPT]);
    bencatBenefit('Commission', ['amount' => 1000.0, 'tax' => BenefitRule::TAX_TAXABLE]);

    $calc = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $draft = $calc['drafts'][0];
    $byName = array_column($calc['incentiveLines'][$employee->id], null, 'name');
    // PERA whole, Rice 1,000 own cap + 500 under the ceiling, Bonus 89,500 under it, 10,500 over; Commission taxed.
    expect($draft->exempt_benefits)->toBe(93000.0)
        ->and($draft->ceiling_benefits)->toBe(90000.0)
        ->and($draft->taxable_benefits_excess)->toBe(10500.0)
        ->and($draft->bonus)->toBe(11500.0)
        ->and($byName['Rice']['over_ceiling'])->toBe(0.0)
        ->and($byName['Bonus']['over_ceiling'])->toBe(10500.0)
        ->and($byName['Commission']['over_ceiling'])->toBe(0.0)
        ->and($calc['statutory'][$employee->id]['benefits'])->toBe(['ytd_before' => 0.0, 'exempt' => 93000.0, 'ceiling' => 90000.0, 'taxable_excess' => 10500.0]);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');
    $posted = Payslip::query()->where('run_id', $run->id)->sole();
    expect($posted->exempt_benefits)->toBe(93000.0)
        ->and($posted->ceiling_benefits)->toBe(90000.0)
        ->and(PayslipIncentive::query()->where('payslip_id', $posted->id)->orderBy('position')->get()
            ->map(static fn (PayslipIncentive $l): array => [$l->name, $l->tax, $l->counts_toward_13th, $l->amount, $l->over_ceiling])->all())
        ->toBe([['PERA', 'NOT_INCOME', true, 2000.0, 0.0], ['Rice', 'DE_MINIMIS', false, 1500.0, 0.0], ['Bonus', 'EXEMPT', false, 100000.0, 10500.0], ['Commission', 'TAXABLE', false, 1000.0, 0.0]]);

    // The ledger counts the ceiling's part; the 13th month basis adds the flagged line.
    expect(app(ExemptBenefitLedger::class)->usedBefore($actor, [$employee->id], 2026))->toBe([$employee->id => 90000.0])
        ->and(app(ThirteenthMonthBasis::class)->forEmployees($actor, [$employee->id], 2026)[$employee->id]['basic_pay'])->toBe(4727.27);
    // A payslip posted before the ceiling was recorded apart counts its whole exempt figure.
    DB::table('payslips')->where('id', $posted->id)->update(['ceiling_benefits' => null]);
    expect(app(ExemptBenefitLedger::class)->usedBefore($actor, [$employee->id], 2026))->toBe([$employee->id => 93000.0]);
});

it('shows the proposal in the workspace dialog and saves a typed amount over it', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $pera = bencatBenefit('PERA');

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->call('openIncentives', $employee->id)
        ->assertSee('Proposed')
        ->assertSee('₱2,000.00')
        ->assertSee('Not income')
        ->assertSee('leave empty to pay it as proposed; it cannot be set to 0 here')
        // 0 is not an amount: a scheduled benefit is stopped in the catalog, never from the dialog.
        ->set("incentiveForm.{$pera->id}", '0')
        ->call('applyIncentives')
        ->assertSet('incentiveError', 'PERA is scheduled by the catalog and is paid as proposed; it cannot be set to 0 here. To stop it for everyone, change its rule in Incentives & Bonuses.')
        ->assertSet('incentivesOpen', true)
        ->set("incentiveForm.{$pera->id}", '750')
        ->assertSee('the typed amount replaces it')
        ->call('applyIncentives')
        ->call('save');
    expect(PayrollRunMemberIncentive::query()->where('type_id', $pera->id)->sole()->amount)->toBe(750.0)
        ->and($page->get('incentivesOpen'))->toBeFalse();
    expect($actor->userId)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Evidence
|--------------------------------------------------------------------------
*/

it('captures the rules in the evidence, stales the preview when the catalog changes and restores older shapes', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $pera = bencatBenefit('PERA');
    $typedType = PayrollIncentiveType::create(['code' => '9', 'name' => 'Christmas Bonus', 'is_taxable' => false]);

    $inputs = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, draftIncentives: [$employee->id => [$typedType->id => 500]])['inputs'];
    $data = $inputs->toArray();
    expect($data['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and(array_keys($data['benefit_rules']))->toBe([$pera->id])
        ->and($data['incentives'][$employee->id][0])->toMatchArray(['name' => 'Christmas Bonus', 'tax' => 'EXEMPT', 'counts_toward_13th' => false]);
    $restored = PayrollInputs::restore(PayrollInputs::json($data));
    expect($restored->fingerprint())->toBe($inputs->fingerprint())
        ->and(PayrollData::calculateInputs($restored, strict: true)['incentiveLines'][$employee->id])->toHaveCount(2);

    // A catalog edit changes the captured rules, so the fingerprint moves; a replay keeps what it captured.
    $pera->forceFill(['amount' => 2500.0])->save();
    $again = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor, draftIncentives: [$employee->id => [$typedType->id => 500]])['inputs'];
    expect($again->fingerprint())->not->toBe($inputs->fingerprint())
        ->and(PayrollData::calculateInputs($restored, strict: true)['incentiveLines'][$employee->id][0]['amount'])->toBe(2000.0);

    // The shape before the rules: lines with the flag alone, no rules.
    $eight = $data;
    unset($eight['benefit_rules'], $eight['deduction_rules'], $eight['recurring_lines'], $eight['skipped_recurring_lines'], $eight['attendance_through'], $eight['settlements'], $eight['leave_charges'], $eight['leave_cash_outs'], $eight['cash_out_type'], $eight['gov_policies'], $eight['adjustments']);
    $eight['schema_version'] = 8;
    $eight = PayrollEvidenceFixture::beforeSchema17($eight);
    foreach ($eight['incentives'] as $id => $lines) {
        $eight['incentives'][$id] = array_map(static fn (array $line): array => array_diff_key($line, ['tax' => true, 'counts_toward_13th' => true]), $lines);
    }
    $old = PayrollInputs::restore(PayrollInputs::json($eight));
    expect($old->incentives[$employee->id][0]['tax'])->toBe('EXEMPT')
        ->and($old->benefitRules)->toBe([])
        ->and(PayrollInputs::json($old->toArray()))->toBe(PayrollInputs::json($eight))
        ->and(PayrollData::calculateInputs($old, strict: true)['incentiveLines'][$employee->id])->toHaveCount(1);

    // A rule a replay cannot read, a line whose category disagrees with its flag, and an unscheduled rule are refused.
    $broken = $data;
    $broken['benefit_rules'][$pera->id]['figures']['tax'] = 'FREE';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($broken)))->toThrow(RuntimeException::class, 'Invalid payroll benefit rule evidence.');
    $mismatch = $data;
    $mismatch['incentives'][$employee->id][0]['tax'] = 'TAXABLE';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($mismatch)))->toThrow(RuntimeException::class, 'Invalid payroll incentive evidence.');
    $unscheduled = $data;
    $unscheduled['benefit_rules'][$pera->id]['figures']['frequency'] = 'ONCE';
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($unscheduled)))->toThrow(RuntimeException::class, 'Invalid payroll benefit rule evidence.');
});

it('applies the migration twice and turns the former flag into the category once', function () {
    $migration = require base_path('database/migrations/2026_10_12_0003_add_benefit_rules_to_payroll_incentive_types_table.php');
    DB::table('payroll_incentive_types')->insert([
        'id' => (string) Str::ulid(), 'code' => '901', 'name' => 'Exempt by flag', 'description' => null, 'is_taxable' => false,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ]);
    $migration->up();
    $migration->up();
    expect(DB::table('payroll_incentive_types')->where('code', '901')->value('tax'))->toBe('EXEMPT');
    DB::table('payroll_incentive_types')->where('code', '901')->update(['tax' => 'NOT_INCOME']);
    $migration->up();
    expect(DB::table('payroll_incentive_types')->where('code', '901')->value('tax'))->toBe('NOT_INCOME');
});
