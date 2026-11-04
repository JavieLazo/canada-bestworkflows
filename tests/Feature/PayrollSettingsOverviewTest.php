<?php

declare(strict_types=1);

use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\StatutorySchedule;
use App\Livewire\Payroll\SettingsOverview;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollPayRule;
use App\Models\PayrollStatutoryTable;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Catalogs\Deductions;
use App\Modules\Payroll\Application\Commands\AddStatutoryTableVersion;
use App\Modules\Payroll\Application\Commands\SavePayRulesVersion;
use App\Modules\Payroll\Application\Commands\SaveRecurringLine;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Payroll → Payroll Settings: the settings moved under Payroll (gated on
 * the PAYROLL grant, their Records addresses forwarding), share a switch,
 * and open on an overview of what is in force. The overview writes
 * nothing; its "Needs attention" list names what would stop or spoil the
 * next payroll, and everything that names an employee or their pay shows
 * only to an all-data PAYROLL holder. Whoever manages payroll manages
 * every company-wide setting (PayrollSettingsAccess).
 */

/** @param list<string> $modules */
function psoActor(array $modules = ['PAYROLL'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Settings '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides */
function psoEmployee(string $lastName, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'PSO-'.Str::upper(Str::random(5)),
        'first_name' => 'Pat',
        'last_name' => $lastName,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
        'is_active' => true,
    ], $overrides))->save();

    return $employee->refresh();
}

/* |---| The gate and the menu |---| */

it('opens under Payroll for PAYROLL holders, forwards the old addresses and lights Payroll Settings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $this->get('/payroll/settings')->assertRedirect(route('login'));

    // Records alone opens nothing here, old addresses included; a PAYROLL
    // holder's old bookmarks forward.
    psoActor(['RECORDS']);
    $this->get('/payroll/settings')->assertRedirect();
    $this->get('/records/contribution-tables')->assertRedirect('/records/positions');
    psoActor(['PAYROLL']);
    $this->get('/records/contribution-tables')->assertRedirect('/payroll/settings/contribution-tables');
    $this->get('/records/employee-pay-settings')->assertRedirect('/payroll/settings/employees');

    psoActor(['PAYROLL', 'RECORDS']);
    $page = $this->get('/payroll/settings')->assertOk()
        ->assertSee('What payroll uses today, and where to change it.')
        ->assertSee('aria-label="Payroll Settings pages"', false)
        ->assertSee(route('payroll.settings.deductions'), false);
    $html = (string) $page->getContent();
    // One sidebar entry under Payroll; the Records tree no longer holds the settings.
    expect(substr_count($html, 'href="'.url('/payroll/settings').'"'))->toBeGreaterThanOrEqual(2)
        ->and($html)->not->toContain('href="'.url('/records/pay-rates').'"')
        ->and($html)->not->toContain('href="'.url('/records/employee-pay-settings').'"');

    foreach (['/payroll/settings/employees', '/payroll/settings/contribution-tables', '/payroll/settings/pay-rates', '/payroll/settings/payroll-deductions', '/payroll/settings/incentive-types'] as $path) {
        $this->get($path)->assertOk()->assertSee('aria-label="Payroll Settings pages"', false);
    }
});

/* |---| What it shows |---| */

it('lists what needs attention, most pressing first, each with the page that fixes it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $actor = psoActor();
    $loan = PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);
    psoEmployee('Norate', ['monthly_rate' => 0, 'daily_rate' => null]);
    $gone = psoEmployee('Leaver', ['is_active' => false]);
    $nearly = psoEmployee('Nearly');
    $save = app(SaveRecurringLine::class);
    $save->handle($actor, null, $gone->id, $loan->id, ['kind' => 'DEDUCTION', 'amount' => 500.0, 'total' => 4500.0, 'frequency' => 'EVERY', 'starts_on' => '2026-10-01', 'ends_on' => null], 'Loan 9');
    $save->handle($actor, null, $nearly->id, $loan->id, ['kind' => 'DEDUCTION', 'amount' => 500.0, 'total' => 500.0, 'frequency' => 'EVERY', 'starts_on' => '2026-10-16', 'ends_on' => null], 'Loan 10');
    app(SavePayRulesVersion::class)->handle($actor, PayrollPayRule::COMPANY, '2026-11-16', 'New rates', PayRules::fromFormulas()->toArray());

    $html = Livewire::test(SettingsOverview::class)
        ->assertSee('SSS, PhilHealth, Pag-IBIG and Withholding Tax tables confirmed through 31 Dec 2026.')
        ->assertSee('61 days left')
        ->assertSee('Pat Norate has no Rate Per Month or Rate Per Day.')
        ->assertSee('Paid ₱0.00')
        ->assertSee('Pat Leaver has left with ₱4,500.00 still owed on SSS Loan.')
        ->assertSee('New company Pay Basis & Rates start on 16 Nov 2026.')
        ->assertSee('Pat Nearly\'s SSS Loan is paid off on the next payroll it is due on.')
        ->html();
    $attention = (string) strstr((string) strstr($html, 'data-needs-attention'), '</section>', true);
    expect(strpos($attention, 'tables confirmed through'))->toBeLessThan((int) strpos($attention, 'no Rate Per Month'))
        ->and(strpos($attention, 'no Rate Per Month'))->toBeLessThan((int) strpos($attention, 'has left with'))
        ->and(strpos($attention, 'has left with'))->toBeLessThan((int) strpos($attention, 'New company Pay Basis'))
        ->and(strpos($attention, 'New company Pay Basis'))->toBeLessThan((int) strpos($attention, 'is paid off on the next payroll'));
});

it('says so when nothing needs attention, and shows the settings in force', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 04:00:00', 'UTC'));
    psoActor();
    PayrollDeduction::create(['code' => '7', 'name' => 'SSS Loan']);

    Livewire::test(SettingsOverview::class)
        ->assertSee('Nothing needs attention.')
        ->assertSee('Daily basis · a day is the monthly rate ÷ the month\'s working days')
        ->assertSee('None: every status follows the company rule')
        ->assertSee('SSS Loan')
        ->assertSee('Everyone is paid by the company settings.');
});

it('keeps employees and their pay to all-data PAYROLL holders', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    psoEmployee('Norate', ['monthly_rate' => 0, 'daily_rate' => null]);
    psoEmployee('Fixedtax', ['fixed_tax' => 500]);

    psoActor(['PAYROLL'], false);
    Livewire::test(SettingsOverview::class)
        ->assertSee('tables confirmed through 31 Dec 2026.')
        ->assertDontSee('Norate')
        ->assertDontSee('Fixedtax')
        ->assertDontSee('Employees paid differently')
        ->assertDontSee('Find an employee')
        ->call('employeePicked', 'settings-employee', EmployeeProfile::query()->value('id'))
        ->assertNoRedirect();

    psoActor();
    Livewire::test(SettingsOverview::class)
        ->assertSee('Employees paid differently')
        ->assertSee('Pat Fixedtax')
        ->assertSee('Withholding tax: ₱500.00 fixed');
});

it('opens an employee\'s pay settings from the search, and ignores an unknown id', function () {
    psoActor();
    $employee = psoEmployee('Picked');

    Livewire::test(SettingsOverview::class)
        ->call('employeePicked', 'settings-employee', (string) Str::ulid())
        ->assertNoRedirect()
        ->call('employeePicked', 'another-picker', $employee->id)
        ->assertNoRedirect()
        ->call('employeePicked', 'settings-employee', $employee->id)
        ->assertRedirect(route('records.employee-pay-settings.show', ['employee' => $employee->id]));
});

/* |---| Who manages the settings |---| */

it('lets an all-data PAYROLL holder manage the company-wide settings, and nobody without all data', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 04:00:00', 'UTC'));
    $actor = psoActor(['PAYROLL']);
    $versions = PayrollStatutoryTable::query()->count();
    app(AddStatutoryTableVersion::class)->handle($actor, StatutorySchedule::SSS, '2027-01-01', '2027-12-31', 'Payroll', StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]);
    app(Deductions::class)->save($actor, null, 'Cash Advance', '');
    app(SavePayRulesVersion::class)->handle($actor, PayrollPayRule::COMPANY, '2026-11-16', 'Payroll', PayRules::fromFormulas()->toArray());
    expect(PayrollStatutoryTable::query()->count())->toBe($versions + 1)
        ->and(PayrollDeduction::query()->where('name', 'Cash Advance')->exists())->toBeTrue()
        ->and(PayrollPayRule::query()->where('effective_from', '2026-11-16')->exists())->toBeTrue();

    $reader = psoActor(['PAYROLL'], false);
    expect(fn () => app(AddStatutoryTableVersion::class)->handle($reader, StatutorySchedule::SSS, '2028-01-01', '2028-12-31', 'No', StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.')
        ->and(fn () => app(Deductions::class)->save($reader, null, 'Uniform', ''))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.')
        ->and(PayrollStatutoryTable::query()->count())->toBe($versions + 1)
        ->and(PayrollDeduction::query()->where('name', 'Uniform')->exists())->toBeFalse();
});
