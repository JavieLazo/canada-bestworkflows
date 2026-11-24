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
    $this->get('/records/payroll-settings/pay-rates')->assertRedirect(route('login'));

    prpageActor(false);
    $this->get('/records/payroll-settings/pay-rates')
        ->assertOk()
        ->assertSee('Pay Basis &amp; Rates', false)
        ->assertSee('Rates by Day Type')
        ->assertSee('Ordinary workday')
        ->assertSee('Double holiday on a rest day')
        // One set of rules: no version list, no date and no reason to give.
        ->assertDontSee('Versions')->assertDontSee('Effective From')->assertDontSee('Superseded')
        ->assertSee('Rest day, overtime hour: 162.5% is below the usual minimum of 169%.')
        ->assertSee('Your role can view these rules.')
        ->assertDontSee('Applies to all draft payrolls now.')
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
        ->assertSee('$1,363.64');
    $page->call('applyPreset', PayRules::PRESET_STANDARD)
        ->assertSet('formBasis', PayRules::BASIS_DAILY)
        ->assertSet('formHolidayNeedsAttendance', true)
        ->assertSet('formMatrix.REGULAR.unworked', '100')
        ->assertSet('formMatrix.REGULAR.worked', '');
    expect(PayrollPayRule::query()->count())->toBe(1);
});

it('replaces the company rules in place, keeping a percentage shown unchanged as its stored fraction', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    prpageActor();
    $seeded = PayrollPayRule::query()->sole();

    $page = Livewire::test(PayRulesPage::class)
        ->assertSee('Applies to all draft payrolls now.')
        ->set('formMatrix.REST.overtime', '169')
        ->set('formMatrix.REST.night', '143')
        ->set('formMatrix.REGULAR.unworked', '100')
        ->set('formMatrix.DOUBLE.unworked', '200')
        ->assertDontSee('is below the usual minimum')
        ->assertSee('Unsaved changes')
        ->call('save')
        ->assertSet('formError', null)
        ->assertDontSee('Unsaved changes');

    // The same row carries the new rules from the date it always covered: nothing is added beside it.
    $saved = PayrollPayRule::query()->sole();
    expect($saved->id)->toBe($seeded->id)
        ->and($saved->scope)->toBe(PayrollPayRule::COMPANY)
        ->and($saved->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($saved->status)->toBe(PayrollPayRule::APPROVED)
        ->and($saved->params['overtime']['rate'])->toBe(1.25)
        ->and($saved->params['night'])->toEqual(['rate' => 0.1, 'max_hours' => 8.0, 'start' => 1320, 'end' => 1800, 'on_overtime' => false])
        ->and($saved->params['rest_day']['rate'])->toBe(1.3)
        ->and($saved->params['holiday_overtime_factor'])->toBe(1.3)
        ->and($saved->params['matrix']['REST'])->toEqual(['worked' => null, 'overtime' => 169.0, 'night' => 143.0, 'unworked' => 0.0])
        ->and($saved->params['matrix']['REGULAR']['unworked'])->toEqual(100)
        ->and($saved->params['matrix']['ORDINARY'])->toEqual(['worked' => null, 'overtime' => null, 'night' => null, 'unworked' => 0.0])
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_RULES_SAVED->value)->where('entity_id', $saved->id)
            ->where('details', 'Pay Basis & Rates replaced for the company')->exists())->toBeTrue();

    // The form shows what was saved; a changed base rate is converted once on the next save.
    $page->assertSet('formMatrix.REST.overtime', '169')
        ->set('formMatrix.ORDINARY.overtime', '150')
        ->call('save')
        ->assertSet('formError', null);
    expect(PayrollPayRule::query()->sole()->params['overtime']['rate'])->toBe(1.5)
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_RULES_SAVED->value)->count())->toBe(2);
});
it('asks for no reason or date, refuses a bad figure and ignores a scope that is not on the Employment list', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    prpageActor();
    $page = Livewire::test(PayRulesPage::class)
        ->assertSee('Rest day, overtime hour: 162.5% is below the usual minimum of 169%.')
        ->assertSee('Regular holiday, not worked: 0% is below the usual minimum of 100%.')
        // A rate below the usual minimum is a warning, never a refusal, and no reason is asked.
        ->call('save')
        ->assertSet('formError', null);
    expect(PayrollPayRule::query()->count())->toBe(1);

    // A scope that is not on the Employment list is ignored: the select stays where it was.
    $page->set('scope', 'not-a-status')
        ->assertSet('scope', PayrollPayRule::COMPANY);

    $page->set('formMatrix.SPECIAL.worked', 'lots')
        ->call('save')
        ->assertSet('formError', 'Special non-working day, worked day: enter a percentage, or leave it blank.');
    expect(PayrollPayRule::query()->count())->toBe(1);
});
it('marks a rate below the usual minimum as a warning, and only an unreadable cell as an error', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    prpageActor();
    $input = static function (string $html, string $cell): string {
        preg_match('/<input[^>]*formMatrix\.'.preg_quote($cell, '/').'"[^>]*>/', $html, $match);

        return $match[0] ?? '';
    };

    $page = Livewire::test(PayRulesPage::class);
    $low = $input($page->html(), 'REST.overtime');
    expect($low)->toContain('border-warning!')->toContain('aria-invalid="false"')
        ->and($low)->not->toContain('border-danger!')
        // Editable cells read as editable: the card's own fill, never the page's grey.
        ->and($low)->toContain('bg-card')
        ->and($low)->not->toContain('bg-background');

    $page->set('formMatrix.SPECIAL.worked', 'lots')->call('save');
    $bad = $input($page->html(), 'SPECIAL.worked');
    expect($bad)->toContain('border-danger!')->toContain('aria-invalid="true"');
});

it('names whose rules the form holds, explains the cell borders, and lists the warnings above the table', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    prpageActor();
    $status = prpageStatus('Job Order');

    $page = Livewire::test(PayRulesPage::class)
        ->assertSee('Editing:')
        ->assertSee('the company rule.')
        ->assertSee('Base rate: the other cells are worked out from it')
        ->assertSee('Daily Limits')
        ->assertSee('Other Rates and Checks');
    $html = $page->html();
    // A warning names cells of the table, so it is read with them in view.
    expect(strpos($html, 'data-rates-legend'))->toBeLessThan((int) strpos($html, 'is below the usual minimum'))
        ->and(strpos($html, 'is below the usual minimum'))->toBeLessThan((int) strpos($html, '<table class="hris-table">'));

    // ONE frame holds the form: six parts, each under a band naming it, in the order a rule is worked out.
    $at = static fn (string $name): int => (int) strpos($html, '>'.$name.'</h2>');
    $parts = [$at('Pay Basis'), $at('Daily Rate'), $at('Night Work'), $at('Rates by Day Type'), $at('Daily Limits'), $at('Other Rates and Checks')];
    $sorted = $parts;
    sort($sorted);
    expect(substr_count($html, 'data-rates-form'))->toBe(1)
        ->and(substr_count($html, 'data-rates-part'))->toBe(6)
        ->and($parts[0])->toBeGreaterThan((int) strpos($html, 'data-rates-form'))
        ->and($parts)->toBe($sorted)
        ->and(count(array_unique($parts)))->toBe(6);

    $page->set('scope', $status->id)
        ->assertSee('the Job Order override.')
        ->assertDontSee('the company rule.');
});

it('says a saved change applies at once, with no effective date, and keeps Save in view', function () {
    prpageActor();

    Livewire::test(PayRulesPage::class)
        ->assertDontSee('effective date')
        ->assertSee('Applies to all draft payrolls now.')
        ->assertSeeHtml('sticky bottom-4')
        ->assertSeeHtml('data-rates-save');
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
        ->assertSee('This status follows the company rule')
        ->assertSet('formMatrix.ORDINARY.overtime', '125')
        ->set('formMethod', PayRules::METHOD_DIVISOR)
        ->set('formDivisor', '22')
        ->call('save')
        ->assertSet('formError', null)
        ->assertSee('Contract of Service (override)')
        ->assertDontSee('This status follows the company rule');

    // The status's rules cover every date the company's do, so every draft period reads them.
    $saved = PayrollPayRule::query()->where('scope', $contract->id)->sole();
    expect($saved->params['daily_rate'])->toEqual(['method' => PayRules::METHOD_DIVISOR, 'value' => 22.0])
        ->and($saved->effective_from->format('Y-m-d'))->toBe('2025-01-01')
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
        ->call('save')
        ->assertSet('formError', null);

    $saved = PayrollPayRule::query()->sole();
    expect($saved->params['hourly_rate'])->toEqual(['hours' => 8.0])
        ->and($saved->params['rounding'])->toEqual(['decimals' => 2])
        ->and($saved->params['overtime']['seaport_rate'])->toEqual(1.5);

    // Shown again, the figures read as typed; a bad figure is refused before the save.
    $page->assertSet('formHourlyHours', '8')
        ->assertSet('formRounding', '2')
        ->assertSet('formSeaportRate', '150')
        ->set('formHourlyHours', '0.5')
        ->assertSee('Hours for the hourly rate: enter between 1 and 24, or leave it blank.')
        ->set('formHourlyHours', '')
        ->set('formRounding', '')
        ->set('formSeaportRate', '')
        ->call('save')
        ->assertSet('formError', null);
    $blank = PayrollPayRule::query()->sole();
    expect($blank->params['hourly_rate'])->toEqual(['hours' => null])
        ->and($blank->params['rounding'])->toEqual(['decimals' => null])
        ->and($blank->params['overtime']['seaport_rate'])->toBeNull();
});
