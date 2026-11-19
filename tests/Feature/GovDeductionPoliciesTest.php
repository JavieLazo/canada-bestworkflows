<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Livewire\Records\EmployeePaySettingsPage;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollGovDeductionPolicy;
use App\Models\PayrollPayRule;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\ApproveGovDeductionPolicy;
use App\Modules\Payroll\Application\Commands\DiscardGovDeductionPolicy;
use App\Modules\Payroll\Application\Commands\SaveGovDeductionPolicy;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\Queries\GovDeductionPolicyRecords;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PayrollEvidenceFixture;

/**
 * Each employee's Contributions & tax policy as dated, approved versions
 * (2026_11_02_0003): prepared as a draft by an all-data PAYROLL holder,
 * approved (by the preparer or the employee included) and in force from
 * its date; an employee with no approved version keeps the record's
 * baseline columns. Payroll lays the version in force on the period's
 * first day over the employee, records it in the evidence (schema 15),
 * and prices a percentage deduction at the employee's own rate. A draft
 * never prices anyone.
 */

/** @param list<string> $modules */
function gdpActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Gov policy '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides employee_profiles columns over a 30,000-a-month regular employee */
function gdpEmployee(string $lastName, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'GDP-'.Str::upper(Str::random(5)), 'first_name' => 'Pat', 'last_name' => $lastName,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true,
    ], $overrides))->save();

    return $employee->refresh();
}

/**
 * A version's figures: the standard computation with some keys changed.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function gdpFigures(array $changes = []): array
{
    return [...GovDeductionSettings::STANDARD, ...$changes];
}

/** A Provident Fund row: 3% employee + 10% employer of the monthly rate, every payroll. */
function gdpFund(): PayrollDeduction
{
    $row = new PayrollDeduction;
    $row->forceFill(['code' => '901', 'name' => 'Provident Fund', 'calculation' => null,
        'percent' => ['employee' => 3.0, 'employer' => 10.0, 'base' => 'MONTHLY', 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => false]])->save();

    return $row;
}

/** A finalized regular payslip for the employee, so the period is posted. */
function gdpPosted(EmployeeProfile $employee, string $start, string $end): void
{
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-'.Str::upper(Str::random(4)), 'name' => 'Posted', 'period_start' => $start, 'period_end' => $end,
        'status' => PayrollRunStatus::FINALIZED, 'kind' => PayrollRunKind::REGULAR])->save();
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => $employee->id, 'period_start' => $start, 'period_end' => $end,
        'daily_rate' => 1000, 'work_days' => 10, 'work_days_pay' => 10000, 'basic_pay' => 10000, 'total_base_pay' => 10000,
        'gross_pay' => 10000, 'sss' => 0, 'philhealth' => 0, 'pagibig' => 0, 'withholding_tax' => 0, 'total_deductions' => 0, 'net_pay' => 10000])->save();
}

/* |---| The migrations |---| */

it('adds the approval columns to the pay rules, backfilling every row approved, and creates the policy table, resuming from a partial run', function () {
    $employee = gdpEmployee('Migrated');
    $row = new PayrollPayRule;
    $row->forceFill(['scope' => $employee->id, 'scope_type' => PayrollPayRule::SCOPE_EMPLOYEE, 'effective_from' => '2027-01-01', 'reason' => 'Synthetic', 'params' => null, 'status' => PayrollPayRule::APPROVED, 'approved_at' => now('UTC')])->save();
    $draft = new PayrollPayRule;
    $draft->forceFill(['scope' => $employee->id, 'scope_type' => PayrollPayRule::SCOPE_EMPLOYEE, 'effective_from' => '2027-02-01', 'reason' => 'Synthetic', 'params' => null, 'status' => PayrollPayRule::DRAFT])->save();

    $approval = require base_path('database/migrations/2026_11_02_0002_add_approval_to_payroll_pay_rules_table.php');
    $approval->down();
    expect(Schema::hasColumn('payroll_pay_rules', 'status'))->toBeFalse()
        ->and(PayrollPayRule::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(PayrollPayRule::query()->whereKey($row->id)->exists())->toBeTrue();
    $approval->up();
    $approval->up();
    expect(Schema::hasColumns('payroll_pay_rules', ['status', 'approved_by', 'approved_by_name', 'approved_at']))->toBeTrue()
        ->and(Schema::hasIndex('payroll_pay_rules', 'ppr_status_idx'))->toBeTrue();
    $migrated = PayrollPayRule::query()->whereKey($row->id)->sole();
    expect($migrated->status)->toBe(PayrollPayRule::APPROVED)
        ->and($migrated->approved_at?->equalTo($migrated->created_at))->toBeTrue();

    $policies = require base_path('database/migrations/2026_11_02_0003_create_payroll_gov_deduction_policies_table.php');
    $policies->down();
    expect(Schema::hasTable('payroll_gov_deduction_policies'))->toBeFalse();
    $policies->up();
    $policies->up();
    expect(Schema::hasTable('payroll_gov_deduction_policies'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_gov_deduction_policies', 'pgdp_employee_from_unq'))->toBeTrue()
        ->and(Schema::hasColumns('payroll_gov_deduction_policies', ['employee_id', 'effective_from', 'params', 'reason', 'status', 'prepared_by', 'approved_by', 'approved_at']))->toBeTrue();
});

/* |---| The commands |---| */

it('prepares a draft, approves it from a second account of the preparer, and discards a draft, audited', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    $employee = gdpEmployee('Versioned');
    $fund = gdpFund();
    $preparer = gdpActor();
    $save = app(SaveGovDeductionPolicy::class);
    $figures = gdpFigures(['allow_sss' => false, 'fixed_pagibig_ee' => 400.0, 'percent_rates' => [$fund->id => 5.0]]);

    // Refusals before anything is written.
    expect(fn () => $save->handle($preparer, $employee->id, '2026-11-05', 'Mid', $figures))->toThrow(RuntimeException::class, 'A version takes effect on the 1st or the 16th of a month')
        ->and(fn () => $save->handle($preparer, $employee->id, '2026-11-01', ' ', $figures))->toThrow(RuntimeException::class, 'Enter the reason for this version.')
        ->and(fn () => $save->handle($preparer, $employee->id, '2026-11-01', 'Bad', ['allow_sss' => false]))->toThrow(RuntimeException::class, 'The contribution settings are incomplete')
        ->and(fn () => $save->handle($preparer, $employee->id, '2026-11-01', 'Bad', gdpFigures(['fixed_tax' => 100.0, 'percent_tax' => 5.0])))->toThrow(RuntimeException::class, 'Withholding tax is worked out one way')
        ->and(fn () => $save->handle($preparer, $employee->id, '2026-11-01', 'Bad', gdpFigures(['percent_rates' => [(string) Str::ulid() => 5.0]])))->toThrow(RuntimeException::class, 'A rate names a percentage deduction that does not exist.')
        ->and(fn () => $save->handle($preparer, (string) Str::ulid(), '2026-11-01', 'Nobody', $figures))->toThrow(RuntimeException::class, 'That employee no longer exists.')
        ->and(PayrollGovDeductionPolicy::query()->count())->toBe(0);

    $id = $save->handle($preparer, $employee->id, '2026-11-01', 'Retiree, SSS exempt; Pag-IBIG by agreement', $figures);
    $draft = PayrollGovDeductionPolicy::query()->whereKey($id)->sole();
    expect($draft->status)->toBe(PayrollGovDeductionPolicy::DRAFT)
        ->and($draft->params['allow_sss'])->toBeFalse()
        ->and($draft->params['percent_rates'])->toEqual([$fund->id => 5.0])
        ->and($draft->prepared_by)->toBe((int) $preparer->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::GOV_POLICY_SAVED->value)->sole()->details)
        ->toBe('Pat Versioned · Contributions & tax draft from 01 Nov 2026: Deduct SSS, Fixed Pag-IBIG (employee), Own rates on percentage deductions (Retiree, SSS exempt; Pag-IBIG by agreement)');

    // The same date again edits the draft in place; a draft never prices anyone.
    expect($save->handle($preparer, $employee->id, '2026-11-01', 'Corrected', gdpFigures(['allow_sss' => false])))->toBe($id)
        ->and(PayrollGovDeductionPolicy::query()->count())->toBe(1)
        ->and($draft->refresh()->reason)->toBe('Corrected')
        ->and(GovDeductionPolicyStore::forEmployees([$employee->id], '2026-11-01'))->toBe([]);

    // A second account of the preparer's own person approves: one administrator runs the whole flow.
    $approve = app(ApproveGovDeductionPolicy::class);
    $preparerUser = User::query()->findOrFail($preparer->userId);
    $preparerUser->forceFill(['employee_id' => gdpEmployee('Preparer')->id])->save();
    $twin = User::factory()->create(['role_id' => $preparerUser->role_id, 'employee_id' => $preparerUser->employee_id]);
    $approver = Access::forActor($twin);
    expect($approve->handle($approver, $id))->toBe('Contributions & tax version from 01 Nov 2026 approved for Pat Versioned.');
    $approved = $draft->refresh();
    expect($approved->status)->toBe(PayrollGovDeductionPolicy::APPROVED)
        ->and($approved->approved_by)->toBe((int) $approver->userId)
        ->and($approved->approved_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditEvent::GOV_POLICY_APPROVED->value)->sole()->details)->toContain('version from 01 Nov 2026 approved (prepared by')
        ->and(fn () => $approve->handle($approver, $id))->toThrow(RuntimeException::class, 'That version is already approved.')
        ->and(GovDeductionPolicyStore::forEmployees([$employee->id], '2026-11-01')[$employee->id]->id)->toBe($id)
        ->and(GovDeductionPolicyStore::forEmployees([$employee->id], '2026-10-16'))->toBe([]);

    // An approved version never changes: a draft on or before its date is refused; a later one is fine.
    expect(fn () => $save->handle($approver, $employee->id, '2026-11-01', 'Again', $figures))->toThrow(RuntimeException::class, 'An approved version already takes effect on 01 Nov 2026 and never changes.')
        ->and(fn () => $save->handle($approver, $employee->id, '2026-10-16', 'Earlier', $figures))->toThrow(RuntimeException::class, 'An approved version already takes effect on 01 Nov 2026');
    $later = $save->handle($approver, $employee->id, '2026-12-01', 'Later', gdpFigures());

    // A draft is discarded, an approved version never; a version dated inside a posted payroll is refused.
    $discard = app(DiscardGovDeductionPolicy::class);
    expect(fn () => $discard->handle($approver, $id))->toThrow(RuntimeException::class, 'An approved version cannot be discarded.')
        ->and($discard->handle($approver, $later))->toBe('Draft from 01 Dec 2026 discarded.')
        ->and(PayrollGovDeductionPolicy::query()->whereKey($later)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditEvent::GOV_POLICY_DISCARDED->value)->sole()->details)->toBe('Pat Versioned · Contributions & tax draft from 01 Dec 2026 discarded (Later)');
    gdpPosted($employee, '2026-12-01', '2026-12-15');
    expect(fn () => $save->handle($approver, $employee->id, '2026-12-01', 'Posted', $figures))->toThrow(RuntimeException::class, 'A finalized payroll for Pat Versioned already reaches 15 Dec 2026. Start the version on the next cut-off.')
        ->and($save->handle($approver, $employee->id, '2026-12-16', 'After', $figures))->not->toBe('');

    // Pay data: a Records manager neither reads nor writes.
    $records = gdpActor(['RECORDS', 'EMPLOYEES']);
    expect(fn () => $save->handle($records, $employee->id, '2027-01-01', 'No', $figures))->toThrow(RuntimeException::class, 'Your role cannot manage employee pay settings.')
        ->and(fn () => app(GovDeductionPolicyRecords::class)->versions($records, $employee->id))->toThrow(HttpException::class)
        ->and(app(GovDeductionPolicyRecords::class)->versions(gdpActor(), $employee->id))->toHaveCount(2);
});

/* |---| What payroll computes with |---| */

it('lays the approved version in force over the employee, records it in the evidence, prices the fund at their own rate, and replays it', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $fund = gdpFund();
    $compute = static fn (): array => PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);

    $baseline = $compute();
    $before = $baseline['drafts'][0];
    expect($before->sss)->toBeGreaterThan(0.0)
        ->and($baseline['inputs']->govPolicies)->toBe([])
        ->and($baseline['inputs']->employees[0]->allow_sss)->toBeTrue()
        // The listed rate: 3% of 30,000, halved on the first cut-off.
        ->and($baseline['customDeductionLines'][$employee->id][0]['amount'])->toBe(450.0);

    // Prepared, then approved; only then does it price.
    $preparer = gdpActor();
    $id = app(SaveGovDeductionPolicy::class)->handle($preparer, $employee->id, '2026-09-01', 'Exempt from SSS', gdpFigures(['allow_sss' => false, 'fixed_pagibig_ee' => 400.0, 'percent_tax' => 5.0, 'percent_rates' => [$fund->id => 5.0]]));
    expect($compute()['inputs']->fingerprint())->toBe($baseline['inputs']->fingerprint());
    app(ApproveGovDeductionPolicy::class)->handle(gdpActor(), $id);

    $custom = $compute();
    $after = $custom['drafts'][0];
    $fundLine = $custom['customDeductionLines'][$employee->id][0];
    expect($after->sss)->toBe(0.0)
        ->and($after->sss_er)->toBe(0.0)
        ->and($after->pagibig)->toBe(200.0)
        ->and($after->withholding_tax)->toBeGreaterThan(0.0)
        ->and($fundLine['amount'])->toBe(750.0)
        ->and($fundLine['employer_amount'])->toBe(1500.0)
        ->and($custom['inputs']->employees[0]->allow_sss)->toBeFalse()
        ->and($custom['inputs']->govPolicies[$employee->id])->toMatchArray(['version_id' => $id, 'effective_from' => '2026-09-01'])
        ->and($custom['inputs']->govPolicies[$employee->id]['figures']['percent_rates'])->toEqual([$fund->id => 5.0])
        ->and($custom['inputs']->fingerprint())->not->toBe($baseline['inputs']->fingerprint());

    // A version from the 16th does not reach the first cut-off, and a later approved version supersedes.
    expect(GovDeductionPolicyStore::forEmployees([$employee->id], '2026-08-16'))->toBe([]);

    // The evidence replays the captured version, whatever the table holds later.
    $json = PayrollInputs::json($custom['inputs']->toArray());
    $restored = PayrollInputs::restore($json);
    expect($restored->fingerprint())->toBe($custom['inputs']->fingerprint())
        ->and(json_decode($json, true)['schema_version'])->toBe(24);
    PayrollGovDeductionPolicy::query()->whereKey($id)->delete();
    $fund->forceFill(['percent' => [...$fund->percent, 'employee' => 4.0]])->save();
    $replay = PayrollData::calculateInputs($restored, strict: true);
    expect($replay['drafts'][0]->sss)->toBe(0.0)
        ->and($replay['customDeductionLines'][$employee->id][0]['amount'])->toBe(750.0);

    // Tampered evidence is refused, never coerced.
    $data = json_decode($json, true);
    $data['gov_policies'][$employee->id]['figures']['percent_rates'][$fund->id] = 101;
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($data)))->toThrow(RuntimeException::class, 'Invalid payroll contribution policy evidence.');
    $data = json_decode($json, true);
    $data['gov_policies'][(string) Str::ulid()] = $data['gov_policies'][$employee->id];
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($data)))->toThrow(RuntimeException::class, 'Invalid payroll contribution policy evidence.');
});

it('keeps the record\'s baseline columns for an employee with no approved version', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $employee->forceFill(['allow_sss' => false, 'pagibig_mode' => 'QUARTERLY'])->save();
    $employee->refresh();

    $result = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($result['drafts'][0]->sss)->toBe(0.0)
        ->and($result['inputs']->employees[0]->pagibig_mode)->toBe('QUARTERLY')
        ->and($result['inputs']->govPolicies)->toBe([]);

    $settings = GovDeductionSettings::fromColumns($employee);
    expect($settings->get('allow_sss'))->toBeFalse()
        ->and($settings->get('pagibig_mode'))->toBe(GovDeductionSettings::MODE_MONTHLY)
        ->and($settings->customized())->toBe(['allow_sss', 'pagibig_mode']);
});

/* |---| The page |---| */

it('edits an employee\'s contributions and tax on the pay page, in effect at once, with no date, reason or approval', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    $employee = gdpEmployee('Paged', ['allow_philhealth' => false]);
    $fund = gdpFund();
    gdpActor();

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        // The card counts what differs from the standard, by the same test as each row's pill:
        // the record's own PhilHealth switch is one of the five rows.
        ->assertSee('1 of 5 set for this employee.')
        ->assertDontSee('Prepare a version')
        ->call('startEdit')
        ->assertSet('editing', true)
        ->assertDontSee('Takes effect on')
        // The form starts from the baseline the record holds.
        ->assertSet('form.philhealth_deduct', false)
        ->assertSet('form.percent.'.$fund->id, '')
        ->set('form.sss_deduct', false)
        ->set('form.tax_method', 'PERCENT')
        ->set('form.tax_amount', '5')
        ->set('form.percent.'.$fund->id, '10')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSet('formError', null)
        // In use at once: the card reads the settings, with the fund at the employee's rate.
        ->assertDontSee('Awaiting approval')
        ->assertSee('4 of 5 set for this employee.')
        ->assertSee('5% of taxable compensation')
        ->assertSee('Own rate 10%');
    $row = PayrollGovDeductionPolicy::query()->sole();
    expect($row->status)->toBe('APPROVED')
        ->and($row->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($row->params['allow_sss'])->toBeFalse()
        ->and($row->params['allow_philhealth'])->toBeFalse()
        ->and($row->params['percent_tax'])->toEqual(5.0)
        ->and($row->params['percent_rates'])->toEqual([$fund->id => 10.0])
        ->and(AuditLog::query()->where('action', 'GOV_POLICY_SAVED')->where('entity_id', $row->id)->count())->toBe(1);
    Livewire::test(EmployeePaySettingsPage::class)->assertSee('Paged')->assertDontSee('Awaiting approval');

    // "Reset to standard" fills the standard computation; saving replaces the same row.
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->call('startEdit')
        ->assertSee('Reset to Standard')
        ->call('useStandard')
        ->assertSet('form.sss_deduct', true)
        ->assertSet('form.percent.'.$fund->id, '')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('The standard settings apply.');
    $again = PayrollGovDeductionPolicy::query()->sole();
    expect($again->id)->toBe($row->id)
        ->and($again->params['allow_sss'])->toBeTrue()
        ->and($again->params['percent_rates'])->toBe([]);

    // A holder without all data reads nothing and writes nothing.
    gdpActor(['PAYROLL'], allData: false);
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertDontSeeHtml('wire:click="startEdit"')
        ->call('startEdit')
        ->assertSet('editing', false)
        ->set('form.sss_deduct', false)
        ->call('save');
    expect(PayrollGovDeductionPolicy::query()->sole()->params['allow_sss'])->toBeTrue()
        ->and(PayrollGovDeductionPolicy::query()->count())->toBe(1);
});
