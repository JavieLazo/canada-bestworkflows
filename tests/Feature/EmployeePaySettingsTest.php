<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Records\EmployeePaySettingsPage;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollGovDeductionPolicy;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Application\Commands\UpdateEmployeePaySettings;
use App\Modules\Workforce\Application\EmployeePaySettingsInput;
use App\Modules\Workforce\Application\Queries\EmployeePaySettingsRecords;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Employee Pay Settings, under Records → Payroll Settings → Employees: who
 * is paid differently from the standard computation, listed from a rate of
 * their own on a percentage deduction (the approved settings version in
 * force), the hourly rate override, Pay Basis & Rates of their own and
 * recurring lines; a contribution or withholding setting stored on the
 * record sets nobody apart and is never worded. The page opens for PAYROLL, but the settings are
 * pay data: only an all-data PAYROLL holder reads or writes them. The
 * hourly rate is the one setting still edited in place, with a reason,
 * audited under RequiredAudit; nothing changed writes nothing. What is
 * saved is what the next draft computes with.
 */

/** @param list<string> $modules */
function epsActor(array $modules = ['PAYROLL'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Pay settings '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides employee_profiles columns over a 30,000-a-month regular employee */
function epsEmployee(string $lastName, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'EPS-'.Str::upper(Str::random(5)),
        'first_name' => 'Pat',
        'last_name' => $lastName,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
        'is_active' => true,
    ], $overrides))->save();

    return $employee->refresh();
}

/** An approved settings version giving the employee a rate of their own on the Provident Fund, a percentage deduction made on first use. */
function epsOwnRate(EmployeeProfile $employee, float $rate = 5.0): void
{
    $fund = PayrollDeduction::query()->where('name', 'Provident Fund')->first();
    if ($fund === null) {
        $fund = new PayrollDeduction;
        $fund->forceFill(['code' => '8', 'name' => 'Provident Fund', 'percent' => ['employee' => 3.0, 'employer' => 3.0, 'base' => 'MONTHLY', 'frequency' => 'EVERY', 'cap' => null, 'before_tax' => false]])->save();
    }
    $policy = new PayrollGovDeductionPolicy;
    $policy->forceFill(['employee_id' => $employee->id, 'effective_from' => '2025-01-01', 'reason' => 'Own rate', 'status' => PayrollGovDeductionPolicy::APPROVED, 'approved_at' => '2025-01-01 00:00:00.000',
        'params' => [...GovDeductionSettings::STANDARD, 'percent_rates' => [(string) $fund->id => $rate]]])->save();
}

/** 08:00-17:00 Manila on the ten weekdays of August 2026's first cut-off. */
function epsWorkedFirstHalf(EmployeeProfile $employee): void
{
    foreach (['03', '04', '05', '06', '07', '10', '11', '12', '13', '14'] as $day) {
        foreach ([['TIME_IN', '00:00:00'], ['TIME_OUT', '09:00:00']] as [$type, $at]) {
            AttendanceLog::query()->create([
                'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
                'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => "2026-08-{$day} {$at}",
            ]);
        }
    }
}

/* |---| The gate |---| */

it('opens for PAYROLL holders under Payroll Settings and shows the settings only to all-data PAYROLL', function () {
    $employee = epsEmployee('Gated', ['allow_sss' => false]);
    $show = '/records/payroll-settings/employees/'.$employee->id;

    $this->get('/records/payroll-settings/employees')->assertRedirect(route('login'));

    // Records Management alone no longer opens it: it is a Payroll Setting.
    foreach ([['PORTAL'], ['RECORDS'], ['EMPLOYEES', 'RECORDS']] as $modules) {
        epsActor($modules);
        $this->get('/records/payroll-settings/employees')->assertRedirect();
        $this->get($show)->assertRedirect();
    }
    // The former Records address is gated like the page: a Records holder is
    // sent to their landing page, a PAYROLL holder's bookmark forwards.
    $this->get('/records/employee-pay-settings/'.$employee->id)->assertRedirect('/employees');

    epsActor(['PAYROLL'], false);
    $this->get('/records/employee-pay-settings/'.$employee->id)->assertRedirect($show);
    $this->get('/records/payroll-settings/employees')->assertOk()->assertSee('shown to payroll officers')->assertDontSee('Gated');
    $this->get($show)->assertOk()->assertSee('shown to payroll officers')->assertDontSee('Gated');

    epsActor(['PAYROLL']);
    $this->get('/records/payroll-settings/employees')->assertOk()->assertSee('Gated');
    $this->get($show)->assertOk()->assertSee('Gated')->assertSee('Tax and Statutory Deductions');
    $this->get('/records/payroll-settings/employees/'.Str::ulid())->assertNotFound();
});

it('reads and writes nothing for an actor without all-data PAYROLL', function () {
    $employee = epsEmployee('Refused');
    $actor = epsActor(['EMPLOYEES', 'RECORDS']);

    expect(fn () => app(EmployeePaySettingsRecords::class)->list($actor))->toThrow(HttpException::class)
        ->and(fn () => app(UpdateEmployeePaySettings::class)->handle($actor, $employee->id, ['hourly_rate' => '200'], 'Because'))
        ->toThrow(RuntimeException::class, 'Your role cannot manage employee pay settings.')
        ->and((float) $employee->refresh()->hourly_rate)->toBe(0.0);
});

/* |---| The list |---| */

it('opens on every active employee and narrows to those whose settings differ', function () {
    epsEmployee('Plainpay');
    // Contribution and withholding settings stored on the record set nobody apart: nothing prices from them.
    epsEmployee('Storedonly', ['allow_sss' => false, 'pagibig_mode' => 'QUARTERLY', 'percent_tax' => 5]);
    epsOwnRate(epsEmployee('Ownrate'));
    epsEmployee('Hourly', ['hourly_rate' => 150]);
    epsOwnRate(epsEmployee('Gone', ['is_active' => false]));
    epsActor(['PAYROLL']);

    // The list opens on everyone; Only customized narrows it.
    Livewire::test(EmployeePaySettingsPage::class)
        ->assertSee('Plainpay')
        ->set('onlyCustomized', true)
        ->assertSee('Ownrate')->assertSee('Own rates')
        ->assertSee('Hourly')->assertSee('$150.00 an hour')
        ->assertDontSee('Plainpay')->assertDontSee('Storedonly')->assertDontSee('Gone')
        ->set('onlyCustomized', false)
        ->assertSee('Plainpay')->assertSee('Storedonly')->assertDontSee('Gone')
        ->set('activeOnly', false)
        ->assertSee('Gone')
        ->set('search', 'ownrate')
        ->assertSee('Ownrate')->assertDontSee('Plainpay');
});

it('opens an employee from anywhere on their row, not from a link on the name', function () {
    $employee = epsEmployee('Rowclick');
    epsActor(['PAYROLL']);
    $url = route('records.employee-pay-settings.show', ['employee' => $employee->id]);

    $html = Livewire::test(EmployeePaySettingsPage::class)->html();
    preg_match('/<tr[^>]*wire:key="row-'.$employee->id.'"[^>]*>/s', $html, $row);
    $tag = $row[0] ?? '';

    // The row is the one control: it carries the address, takes focus, and answers a click, Enter and Space.
    expect($tag)->toContain('data-href="'.$url.'"')
        ->and($tag)->toContain('tabindex="0"')
        ->and($tag)->toContain('aria-label="Open Pat Rowclick')
        ->and($tag)->toContain('x-on:click=')
        ->and($tag)->toContain('x-on:keydown.enter.self.prevent=')
        ->and($tag)->toContain('x-on:keydown.space.self.prevent=')
        ->and($tag)->toContain('Livewire.navigate($el.dataset.href)')
        // The address is the row's alone: no link to it anywhere on the page.
        ->and(preg_match('/<a\s[^>]*'.preg_quote($url, '/').'/', $html))->toBe(0);
});

it('shows an employee as the Employee Records list does: portrait or initials, name over position, status as pills', function () {
    $employee = epsEmployee('Shown', ['employee_id_no' => '2026-0042', 'position' => 'Accountant II', 'employment_status' => 'Regular']);
    $pictured = epsEmployee('Pictured', ['photo_path' => 'portraits/pictured.jpg', 'employment_status' => 'Job Order', 'is_active' => false]);
    epsOwnRate($pictured);
    epsActor(['PAYROLL']);

    $html = Livewire::test(EmployeePaySettingsPage::class)->set('activeOnly', false)->html();
    $cells = static function (string $id) use ($html): string {
        preg_match('/<tr[^>]*wire:key="row-'.$id.'"[^>]*>(.*?)<\/tr>/s', $html, $row);

        return preg_replace('/\s+/', ' ', $row[1] ?? '') ?? '';
    };

    // The avatar with the name over the position: neither the row id nor the Employee ID is shown, and no
    // Custom pill marks a row whose own settings already read in bold.
    $shown = $cells($employee->id);
    expect($shown)->toContain('<span class="num text-xs font-semibold">PS</span>')
        ->and($shown)->toContain('<p class="truncate font-medium">Pat Shown</p>')
        ->and($shown)->toContain('<p class="truncate text-xs text-muted-foreground">Accountant II</p>')
        ->and($shown)->not->toContain($employee->employee_code)
        ->and($shown)->not->toContain('2026-0042')
        ->and($shown)->toContain('pill pill-success')
        ->and($shown)->not->toContain('Inactive');

    // A portrait in place of the initials; no position reads as a dash; not Regular and inactive as their own
    // pills; a setting of their own (a rate on a percentage deduction) reads in bold, with no Custom pill.
    $other = $cells($pictured->id);
    expect($other)->toContain('alt="Pat Pictured"')
        ->and($other)->toContain('portraits/pictured.jpg')
        ->and($other)->not->toContain('>PP</span>')
        ->and($other)->toContain('<p class="truncate text-xs text-muted-foreground">—</p>')
        ->and($other)->toContain('font-semibold')
        ->and($other)->not->toContain('Custom')
        ->and($other)->toContain('pill pill-info')
        ->and($other)->toContain('<span class="pill pill-muted">Inactive</span>');
});

it('counts the listed employees above the table by what is their own, whatever Only customized shows', function () {
    epsEmployee('Plainpay');
    epsOwnRate(epsEmployee('Ownrate'));
    epsOwnRate(epsEmployee('Nonmember'), 0.0);
    epsEmployee('Hourly', ['hourly_rate' => 150]);
    epsOwnRate(epsEmployee('Gone', ['is_active' => false]));
    epsActor(['PAYROLL']);

    $figure = static function (string $html, string $stat): ?string {
        return preg_match('/data-stat="'.$stat.'".*?<dd[^>]*>\s*<span[^>]*>([^<]*)<\/span>/s', $html, $m) === 1 ? trim($m[1]) : null;
    };

    $page = Livewire::test(EmployeePaySettingsPage::class);
    $html = $page->html();
    expect($figure($html, 'listed'))->toBe('4')
        ->and($figure($html, 'customized'))->toBe('3')
        ->and($figure($html, 'gov'))->toBe('2')
        ->and($figure($html, 'rates'))->toBe('1')
        ->and($figure($html, 'recurring'))->toBe('0');

    // Only customized narrows the table, never the figures above it.
    $html = $page->set('onlyCustomized', true)->assertDontSee('Plainpay')->html();
    expect($figure($html, 'listed'))->toBe('4')
        ->and($figure($html, 'customized'))->toBe('3');

    // Active only and the search change who is counted.
    $html = $page->set('onlyCustomized', false)->set('activeOnly', false)->html();
    expect($figure($html, 'listed'))->toBe('5')
        ->and($figure($html, 'gov'))->toBe('3');
    expect($figure($page->set('search', 'hourly')->html(), 'listed'))->toBe('1');

    // The figures are pay data too: a holder without all data gets none.
    epsActor(['PAYROLL'], false);
    app()->forgetInstance(Access::class);
    Livewire::test(EmployeePaySettingsPage::class)
        ->assertSee('shown to payroll officers')
        ->assertDontSeeHtml('data-pay-settings-summary');
});

it('reads the percentage deductions in one cell, Listed rates or Own rates, and names no contribution or withholding setting', function () {
    epsEmployee('Plainpay');
    // Stored contribution and withholding settings are never worded.
    epsOwnRate(epsEmployee('Ownrate', ['allow_sss' => false, 'percent_tax' => 5]));
    epsActor(['PAYROLL']);

    $html = Livewire::test(EmployeePaySettingsPage::class)->html();
    $cell = static function (string $name) use ($html): string {
        preg_match('/<tr[^>]*aria-label="Open Pat '.$name.'[^>]*>(.*?)<\/tr>/s', $html, $row);
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row[1] ?? '', $cells);

        return trim(strip_tags($cells[1][2] ?? ''));
    };

    expect($html)->toContain('<th>Percentage Deductions</th>')
        ->and($cell('Plainpay'))->toBe('Listed rates')
        ->and($cell('Ownrate'))->toBe('Own rates')
        ->and($html)->not->toContain('Contributions &amp; Tax')
        ->and($html)->not->toContain('SSS')
        ->and($html)->not->toContain('Withholding')
        ->and($html)->not->toContain('PhilHealth')
        ->and($html)->not->toContain('taxable compensation');
});

/* |---| The hourly rate |---| */

it('saves the hourly rate override from the page with a reason, audited, and refuses what it cannot read', function () {
    $employee = epsEmployee('Saver');
    epsActor(['PAYROLL']);

    Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->call('startEditHourly')
        ->assertSet('hourlyEditing', true)
        ->assertSet('editing', false)
        ->set('hourlyRate', '-1')
        ->set('hourlyReason', 'Contract rate')
        ->call('saveHourly')
        ->assertSet('hourlyEditing', true)
        ->assertSet('hourlyError', 'Hourly rate override: enter an amount up to $99,999,999.00, or leave it blank to use the shift.')
        ->set('hourlyRate', '200')
        ->set('hourlyReason', '')
        ->call('saveHourly')
        ->assertSet('hourlyError', 'Enter the reason for this change.')
        ->set('hourlyReason', 'Contract rate, per HR memo 12')
        ->call('saveHourly')
        ->assertSet('hourlyEditing', false)
        ->assertSet('hourlyError', null)
        ->assertSee('$200.00 an hour');

    expect((float) $employee->refresh()->hourly_rate)->toBe(200.0);
    $audit = AuditLog::query()->where('action', AuditEvent::PAY_SETTINGS_UPDATED->value)->sole();
    expect($audit->entity_id)->toBe($employee->id)
        ->and($audit->details)->toBe('Pat Saver — Hourly rate override changed (Contract rate, per HR memo 12)');
});

it('writes nothing and no audit row when nothing changed, reason or not', function () {
    $employee = epsEmployee('Unchanged', ['hourly_rate' => 150]);
    $actor = epsActor(['PAYROLL']);
    $updated = $employee->updated_at;

    expect(app(UpdateEmployeePaySettings::class)->handle($actor, $employee->id, EmployeePaySettingsInput::toForm($employee), ''))->toBe('Nothing changed.')
        ->and(app(UpdateEmployeePaySettings::class)->handle($actor, $employee->id, ['hourly_rate' => '150.00'], ''))->toBe('Nothing changed.')
        ->and($employee->refresh()->updated_at?->equalTo($updated))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_SETTINGS_UPDATED->value)->exists())->toBeFalse();
});

/* |---| What payroll computes with |---| */

it('is what the next draft computes with, and asks for a fresh review', function () {
    $employee = epsEmployee('Payroll');
    epsWorkedFirstHalf($employee);
    $actor = epsActor(['PAYROLL']);
    $compute = static fn (): array => PayrollData::calculateDrafts([$employee->id], '2026-08-01', '2026-08-15', [], [], [], false, $actor);

    $standard = $compute();
    app(UpdateEmployeePaySettings::class)->handle($actor, $employee->id, ['hourly_rate' => '500'], 'Contract rate');
    $custom = $compute();
    expect($custom['inputs']?->employees[0]->hourly_rate)->toBe(500.0)
        ->and($custom['inputs']?->fingerprint())->not->toBe($standard['inputs']?->fingerprint());
});

it('links each draft line to the employee\'s pay settings for an all-data payroll officer', function () {
    $employee = epsEmployee('Linked');
    epsWorkedFirstHalf($employee);
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-EPS', 'name' => 'Pay settings link', 'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT'])->save();
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0, 'period_start' => '2026-08-01', 'period_end' => '2026-08-15']);
    epsActor(['PAYROLL']);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSee('Pay settings for Pat Linked')
        ->assertSee(route('records.employee-pay-settings.show', ['employee' => $employee->id]), false);
});
