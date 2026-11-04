<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Livewire\Records\IncentiveTypesPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\PayrollBenefitCeiling;
use App\Models\PayrollEmployeeBenefit;
use App\Models\PayrollIncentiveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\ApproveBenefitAssignment;
use App\Modules\Payroll\Application\Commands\DiscardBenefitAssignment;
use App\Modules\Payroll\Application\Commands\SaveBenefitAssignment;
use App\Modules\Payroll\Application\Commands\SaveBenefitCeiling;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * TX05, the tax-free ceiling and benefit assignments: the yearly ceiling
 * is a dated version written by whoever manages payroll settings, captured
 * in the payroll and 13th month evidence and used by the split; a benefit
 * assigned to one employee is a DRAFT until approved (by the preparer
 * included), proposes the type on every payroll inside its span whether or not
 * the status rule qualifies them, at its own amount when it carries one,
 * and is captured in the evidence so a replay never reads the live table.
 */

/** @param list<string> $modules */
function benaActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null, string $name = 'Pay Officer'): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Benefit '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['name' => $name, 'role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function benaStatus(string $name): EmploymentStatusOption
{
    $status = new EmploymentStatusOption;
    $status->forceFill(['code' => (string) (EmploymentStatusOption::query()->count() + 1), 'name' => $name])->save();

    return $status;
}

/** @param array<string, mixed> $changes */
function benaType(Access $actor, string $name, array $changes = []): PayrollIncentiveType
{
    $figures = array_replace([
        'amount_type' => BenefitRule::AMOUNT_FIXED, 'amount' => 2000.0, 'frequency' => BenefitRule::FREQUENCY_FIRST, 'month' => null,
        'statuses' => null, 'min_service_months' => 0, 'proration' => BenefitRule::PRORATION_NONE, 'tax' => BenefitRule::TAX_NOT_INCOME,
        'cap' => null, 'approval_reference' => null, 'counts_toward_13th' => false,
    ], $changes);
    app(SaveIncentiveType::class)->handle($actor, null, $name, null, $figures['tax'] === BenefitRule::TAX_TAXABLE, $figures);

    return PayrollIncentiveType::query()->where('name', $name)->sole();
}

/*
|--------------------------------------------------------------------------
| The ceiling
|--------------------------------------------------------------------------
*/

it('records a dated ceiling version after the latest, audits it and shows it on the Incentives page', function () {
    $actor = benaActor();
    $seeded = PayrollBenefitCeiling::query()->sole();
    expect($seeded->ceiling)->toBe(90000.0)->and($seeded->effective_from->format('Y-m-d'))->toBe('2025-01-01');

    $command = app(SaveBenefitCeiling::class);
    expect(fn () => $command->handle($actor, '2025-01-01', '95,000', 'Same day'))->toThrow(RuntimeException::class, 'already takes effect')
        ->and(fn () => $command->handle($actor, '2024-12-31', '95,000', 'Earlier'))->toThrow(RuntimeException::class, 'already takes effect')
        ->and(fn () => $command->handle($actor, '2027-01-01', '-1', 'Negative'))->toThrow(RuntimeException::class, 'at or above zero')
        ->and(fn () => $command->handle($actor, '2027-01-01', '95,000', ''))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $command->handle($actor, 'soon', '95,000', 'Bad date'))->toThrow(RuntimeException::class, 'date');
    expect($command->handle($actor, '2027-01-01', '95,000.00', 'Raised for the year'))->toContain('95,000.00')->toContain('2027');
    $row = PayrollBenefitCeiling::query()->where('effective_from', '2027-01-01')->sole();
    expect($row->ceiling)->toBe(95000.0)->and($row->created_by)->toBe((int) $actor->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::BENEFIT_CEILING_SAVED->value)->where('entity_id', $row->id)->exists())->toBeTrue();

    Livewire::test(IncentiveTypesPage::class)
        ->assertSeeHtml('data-benefit-ceiling')
        ->assertSeeHtml('data-ceiling-row="'.$row->id.'"')
        ->assertSee('95,000.00')->assertSee('Upcoming')->assertSee('In force')
        ->call('openCeiling')
        ->assertSet('ceilingForm.effective_from', '2027-01-02')
        ->set('ceilingForm.amount', '100000')->set('ceilingForm.reason', 'Another')
        ->call('saveCeiling')
        ->assertSet('ceilingOpen', false);
    expect(PayrollBenefitCeiling::query()->count())->toBe(3);
});

it('refuses a ceiling version from an actor without all-data PAYROLL, on the page and in the command', function () {
    $actor = benaActor(['PAYROLL', 'RECORDS'], allData: false);
    expect(fn () => app(SaveBenefitCeiling::class)->handle($actor, '2027-01-01', '95000', 'No'))->toThrow(RuntimeException::class, 'cannot manage');
    Livewire::test(IncentiveTypesPage::class)->assertSeeHtml('data-benefit-ceiling')->assertDontSee('Set ceiling')
        ->set('ceilingForm', ['effective_from' => '2027-01-01', 'amount' => '95000', 'reason' => 'No'])->call('saveCeiling');
    expect(PayrollBenefitCeiling::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditEvent::BENEFIT_CEILING_SAVED->value)->exists())->toBeFalse();
});

it('captures the ceiling in force on the period\'s first day and splits the exempt benefits with it', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    benaType($actor, 'Bonus', ['amount' => 5000.0, 'tax' => BenefitRule::TAX_EXEMPT]);
    app(SaveBenefitCeiling::class)->handle($actor, '2026-09-01', '3000', 'Lowered for the test');

    $calc = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($calc['drafts'][0]->ceiling_benefits)->toBe(3000.0)
        ->and($calc['drafts'][0]->taxable_benefits_excess)->toBe(2000.0)
        ->and($calc['inputs']->benefitCeiling)->toMatchArray(['effective_from' => '2026-09-01', 'ceiling' => 3000.0])
        ->and($calc['inputs']->ceiling())->toBe(3000.0);

    // The day before the version: the earlier one applies, and a replay keeps whichever was captured.
    $before = PayrollData::calculateDrafts([$employee->id], '2026-08-16', '2026-08-31', actor: $actor);
    expect($before['inputs']->benefitCeiling['effective_from'])->toBe('2025-01-01')->and($before['inputs']->ceiling())->toBe(90000.0);
    $restored = PayrollInputs::restore(PayrollInputs::json($calc['inputs']->toArray()));
    expect($restored->ceiling())->toBe(3000.0)
        ->and(PayrollData::calculateInputs($restored, strict: true)['drafts'][0]->ceiling_benefits)->toBe(3000.0)
        ->and(PayrollInputs::restore(PayrollInputs::json(PayrollEvidenceFixture::asSchema(['schema_version' => 20] + $calc['inputs']->toArray(), 20)))->ceiling())->toBe(90000.0);
});

/*
|--------------------------------------------------------------------------
| Assignments
|--------------------------------------------------------------------------
*/

it('drafts an assignment, approves it through the employee\'s own account, and discards a second draft', function () {
    $preparer = benaActor(name: 'Preparer');
    benaStatus('Regular');
    $contract = benaStatus('Contract of Service');
    $pera = benaType($preparer, 'PERA', ['statuses' => [$contract->id]]);
    $typed = benaType($preparer, 'Typed', ['amount_type' => BenefitRule::AMOUNT_TYPED, 'amount' => null, 'frequency' => BenefitRule::FREQUENCY_ONCE]);
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'BEN-1', 'first_name' => 'Ana', 'last_name' => 'Benefit', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true])->save();

    $save = app(SaveBenefitAssignment::class);
    expect(fn () => $save->handle($preparer, $employee->id, $typed->id, '2026-09-01', null, null, 'Typed'))->toThrow(RuntimeException::class, 'typed per payroll')
        ->and(fn () => $save->handle($preparer, $employee->id, $pera->id, '2026-09-01', '2026-08-31', null, 'Backwards'))->toThrow(RuntimeException::class, 'end date')
        ->and(fn () => $save->handle($preparer, $employee->id, $pera->id, '2026-09-01', null, '0', 'Zero'))->toThrow(RuntimeException::class, 'above zero')
        ->and(fn () => $save->handle($preparer, $employee->id, $pera->id, '2026-09-01', null, null, ''))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $save->handle($preparer, $employee->id, 'nope', '2026-09-01', null, null, 'Gone'))->toThrow(RuntimeException::class, 'Choose a benefit');
    expect($save->handle($preparer, $employee->id, $pera->id, '2026-09-01', '2026-12-31', '1,250.50', 'Special grant', 'Memo 7'))->toContain('paid once approved');
    $row = PayrollEmployeeBenefit::query()->sole();
    expect($row->status)->toBe('DRAFT')->and($row->amount)->toBe(1250.5)->and($row->reference)->toBe('Memo 7')
        ->and($row->prepared_by)->toBe((int) $preparer->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::BENEFIT_ASSIGNED->value)->where('entity_id', $row->id)->exists())->toBeTrue();

    // A viewer cannot approve; the employee's own all-data account can — one administrator runs the whole flow.
    $viewer = benaActor(['PAYROLL'], allData: false, name: 'Viewer');
    expect(fn () => app(ApproveBenefitAssignment::class)->handle($viewer, $row->id))->toThrow(RuntimeException::class, 'cannot manage');
    expect($row->refresh()->status)->toBe('DRAFT');

    $approver = benaActor(employeeId: $employee->id, name: 'Ana Benefit');
    expect(app(ApproveBenefitAssignment::class)->handle($approver, $row->id))->toContain('approved');
    expect($row->refresh()->status)->toBe('APPROVED')->and($row->approved_by)->toBe((int) $approver->userId)->and($row->approved_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditEvent::BENEFIT_ASSIGNMENT_APPROVED->value)->where('entity_id', $row->id)->exists())->toBeTrue()
        ->and(fn () => app(ApproveBenefitAssignment::class)->handle($approver, $row->id))->toThrow(RuntimeException::class, 'already approved')
        ->and(fn () => app(DiscardBenefitAssignment::class)->handle($approver, $row->id))->toThrow(RuntimeException::class, 'not discarded');

    // A second draft is discarded, and the audit names it.
    $save->handle($preparer, $employee->id, $pera->id, '2027-01-01', null, null, 'Next year');
    $draft = PayrollEmployeeBenefit::query()->where('status', 'DRAFT')->sole();
    expect(app(DiscardBenefitAssignment::class)->handle($approver, $draft->id))->toContain('Draft discarded');
    expect(PayrollEmployeeBenefit::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditEvent::BENEFIT_ASSIGNMENT_DISCARDED->value)->where('entity_id', $draft->id)->exists())->toBeTrue();
});

it('proposes an assigned benefit the status rule would skip, at the assignment\'s own amount, only once approved and inside its span', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    benaStatus('Regular');
    $contract = benaStatus('Contract of Service');
    $pera = benaType($actor, 'PERA', ['statuses' => [$contract->id]]);
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['incentiveLines'])->toBe([]);

    $preparer = benaActor(name: 'Preparer');
    app(SaveBenefitAssignment::class)->handle($preparer, $employee->id, $pera->id, '2026-09-01', '2026-09-30', '750', 'Granted');
    // A draft proposes nothing.
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $preparer)['incentiveLines'])->toBe([]);
    $approver = benaActor(name: 'Approver');
    $row = PayrollEmployeeBenefit::query()->sole();
    app(ApproveBenefitAssignment::class)->handle($approver, $row->id);

    $calc = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $approver);
    expect($calc['incentiveLines'][$employee->id][0])->toMatchArray(['type_id' => $pera->id, 'amount' => 750.0, 'automatic' => true])
        ->and($calc['inputs']->benefitAssignments[$employee->id][0])->toMatchArray(['id' => $row->id, 'type_id' => $pera->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'amount' => 750.0]);
    // Outside the span the type's own rule decides again, and the evidence carries no assignment.
    $after = PayrollData::calculateDrafts([$employee->id], '2026-10-01', '2026-10-15', actor: $approver);
    expect($after['incentiveLines'])->toBe([])->and($after['inputs']->benefitAssignments)->toBe([]);
    // A replay reads the captured assignments, never the table.
    $row->forceFill(['amount' => 9999.0])->save();
    $replayed = PayrollData::calculateInputs(PayrollInputs::restore(PayrollInputs::json($calc['inputs']->toArray())), strict: true);
    expect($replayed['incentiveLines'][$employee->id][0]['amount'])->toBe(750.0);
});

it('lists, drafts, approves and discards assignments on the employee pay page, and a viewer without all data changes nothing', function () {
    $preparer = benaActor(name: 'Preparer');
    benaStatus('Regular');
    $pera = benaType($preparer, 'PERA');
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'BEN-2', 'first_name' => 'Ben', 'last_name' => 'Assigned', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true])->save();

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSeeHtml('data-assigned-benefits')->assertSee('No benefit assigned')
        ->call('openAssign')->assertSet('assignOpen', true)
        ->set('assignForm.type_id', $pera->id)->set('assignForm.starts_on', '2026-09-01')->set('assignForm.reason', 'From the page')
        ->call('saveAssignment')->assertSet('assignOpen', false)->assertSet('assignError', null)
        ->assertSee('Awaiting approval')->assertSee('PERA');
    $row = PayrollEmployeeBenefit::query()->sole();
    expect($row->status)->toBe('DRAFT')->and($row->amount)->toBeNull();

    // A viewer changes nothing.
    benaActor(['PAYROLL', 'RECORDS'], allData: false, name: 'Viewer');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->set('assignForm', ['type_id' => $pera->id, 'starts_on' => '2026-10-01', 'ends_on' => '', 'amount' => '', 'reason' => 'No', 'reference' => ''])
        ->call('saveAssignment')->call('approveAssignment', $row->id)->call('discardAssignment', $row->id);
    expect(PayrollEmployeeBenefit::query()->count())->toBe(1)->and($row->refresh()->status)->toBe('DRAFT');

    benaActor(name: 'Approver');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])->call('approveAssignment', $row->id)->assertSee('Active');
    expect($row->refresh()->status)->toBe('APPROVED');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->call('openAssign')->set('assignForm.type_id', $pera->id)->set('assignForm.starts_on', '2027-01-01')->set('assignForm.reason', 'Later')
        ->call('saveAssignment');
    $draft = PayrollEmployeeBenefit::query()->where('status', 'DRAFT')->sole();
    benaActor(name: 'Second approver');
    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])->call('discardAssignment', $draft->id)->assertDontSee('Later');
    expect(PayrollEmployeeBenefit::query()->count())->toBe(1);
    expect(ModuleCode::PAYROLL)->toBe('PAYROLL');
});
