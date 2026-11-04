<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\PayrollDeduction;
use App\Models\PayrollIncentiveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SaveRecurringLine;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * One employee's pay page answers "how is this employee paid": their rates
 * at a glance, each marked Company, their status or Own; the company
 * benefits their status and service qualify for beside their own recurring
 * allowances; the percentage deductions that reach everyone beside their
 * own loans, each running loan with when it is paid off; and an add dialog
 * whose kind is the card it opened from, previewing a loan's payoff. The
 * hourly rate is edited from Pay at a glance through the same audited save.
 */

/** An all-data PAYROLL officer, signed in. */
function eppActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Pay page '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides */
function eppEmployee(array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'EPP-'.Str::upper(Str::random(5)),
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'employment_status' => 'Regular',
        'date_hired' => '2026-06-01',
        'monthly_rate' => 32000,
        'is_active' => true,
    ], $overrides))->save();

    return $employee->refresh();
}

it('shows the rates at a glance with where each comes from, and edits the hourly rate there', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    eppActor();
    $employee = eppEmployee(['hourly_rate' => 210]);

    $page = Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('Pay at a glance')
        ->assertSee('₱32,000.00')
        ->assertSee('Changes each month')
        ->assertSee('₱210.00 an hour')
        ->assertSee('The company rule')
        ->assertSee('Contributions &amp; tax', false)
        ->assertSee('Allowances &amp; benefits', false)
        ->assertSee('Loans &amp; other deductions', false);

    $page->call('startEditHourly')
        ->assertSet('hourlyEditing', true)
        ->assertSet('editing', false)
        ->set('hourlyRate', '225')
        ->call('saveHourly')
        ->assertSet('hourlyError', 'Enter the reason for this change.')
        ->set('hourlyReason', 'New overtime agreement')
        ->call('saveHourly')
        ->assertSet('hourlyEditing', false);
    expect($employee->refresh()->hourly_rate)->toBe(225.0)
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_SETTINGS_UPDATED->value)->count())->toBe(1);
});

it('lists the company benefits their status and service qualify for beside their own allowances', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $actor = eppActor();
    $regular = new EmploymentStatusOption;
    $regular->forceFill(['code' => '1', 'name' => 'Regular'])->save();
    $contract = new EmploymentStatusOption;
    $contract->forceFill(['code' => '2', 'name' => 'Contract of Service'])->save();
    $rule = ['amount_type' => 'FIXED', 'frequency' => 'EVERY', 'min_service_months' => 0, 'proration' => 'NONE', 'tax' => 'TAXABLE', 'counts_toward_13th' => false, 'is_taxable' => true];
    PayrollIncentiveType::create([...$rule, 'code' => '1', 'name' => 'Rice Allowance', 'amount' => 1000]);
    PayrollIncentiveType::create([...$rule, 'code' => '2', 'name' => 'Loyalty Pay', 'amount' => 500, 'min_service_months' => 12]);
    PayrollIncentiveType::create([...$rule, 'code' => '3', 'name' => 'Contract Stipend', 'amount' => 300, 'statuses' => [$contract->id]]);
    $transport = PayrollIncentiveType::create(['code' => '4', 'name' => 'Transportation Allowance', 'is_taxable' => true]);
    $employee = eppEmployee();
    app(SaveRecurringLine::class)->handle($actor, null, $employee->id, $transport->id, ['kind' => 'INCENTIVE', 'amount' => 750.0, 'total' => null, 'frequency' => 'EVERY', 'starts_on' => '2026-10-16', 'ends_on' => null], 'Field assignment');

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('Rice Allowance')
        ->assertSee('Loyalty Pay')
        ->assertSee('From 01 Jun 2027, after 12 months of service')
        ->assertDontSee('Contract Stipend')
        ->assertSee('Transportation Allowance')
        ->assertSee('Field assignment');
});

it('lists the percentage deductions beside their own loans, and when each running loan is paid off', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $actor = eppActor();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    PayrollDeduction::create(['code' => '9', 'name' => 'Provident Fund', 'percent' => ['base' => 'MONTHLY', 'employee' => 2.0, 'employer' => 2.0, 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => true]]);
    $employee = eppEmployee();
    app(SaveRecurringLine::class)->handle($actor, null, $employee->id, $loan->id, ['kind' => 'DEDUCTION', 'amount' => 1000.0, 'total' => 12000.0, 'frequency' => 'EVERY', 'starts_on' => '2026-11-01', 'ends_on' => null], 'Loan 2026-114');

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('Provident Fund')
        ->assertSee('₱320.00')
        ->assertSee('SSS Loan')
        ->assertSeeHtml('Paid off on the payroll from <span class="num">16 Apr 2027</span>')
        ->assertDontSee('No recurring line yet.');
});

it('opens the dialog as the card it came from, and previews when a loan is paid off', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    eppActor();
    PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    PayrollIncentiveType::create(['code' => '4', 'name' => 'Transportation Allowance', 'is_taxable' => true]);
    $employee = eppEmployee();

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->call('addLine', 'INCENTIVE')
        ->assertSet('lineKind', 'INCENTIVE')
        ->assertSee('Add allowance · Ana Reyes')
        ->assertSee('Transportation Allowance')
        ->assertDontSee('Total to pay off')
        ->call('addLine', 'NONSENSE')
        ->assertSet('lineKind', 'DEDUCTION')
        ->assertSee('Add loan or deduction · Ana Reyes')
        ->assertSee('Not on the list?')
        ->set('lineStartsOn', '2026-09-01')
        ->set('lineAmount', '1,000')
        ->set('lineTotal', '12000')
        ->assertSee('12 payrolls, up to ₱1,000.00 each')
        ->assertSeeHtml('Paid off on the payroll from <span class="num">16 Feb 2027</span>')
        ->set('lineFrequency', 'SECOND')
        ->assertSeeHtml('Paid off on the payroll from <span class="num">16 Aug 2027</span>')
        ->set('lineTotal', '')
        ->assertDontSee('payrolls, up to');
});
