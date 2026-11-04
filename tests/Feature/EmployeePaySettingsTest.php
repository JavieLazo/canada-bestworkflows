<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Records\EmployeePaySettingsPage;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Workforce\Application\Commands\UpdateEmployeePaySettings;
use App\Modules\Workforce\Application\EmployeePaySettingsInput;
use App\Modules\Workforce\Application\Queries\EmployeePaySettingsRecords;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Employee Pay Settings, under Payroll → Payroll Settings → Employees: who
 * is paid differently from the standard computation, listed from the
 * Contributions & tax settings in force (the approved version, else the
 * record's baseline columns), the hourly rate override, rates of their own
 * and recurring lines. The page opens for PAYROLL, but the settings are
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
    $show = '/payroll/settings/employees/'.$employee->id;

    $this->get('/payroll/settings/employees')->assertRedirect(route('login'));

    // Records Management alone no longer opens it: it is a Payroll Setting.
    foreach ([['PORTAL'], ['RECORDS'], ['EMPLOYEES', 'RECORDS']] as $modules) {
        epsActor($modules);
        $this->get('/payroll/settings/employees')->assertRedirect();
        $this->get($show)->assertRedirect();
    }
    // The former Records address is gated like the page: a Records holder is
    // sent to their landing page, a PAYROLL holder's bookmark forwards.
    $this->get('/records/employee-pay-settings/'.$employee->id)->assertRedirect('/employees');

    epsActor(['PAYROLL'], false);
    $this->get('/records/employee-pay-settings/'.$employee->id)->assertRedirect($show);
    $this->get('/payroll/settings/employees')->assertOk()->assertSee('shown to payroll officers')->assertDontSee('Gated');
    $this->get($show)->assertOk()->assertSee('shown to payroll officers')->assertDontSee('Gated');

    epsActor(['PAYROLL']);
    $this->get('/payroll/settings/employees')->assertOk()->assertSee('Gated');
    $this->get($show)->assertOk()->assertSee('Gated')->assertSee('Contributions &amp; tax', false);
    $this->get('/payroll/settings/employees/'.Str::ulid())->assertNotFound();
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

it('lists the active employees whose settings differ, and everyone on request', function () {
    epsEmployee('Standard');
    epsEmployee('Legacymode', ['sss_mode' => null, 'philhealth_mode' => null, 'pagibig_mode' => null]);
    epsEmployee('Nosss', ['allow_sss' => false]);
    epsEmployee('Quarterly', ['pagibig_mode' => 'QUARTERLY']);
    epsEmployee('Percent', ['percent_tax' => 5]);
    epsEmployee('Hourly', ['hourly_rate' => 150]);
    epsEmployee('Gone', ['allow_philhealth' => false, 'is_active' => false]);
    epsActor(['PAYROLL']);

    Livewire::test(EmployeePaySettingsPage::class)
        ->assertSee('Nosss')->assertSee('Off')
        ->assertSee('Quarterly')->assertSee('Table, whole month on the second cut-off')
        ->assertSee('Percent')->assertSee('5% of taxable pay')
        ->assertSee('Hourly')->assertSee('₱150.00 an hour')
        ->assertDontSee('Standard')->assertDontSee('Legacymode')->assertDontSee('Gone')
        ->set('onlyCustomized', false)
        ->assertSee('Standard')->assertSee('Legacymode')->assertDontSee('Gone')
        ->set('activeOnly', false)
        ->assertSee('Gone')
        ->set('search', 'nosss')
        ->assertSee('Nosss')->assertDontSee('Standard');
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
        ->assertSet('hourlyError', 'Hourly rate override: enter an amount up to ₱99,999,999.00, or leave it blank to use the shift.')
        ->set('hourlyRate', '200')
        ->set('hourlyReason', '')
        ->call('saveHourly')
        ->assertSet('hourlyError', 'Enter the reason for this change.')
        ->set('hourlyReason', 'Contract rate, per HR memo 12')
        ->call('saveHourly')
        ->assertSet('hourlyEditing', false)
        ->assertSet('hourlyError', null)
        ->assertSee('₱200.00 an hour');

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
