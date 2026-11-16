<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Records\SignatoriesPage;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollSignatory;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SaveSignatory;
use App\Modules\Payroll\Application\Queries\SignatoryRecords;
use App\Modules\Payroll\Domain\Signatories\SignatorySlot;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The signatories printed on payroll documents: three fixed slots (Prepared
 * by, Checked by, Certified correct by) whose names and designations whoever
 * manages the Payroll Settings sets, audited, and which every payslip surface
 * and the payroll register print as they stand — a blank slot as an empty
 * line. Nothing here approves a payroll.
 */
function psigActor(bool $allData = true, ?EmployeeProfile $employee = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Signatories '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ([ModuleCode::PAYROLL, ModuleCode::PORTAL, ModuleCode::REPORTS] as $module) {
        (new RoleModuleGrant)->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/**
 * A posted payslip for the signed-in actor's own employee, so every surface can show it.
 *
 * @return array{user: User, employee: EmployeeProfile, run: PayrollRun, payslip: Payslip}
 */
function psigPostedPayslip(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'PSIG-001', 'first_name' => 'Signed', 'last_name' => 'Statement',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 20000])->save();
    $user = psigActor(true, $employee);
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-PSIG', 'name' => 'Signed payroll', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        'status' => PayrollRunStatus::FINALIZED, 'kind' => PayrollRunKind::REGULAR])->save();
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => $employee->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        'daily_rate' => 909.09, 'work_days' => 10, 'work_days_pay' => 9090.9, 'basic_pay' => 9090.9, 'total_base_pay' => 9090.9,
        'gross_pay' => 9090.9, 'sss' => 0, 'philhealth' => 0, 'pagibig' => 0, 'withholding_tax' => 0, 'total_deductions' => 0, 'net_pay' => 9090.9])->save();

    return ['user' => $user, 'employee' => $employee, 'run' => $run, 'payslip' => $payslip];
}

function psigText(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/* |---| The migration |---| */

it('creates the signatories table once and resumes from a partial run', function () {
    $m = require base_path('database/migrations/2026_11_02_0001_create_payroll_signatories_table.php');
    $m->up();
    expect(Schema::hasTable('payroll_signatories'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_signatories', 'psig_slot_unq'))->toBeTrue();
    $m->down();
    expect(Schema::hasTable('payroll_signatories'))->toBeFalse();
    $m->up();
    $m->up();
    expect(Schema::hasTable('payroll_signatories'))->toBeTrue()
        ->and(Schema::hasColumns('payroll_signatories', ['slot', 'name', 'designation', 'updated_by', 'updated_by_name']))->toBeTrue();
});

/* |---| The command |---| */

it('sets a slot only for a PAYROLL all-data holder, validates it, audits a change and skips a no-op', function () {
    $reader = Access::forActor(psigActor(false));
    expect(fn () => app(SaveSignatory::class)->handle($reader, SignatorySlot::PREPARED, 'Someone', 'Finance Analyst'))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(PayrollSignatory::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);

    $access = Access::forActor(psigActor());
    $save = fn (string $slot, string $name, string $designation): bool => app(SaveSignatory::class)->handle($access, $slot, $name, $designation);
    expect(fn () => $save('APPROVED', 'Someone', ''))->toThrow(RuntimeException::class, 'Choose which signature this is.');
    expect(fn () => $save(SignatorySlot::PREPARED, str_repeat('x', 201), ''))->toThrow(RuntimeException::class, 'A name or designation must be at most 200 characters.');
    // Clearing a slot nobody set changes nothing.
    expect($save(SignatorySlot::CHECKED, '', ''))->toBeFalse()
        ->and(PayrollSignatory::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    expect($save(SignatorySlot::PREPARED, ' Pat Analyst ', ' Finance Analyst '))->toBeTrue();
    $row = PayrollSignatory::query()->where('slot', SignatorySlot::PREPARED)->sole();
    expect($row->name)->toBe('Pat Analyst')
        ->and($row->designation)->toBe('Finance Analyst')
        ->and($row->updated_by)->toBe((int) $access->userId)
        ->and($row->updated_by_name)->toBe($access->fullName);
    $audit = AuditLog::query()->where('action', 'SIGNATORY_SET')->sole();
    expect($audit->entity_id)->toBe(SignatorySlot::PREPARED)
        ->and($audit->details)->toBe('Prepared by: Pat Analyst, Finance Analyst');

    // The same values again: no write, no audit row.
    expect($save(SignatorySlot::PREPARED, 'Pat Analyst', 'Finance Analyst'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'SIGNATORY_SET')->count())->toBe(1);
    // Cleared: the row stays, empty, and the audit says so.
    expect($save(SignatorySlot::PREPARED, '', ''))->toBeTrue()
        ->and($row->fresh()->name)->toBeNull()
        ->and(AuditLog::query()->where('action', 'SIGNATORY_SET')->latest('id')->first()?->details)->toBe('Prepared by: cleared');

    $views = app(SignatoryRecords::class)->all($access);
    expect(array_map(static fn ($v) => $v->slot, $views))->toBe(SignatorySlot::all())
        ->and($views[0]->label)->toBe('Prepared by')
        ->and($views[0]->name)->toBe('')
        ->and($views[2]->label)->toBe('Certified correct by');
});

/* |---| The page |---| */

it('saves the three slots from the page for a manager and shows them read-only to everyone else', function () {
    psigActor();
    $this->get('/records/payroll-settings/signatories')->assertOk()->assertSee('Signatories')->assertSee('Save Signatories');
    Livewire::test(SignatoriesPage::class)
        ->set('form.PREPARED.name', 'Pat Analyst')->set('form.PREPARED.designation', 'Finance Analyst')
        ->set('form.CHECKED.name', 'Chris Accountant')->set('form.CHECKED.designation', 'Accountant')
        ->set('form.CERTIFIED.name', 'Sam Manager')->set('form.CERTIFIED.designation', 'Finance Manager')
        ->call('save')
        ->assertSet('form.CERTIFIED.designation', 'Finance Manager');
    expect(PayrollSignatory::query()->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'SIGNATORY_SET')->count())->toBe(3);

    psigActor(false);
    $this->get('/records/payroll-settings/signatories')->assertOk()
        ->assertSee('Chris Accountant')->assertSee('Changing them requires Payroll access to all data.')->assertDontSee('Save Signatories');
    Livewire::test(SignatoriesPage::class)->set('form.PREPARED.name', 'Nobody')->call('save');
    expect(PayrollSignatory::query()->where('slot', SignatorySlot::PREPARED)->sole()->name)->toBe('Pat Analyst')
        ->and(AuditLog::query()->where('action', 'SIGNATORY_SET')->count())->toBe(3);
});

/* |---| The documents |---| */

it('prints the signatories at the foot of every payslip surface and of the payroll register, blank slots as empty lines', function () {
    ['payslip' => $payslip, 'run' => $run] = psigPostedPayslip();
    $access = app(Access::class);
    app(SaveSignatory::class)->handle($access, SignatorySlot::PREPARED, 'Pat Analyst', 'Finance Analyst');
    app(SaveSignatory::class)->handle($access, SignatorySlot::CERTIFIED, 'Sam Manager', 'Finance Manager');

    $surfaces = [
        'Payroll' => Livewire::test(IndividualPayslip::class, ['id' => $run->id, 'employeeId' => $payslip->employee_id])->html(),
        'Portal' => Livewire::test(PortalPage::class)->set('tab', 'payslips')->call('viewPayslip', $payslip->id)->html(),
        'Reports payslips' => Livewire::test(ReportsIndex::class)->call('selectReport', 'payslips')->set('from', '2026-09-01')->set('to', '2026-09-15')->call('generate')->assertHasNoErrors()->html(),
        'Reports register' => Livewire::test(ReportsIndex::class)->call('selectReport', 'payroll-register')->set('from', '2026-09-01')->set('to', '2026-09-15')->call('generate')->assertHasNoErrors()->html(),
    ];
    foreach ($surfaces as $surface => $html) {
        $text = psigText($html);
        // Each surface: the two set slots with name then designation, and the blank slot's label with nothing under it.
        expect($text)->toContain('Prepared by: Pat Analyst Finance Analyst')
            ->toContain('Checked by: Certified correct by: Sam Manager Finance Manager')
            ->and(substr_count($html, 'data-signatory="CHECKED"'))->toBeGreaterThanOrEqual(1, $surface);
    }
});
