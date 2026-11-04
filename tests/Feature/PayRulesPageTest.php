<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayRules;
use App\Livewire\Records\PayRulesPage;
use App\Models\AuditLog;
use App\Models\EmploymentStatusOption;
use App\Models\PayrollPayRule;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Pay Basis & Rates page, under Payroll Settings: any PAYROLL holder reads the figures and the
 * versions; only an all-data holder gets the presets and the save, and
 * every save goes through the audited command, which refuses anyone else.
 * A percentage typed as shown keeps the stored fraction; a blank cell
 * stays "as derived"; a rate below the usual minimum is flagged; every
 * version needs a reason and a date (any day: a payroll it falls inside
 * prices each day by the version in force on it).
 */
function prpageActor(bool $allData = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Rates page '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::PAYROLL])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => null]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function prpageStatus(string $name): EmploymentStatusOption
{
    $status = new EmploymentStatusOption;
    $status->forceFill(['code' => (string) (EmploymentStatusOption::query()->count() + 1), 'name' => $name])->save();

    return $status;
}

it('gates the page on the PAYROLL grant and shows a read-only form to a holder without all data', function () {
    $this->get('/payroll/settings/pay-rates')->assertRedirect(route('login'));

    prpageActor(false);
    $this->get('/payroll/settings/pay-rates')
        ->assertOk()
        ->assertSee('Pay Basis &amp; Rates', false)
        ->assertSee('Rates by day type')
        ->assertSee('Ordinary workday')
        ->assertSee('Two regular holidays on a rest day')
        ->assertSee('01 Jan 2025')
        ->assertSee('Current')
        ->assertSee('Rest day, overtime hour: 162.5% is below the usual minimum of 169%.')
        ->assertSee('Your role can view these rules.')
        ->assertDontSee('Save as new version')
        ->assertDontSee('Fill in from a preset');

    $count = PayrollPayRule::query()->count();
    Livewire::test(PayRulesPage::class)
        ->set('formMatrix.REST.overtime', '169')
        ->call('save');
    expect(PayrollPayRule::query()->count())->toBe($count);
});

it('fills the form from the seeded version and from the presets without saving anything', function () {
    prpageActor();
    $page = Livewire::test(PayRulesPage::class)
        ->assertSet('formBasis', PayRules::BASIS_DAILY)
        ->assertSet('formMethod', PayRules::METHOD_MONTH)
        ->assertSet('formMatrix.ORDINARY.overtime', '125')
        ->assertSet('formMatrix.ORDINARY.night', '110')
        ->assertSet('formMatrix.REST.worked', '130')
        ->assertSet('formMatrix.REST.overtime', '')
        ->assertSet('formNightStart', '22:00')
        ->assertSet('formNightEnd', '06:00')
        ->assertSet('formHolidayOvertime', '130')
        ->assertSee('placeholder="162.5"', false)
        ->assertSee('placeholder="140"', false)
        ->assertDontSee('Unsaved changes');

    $page->call('applyPreset', PayRules::PRESET_GOVERNMENT)
        ->assertSet('formBasis', PayRules::BASIS_MONTHLY)
        ->assertSet('formMethod', PayRules::METHOD_DIVISOR)
        ->assertSet('formDivisor', '22')
        ->assertSet('formNightStart', '18:00')
        ->assertSet('formNightOnOvertime', true)
        ->assertSet('formMatrix.REST.worked', '150')
        ->assertSet('formMatrix.REGULAR.worked', '250')
        ->assertSee('Unsaved changes')
        ->assertSee('₱1,363.64');
    $page->call('applyPreset', PayRules::PRESET_STANDARD)
        ->assertSet('formBasis', PayRules::BASIS_DAILY)
        ->assertSet('formHolidayNeedsAttendance', true)
        ->assertSet('formMatrix.REGULAR.unworked', '100')
        ->assertSet('formMatrix.REGULAR.worked', '');
    expect(PayrollPayRule::query()->count())->toBe(1);
});

it('saves a new version through the command, keeping a percentage shown unchanged as its stored fraction', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    prpageActor();
    $page = Livewire::test(PayRulesPage::class)
        // Today, the earliest day a version may start.
        ->assertSet('formEffectiveFrom', '2026-10-12')
        ->set('formMatrix.REST.overtime', '169')
        ->set('formMatrix.REST.night', '143')
        ->set('formMatrix.REGULAR.unworked', '100')
        ->set('formMatrix.DOUBLE.unworked', '200')
        ->assertDontSee('is below the usual minimum')
        ->set('formEffectiveFrom', '2026-11-01')
        ->set('formReason', 'Rates at the minimums')
        ->call('save')
        ->assertSet('formError', null)
        ->assertSet('formEffectiveFrom', '2026-11-02')
        ->assertSet('formReason', '');

    $saved = PayrollPayRule::query()->where('effective_from', '2026-11-01')->sole();
    expect($saved->scope)->toBe(PayrollPayRule::COMPANY)
        ->and($saved->params['overtime']['rate'])->toBe(1.25)
        ->and($saved->params['night'])->toEqual(['rate' => 0.1, 'max_hours' => 8.0, 'start' => 1320, 'end' => 1800, 'on_overtime' => false])
        ->and($saved->params['rest_day']['rate'])->toBe(1.3)
        ->and($saved->params['holiday_overtime_factor'])->toBe(1.3)
        ->and($saved->params['matrix']['REST'])->toEqual(['worked' => null, 'overtime' => 169.0, 'night' => 143.0, 'unworked' => 0.0])
        ->and($saved->params['matrix']['REGULAR']['unworked'])->toEqual(100)
        ->and($saved->params['matrix']['ORDINARY'])->toEqual(['worked' => null, 'overtime' => null, 'night' => null, 'unworked' => 0.0])
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_RULES_SAVED->value)->where('entity_id', $saved->id)->exists())->toBeTrue();
    $page->assertSee('01 Nov 2026')->assertSee('Upcoming');

    // The form reloads from the version in force (the seeded one, with its
    // shortfalls), so the next save starts from the upcoming version instead.
    // The next version must start later; a changed base rate is converted once.
    $page->call('loadVersion', $saved->id)
        ->set('formMatrix.ORDINARY.overtime', '150')
        ->set('formEffectiveFrom', '2026-11-01')
        ->set('formReason', 'Higher overtime')
        ->call('save')
        ->assertSet('formError', 'A version already starts on 01 Nov 2026. Choose a later date.');
    expect(PayrollPayRule::query()->count())->toBe(2);
    $page->set('formEffectiveFrom', '2026-12-01')
        ->call('save')
        ->assertSet('formError', null);
    expect(PayrollPayRule::query()->where('effective_from', '2026-12-01')->sole()->params['overtime']['rate'])->toBe(1.5);

    // A superseded version can be shown in the form.
    $page->call('loadVersion', $saved->id)
        ->assertSet('loadedId', $saved->id)
        ->assertSet('formMatrix.ORDINARY.overtime', '125')
        ->assertSee('In the form')
        ->assertSee('Showing the upcoming version from')
        ->assertSee('01 Nov 2026');
});

it('requires a reason and a date for every version and refuses a scope that is not on the Employment list', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    prpageActor();
    $page = Livewire::test(PayRulesPage::class)
        ->assertSee('Rest day, overtime hour: 162.5% is below the usual minimum of 169%.')
        ->assertSee('Regular holiday, not worked: 0% is below the usual minimum of 100%.')
        ->set('formEffectiveFrom', '2026-11-01')
        ->call('save')
        ->assertSet('formError', 'Enter the reason for this version.');
    expect(PayrollPayRule::query()->count())->toBe(1);

    // A date inside a payroll period is accepted: that payroll prices each day by its version (DIVERGENCE 17).
    $page->set('formReason', 'Managerial staff')
        ->set('formEffectiveFrom', 'soon')
        ->call('save')
        ->assertSet('formError', 'Enter the date the rules take effect.');
    expect(PayrollPayRule::query()->count())->toBe(1);

    $page->set('formEffectiveFrom', '2026-11-10')
        ->call('save')
        ->assertSet('formError', null);
    expect(PayrollPayRule::query()->where('effective_from', '2026-11-10')->sole()->reason)->toBe('Managerial staff');

    // A scope that is not on the Employment list is ignored: the select stays where it was.
    $page->set('scope', 'not-a-status')
        ->assertSet('scope', PayrollPayRule::COMPANY);
    expect(PayrollPayRule::query()->count())->toBe(2);

    // Changing the scope reloads the form, so the bad cell is typed after it.
    $page->set('scope', PayrollPayRule::COMPANY)
        ->set('formMatrix.SPECIAL.worked', 'lots')
        ->set('formEffectiveFrom', '2026-12-01')
        ->set('formReason', 'x')
        ->call('save')
        ->assertSet('formError', 'Special non-working day, worked day: enter a percentage, or leave it blank.');
});

it('saves an override for one employment status that starts from the company rule', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    prpageActor();
    $contract = prpageStatus('Contract of Service');
    prpageStatus('Regular');

    $page = Livewire::test(PayRulesPage::class)
        ->assertSee('Contract of Service')
        ->assertDontSee('Contract of Service (override)')
        ->set('scope', $contract->id)
        ->assertSee('No override yet')
        ->assertSet('formMatrix.ORDINARY.overtime', '125')
        ->set('formMethod', PayRules::METHOD_DIVISOR)
        ->set('formDivisor', '22')
        ->set('formEffectiveFrom', '2026-11-01')
        ->set('formReason', 'Contractors')
        ->call('save')
        ->assertSet('formError', null)
        ->assertSee('Contract of Service (override)')
        ->assertDontSee('No override yet');

    $saved = PayrollPayRule::query()->where('scope', $contract->id)->sole();
    expect($saved->params['daily_rate'])->toEqual(['method' => PayRules::METHOD_DIVISOR, 'value' => 22.0])
        ->and($saved->reason)->toBe('Contractors')
        ->and(PayrollPayRule::query()->where('scope', PayrollPayRule::COMPANY)->count())->toBe(1);
    $page->set('scope', PayrollPayRule::COMPANY)
        ->assertSet('formMethod', PayRules::METHOD_MONTH);
});

it('saves the hours, rounding and seaport figures as typed and blank as the reference', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    prpageActor();
    $page = Livewire::test(PayRulesPage::class)
        ->assertSet('formHourlyHours', '')
        ->assertSet('formRounding', '')
        ->assertSet('formSeaportRate', '')
        ->assertSee('an hour on an 8-hour shift')
        ->set('formHourlyHours', '8')
        ->set('formRounding', '2')
        ->set('formSeaportRate', '150')
        ->set('formEffectiveFrom', '2026-11-01')
        ->set('formReason', 'Register figures')
        ->call('save')
        ->assertSet('formError', null);

    $saved = PayrollPayRule::query()->where('effective_from', '2026-11-01')->sole();
    expect($saved->params['hourly_rate'])->toEqual(['hours' => 8.0])
        ->and($saved->params['rounding'])->toEqual(['decimals' => 2])
        ->and($saved->params['overtime']['seaport_rate'])->toEqual(1.5);

    // Shown again, the figures read as typed; a bad figure is refused before the save.
    $page->call('loadVersion', $saved->id)
        ->assertSet('formHourlyHours', '8')
        ->assertSet('formRounding', '2')
        ->assertSet('formSeaportRate', '150')
        ->set('formHourlyHours', '0.5')
        ->assertSee('Hours for the hourly rate: enter between 1 and 24, or leave it blank.')
        ->set('formHourlyHours', '')
        ->set('formRounding', '')
        ->set('formSeaportRate', '')
        ->set('formEffectiveFrom', '2026-12-01')
        ->set('formReason', 'Back to the shift hours')
        ->call('save')
        ->assertSet('formError', null);
    $blank = PayrollPayRule::query()->where('effective_from', '2026-12-01')->sole();
    expect($blank->params['hourly_rate'])->toEqual(['hours' => null])
        ->and($blank->params['rounding'])->toEqual(['decimals' => null])
        ->and($blank->params['overtime']['seaport_rate'])->toBeNull();
});
