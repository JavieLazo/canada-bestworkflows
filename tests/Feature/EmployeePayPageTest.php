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
use App\Modules\Payroll\Application\Commands\SaveBenefitAssignment;
use App\Modules\Payroll\Application\Commands\SaveRecurringLine;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * One employee's pay page answers "how is this employee paid": their rates
 * at a glance, each marked Company, their status or Own; the company
 * benefits their status and service qualify for, the incentives assigned
 * to them and their own recurring allowances in ONE table, each row saying
 * who set it and its status; the percentage deductions that reach everyone among their
 * contributions; their own loans, each running loan with when it is paid
 * off; and an add dialog whose kind is the card it opened from, previewing
 * a loan's payoff. The hourly rate and the salary are changed from Pay at a
 * glance, and the search in the head opens another employee's page.
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
        ->assertSee('Pay at a Glance')
        ->assertSee('₱32,000.00')
        ->assertSee('Changes each month')
        ->assertSee('₱210.00 an hour')
        ->assertSee('The company rule')
        ->assertSee('Contributions &amp; Tax', false)
        ->assertSee('Allowances &amp; incentives', false)
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

it('draws company benefits, assigned incentives and own allowances in one table, each with who set it and its status', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $actor = eppActor();
    $rule = ['amount_type' => 'FIXED', 'frequency' => 'EVERY', 'min_service_months' => 0, 'proration' => 'NONE', 'tax' => 'TAXABLE', 'counts_toward_13th' => false, 'is_taxable' => true];
    $rice = PayrollIncentiveType::create([...$rule, 'code' => '1', 'name' => 'Rice Allowance', 'amount' => 1000]);
    PayrollIncentiveType::create([...$rule, 'code' => '2', 'name' => 'Loyalty Pay', 'amount' => 500, 'min_service_months' => 12]);
    $transport = PayrollIncentiveType::create(['code' => '4', 'name' => 'Transportation Allowance', 'is_taxable' => true]);
    $employee = eppEmployee();
    app(SaveRecurringLine::class)->handle($actor, null, $employee->id, $transport->id, ['kind' => 'INCENTIVE', 'amount' => 750.0, 'total' => null, 'frequency' => 'EVERY', 'starts_on' => '2026-10-16', 'ends_on' => null], 'Field assignment');
    app(SaveBenefitAssignment::class)->handle($actor, $employee->id, $rice->id, '2026-11-01', null, null, 'Board memo');

    $html = Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        // The assigned incentive is a row of the one table, not a card of its own.
        ->assertDontSee('Incentives assigned')
        ->assertSee('Assigned · Board memo')
        ->assertSee('Awaiting approval')
        // A benefit still waiting on their service says so in the Status column.
        ->assertSee('Upcoming')
        ->assertSeeHtml('wire:click="openAssign"')
        ->html();

    $from = (int) strpos($html, 'pay-allowances-heading');
    $card = substr($html, $from, (int) strpos($html, 'pay-recurring-heading') - $from);
    expect(substr_count($html, 'data-assigned-benefits'))->toBe(1)
        ->and($card)->toContain('Rice Allowance')
        ->and($card)->toContain('Board memo')
        ->and($card)->toContain('Field assignment')
        ->and($card)->toContain('<th>Set By</th>')
        ->and($card)->toContain('<th>Status</th>')
        // "From" is only ever a date on this page: no column is headed with it.
        ->and($html)->not->toContain('<th>From</th>');
});

it('says why no incentive can be assigned while the catalog lists none paid on a schedule', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    eppActor();
    PayrollIncentiveType::create(['code' => '4', 'name' => 'Transportation Allowance', 'is_taxable' => true]);
    $employee = eppEmployee();

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('No incentive assigned.')
        ->assertSee('lists one paid on a schedule.')
        ->assertDontSeeHtml('wire:click="openAssign"');
});

it('keeps the salary in Pay at a glance, shows no start date for the rules, and closes the page with the Tax year', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    eppActor();
    $employee = eppEmployee();

    $html = Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('Change Salary')
        ->assertDontSee('Since ')
        ->assertDontSee('Rate Per Month on Employee Details')
        ->assertSeeHtml('data-salary-rates')
        ->html();

    expect(substr_count($html, 'data-salary-card'))->toBe(1)
        ->and(strpos($html, 'pay-glance-heading'))->toBeLessThan((int) strpos($html, 'data-salary-rates'))
        ->and(strpos($html, 'pay-recurring-heading'))->toBeLessThan((int) strpos($html, 'pay-tax-year-heading'));
});

it('words a recurring allowance\'s tax for people, never the stored code', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $actor = eppActor();
    $pera = PayrollIncentiveType::create(['code' => '4', 'name' => 'Transportation Allowance', 'is_taxable' => false, 'tax' => 'EXEMPT']);
    $employee = eppEmployee();
    app(SaveRecurringLine::class)->handle($actor, null, $employee->id, $pera->id, ['kind' => 'INCENTIVE', 'amount' => 750.0, 'total' => null, 'frequency' => 'EVERY', 'starts_on' => '2026-10-16', 'ends_on' => null], 'Field assignment');

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('Tax-exempt')
        ->assertDontSee('EXEMPT');
});

it('links each of the five cards from the bar under the employee\'s name', function () {
    eppActor();
    $employee = eppEmployee();

    $html = Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])->html();

    expect(substr_count($html, 'data-pay-sections'))->toBe(1);
    foreach (['pay-glance', 'pay-contributions', 'pay-allowances', 'pay-recurring', 'pay-tax-year'] as $anchor) {
        // A link in the bar, and the one card it leads to, named by the card's own heading.
        expect(substr_count($html, 'href="#'.$anchor.'"'))->toBe(1)
            ->and(substr_count($html, 'id="'.$anchor.'"'))->toBe(1)
            ->and($html)->toContain('aria-labelledby="'.$anchor.'-heading"')
            ->and(strpos($html, 'href="#'.$anchor.'"'))->toBeLessThan((int) strpos($html, 'id="'.$anchor.'"'));
    }
});

it('opens another employee from the search in the head, and ignores an unknown id or another picker', function () {
    eppActor();
    $employee = eppEmployee();
    $other = eppEmployee(['last_name' => 'Santos']);

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('Open another employee')
        ->call('settingsEmployeePicked', 'settings-employee', (string) Str::ulid())
        ->assertNoRedirect()
        ->call('settingsEmployeePicked', 'another-picker', $other->id)
        ->assertNoRedirect()
        ->call('settingsEmployeePicked', 'settings-employee', $other->id)
        ->assertRedirect(route('records.employee-pay-settings.show', ['employee' => $other->id]));
});

it('lists a percentage deduction once, among the contributions, with what it takes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    eppActor();
    PayrollDeduction::create(['code' => '9', 'name' => 'Provident Fund', 'percent' => ['base' => 'MONTHLY', 'employee' => 2.0, 'employer' => 2.0, 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => true]]);
    $employee = eppEmployee();

    $html = Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('No loan or deduction yet.')
        ->assertSee('₱320.00')
        ->html();

    expect(substr_count($html, 'Provident Fund'))->toBe(1)
        ->and(strpos($html, 'Provident Fund'))->toBeLessThan((int) strpos($html, 'pay-allowances-heading'));
});

it('lists their own loans, and when each running loan is paid off', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $actor = eppActor();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    $employee = eppEmployee();
    app(SaveRecurringLine::class)->handle($actor, null, $employee->id, $loan->id, ['kind' => 'DEDUCTION', 'amount' => 1000.0, 'total' => 12000.0, 'frequency' => 'EVERY', 'starts_on' => '2026-11-01', 'ends_on' => null], 'Loan 2026-114');

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('SSS Loan')
        ->assertSeeHtml('Paid off on the payroll from <span class="num">16 Apr 2027</span>')
        ->assertDontSee('No loan or deduction yet.');
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
        ->assertDontSee('Total loan payable')
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
