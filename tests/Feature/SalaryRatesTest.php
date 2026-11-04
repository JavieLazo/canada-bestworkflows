<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryRate;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Workforce\Application\Commands\RecordSalaryChange;
use App\Modules\Workforce\Application\Commands\ReviewSalaryChange;
use App\Modules\Workforce\Application\SalaryRates;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * DELIBERATE DIVERGENCE 18, dated salary: a change recorded for today or
 * later is approved as recorded and becomes the record's rates on its date
 * (at once, or through `salary:apply-due`); one dated in the past waits
 * for review; a record edit of the rates becomes a version
 * dated today; Employment History carries the change on its effective
 * date; payroll captures the versions taking over inside the period and
 * prices each day by the salary in force on it, replaying the evidence
 * never the live table. The migration seeds one version per employee.
 */

/** @param list<string> $modules */
function salrActor(array $modules = ['PAYROLL'], bool $allData = true, ?string $employeeId = null, string $name = 'Pay Officer'): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Salary '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['name' => $name, 'role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** @param array<string, mixed> $overrides */
function salrEmployee(array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'ESR-'.Str::upper(Str::random(5)), 'first_name' => 'Nora', 'last_name' => 'Salcedo',
        'employment_status' => 'Regular', 'date_hired' => '2026-06-01', 'monthly_rate' => 22000, 'is_active' => true,
    ], $overrides))->save();
    $shift = new Shift;
    $shift->forceFill([
        'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 5,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $employee->refresh();
}

function salrWorked(EmployeeProfile $employee, string $date): void
{
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => '17:00'] as $type => $at) {
        $punch = new AttendanceLog;
        $punch->forceFill([
            'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
            'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant("{$date}T{$at}:00")->utc(),
        ])->save();
    }
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-20T10:00:00+08:00'));
});

/*
|--------------------------------------------------------------------------
| The migration's seed
|--------------------------------------------------------------------------
*/

it('seeds one approved version from the hire date for an employee with a rate, once', function () {
    $employee = salrEmployee();
    $rateless = salrEmployee(['monthly_rate' => 0, 'daily_rate' => null]);
    $migration = require base_path('database/migrations/2026_11_02_0008_create_employee_salary_rates_table.php');
    $migration->up();
    $migration->up();

    $seeded = EmployeeSalaryRate::query()->where('employee_id', $employee->id)->get();
    expect($seeded)->toHaveCount(1)
        ->and($seeded[0]->effective_from->format('Y-m-d'))->toBe('2026-06-01')
        ->and($seeded[0]->monthly_rate)->toBe(22000.0)
        ->and($seeded[0]->status)->toBe(EmployeeSalaryRate::APPROVED)
        ->and(EmployeeSalaryRate::query()->where('employee_id', $rateless->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Recording a change
|--------------------------------------------------------------------------
*/

it('applies a change dated today at once, writes the history on its date and audits without the amount', function () {
    $employee = salrEmployee();
    $actor = salrActor();

    $message = app(RecordSalaryChange::class)->handle($actor, $employee->id, '2026-09-20', '24,200', null, 'Step increment', 'Memo 2026-14');
    // The record's rates before the change become the first version, from the hire date.
    $row = EmployeeSalaryRate::query()->where('employee_id', $employee->id)->where('effective_from', '2026-09-20')->sole();
    $baseline = EmployeeSalaryRate::query()->where('employee_id', $employee->id)->where('effective_from', '2026-06-01')->sole();
    expect($message)->toBe('Salary change recorded for Nora Salcedo, in force from 20 Sep 2026.')
        ->and($baseline->monthly_rate)->toBe(22000.0)
        ->and($baseline->reason)->toBe('Rates as recorded')
        ->and($employee->refresh()->monthly_rate)->toBe(24200.0)
        ->and($row->status)->toBe(EmployeeSalaryRate::APPROVED)
        ->and($row->approved_by)->toBe((int) $actor->userId)
        ->and($row->reference)->toBe('Memo 2026-14');
    $history = EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->sole();
    expect($history->effective_date->format('Y-m-d'))->toBe('2026-09-20')
        ->and($history->changed_fields)->toBe('monthly_rate')
        ->and($history->monthly_rate)->toBe(24200.0);
    $audit = AuditLog::query()->where('action', AuditEvent::SALARY_CHANGE_RECORDED->value)->sole();
    expect($audit->details)->toContain('Nora Salcedo · salary change from 20 Sep 2026 recorded (Step increment) · Memo 2026-14')
        ->and($audit->details)->not->toContain('24');

    // The next change must follow it; a blank pair, a bad date and a missing reason are refused.
    expect(fn () => app(RecordSalaryChange::class)->handle($actor, $employee->id, '2026-09-20', '25000', null, 'Again'))
        ->toThrow(RuntimeException::class, 'A salary version already takes effect on 20 Sep 2026.')
        ->and(fn () => app(RecordSalaryChange::class)->handle($actor, $employee->id, '2026-10-01', null, null, 'Blank'))
        ->toThrow(RuntimeException::class, 'Enter a Rate Per Month or a Rate Per Day.')
        ->and(fn () => app(RecordSalaryChange::class)->handle($actor, $employee->id, 'soon', '25000', null, 'When'))
        ->toThrow(RuntimeException::class, 'Enter the date the salary takes effect.')
        ->and(fn () => app(RecordSalaryChange::class)->handle($actor, $employee->id, '2026-10-01', '25000', null, ' '))
        ->toThrow(RuntimeException::class, 'Enter the reason for the salary change.')
        ->and(fn () => app(RecordSalaryChange::class)->handle($actor, $employee->id, '2026-10-01', '-5', null, 'Negative'))
        ->toThrow(RuntimeException::class, 'Rate Per Month: enter an amount, or leave it blank.')
        ->and(EmployeeSalaryRate::query()->count())->toBe(2);
});

it('refuses an actor without all-data pay access, and lets a pay holder change their own salary', function () {
    $employee = salrEmployee();
    $viewer = salrActor(['PAYROLL'], false);
    expect(fn () => app(RecordSalaryChange::class)->handle($viewer, $employee->id, '2026-09-20', '24200', null, 'No'))
        ->toThrow(RuntimeException::class, 'Your role cannot record a salary change.')
        ->and(EmployeeSalaryRate::query()->count())->toBe(0)
        ->and($employee->refresh()->monthly_rate)->toBe(22000.0);
    // An all-data EMPLOYEES holder records one.
    $records = salrActor(['EMPLOYEES']);
    app(RecordSalaryChange::class)->handle($records, $employee->id, '2026-09-20', '24200', null, 'Records');
    expect($employee->refresh()->monthly_rate)->toBe(24200.0);
    // So does an all-data PAYROLL holder on their own record: one administrator runs the whole flow.
    $own = salrActor(['PAYROLL'], true, $employee->id);
    app(RecordSalaryChange::class)->handle($own, $employee->id, '2026-10-01', '25000', null, 'Mine');
    expect(EmployeeSalaryRate::query()->where('employee_id', $employee->id)->where('effective_from', '2026-10-01')->sole()->status)->toBe(EmployeeSalaryRate::APPROVED);
});

it('holds a future-dated change until its date, when the schedule applies it once', function () {
    $employee = salrEmployee();
    $actor = salrActor();
    $message = app(RecordSalaryChange::class)->handle($actor, $employee->id, '2026-10-01', '24200', null, 'From October');
    expect($message)->toBe('Salary change recorded for Nora Salcedo; it takes effect on 01 Oct 2026.')
        ->and($employee->refresh()->monthly_rate)->toBe(22000.0)
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->sole()->effective_date->format('Y-m-d'))->toBe('2026-10-01');

    Artisan::call('salary:apply-due');
    expect($employee->refresh()->monthly_rate)->toBe(22000.0);

    $this->travelTo(new DateTimeImmutable('2026-10-01T00:30:00+08:00'));
    Artisan::call('salary:apply-due');
    expect($employee->refresh()->monthly_rate)->toBe(24200.0)
        ->and(AuditLog::query()->where('action', AuditEvent::SALARY_CHANGE_APPLIED->value)->count())->toBe(1);
    Artisan::call('salary:apply-due');
    expect(AuditLog::query()->where('action', AuditEvent::SALARY_CHANGE_APPLIED->value)->count())->toBe(1);
});

it('keeps a past-dated change pending until it is approved, by its recorder included, and keeps a rejection', function () {
    $employee = salrEmployee();
    $preparer = salrActor(name: 'Preparer');
    $message = app(RecordSalaryChange::class)->handle($preparer, $employee->id, '2026-09-01', '24200', null, 'Backdated');
    $row = EmployeeSalaryRate::query()->where('employee_id', $employee->id)->where('status', EmployeeSalaryRate::PENDING)->sole();
    expect($message)->toContain('waits for approval')
        ->and($row->status)->toBe(EmployeeSalaryRate::PENDING)
        ->and($employee->refresh()->monthly_rate)->toBe(22000.0)
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->exists())->toBeFalse()
        ->and(fn () => app(RecordSalaryChange::class)->handle($preparer, $employee->id, '2026-10-01', '25000', null, 'Another'))
        ->toThrow(RuntimeException::class, 'is still awaiting approval');

    // The recorder approves it themselves: one administrator runs the whole flow.
    $approver = $preparer;
    expect(app(ReviewSalaryChange::class)->handle($approver, $row->id, ReviewSalaryChange::APPROVE, 'Checked the memo'))->toBe('Salary change from 01 Sep 2026 approved for Nora Salcedo.')
        ->and($row->refresh()->status)->toBe(EmployeeSalaryRate::APPROVED)
        ->and($row->review_note)->toBe('Checked the memo')
        ->and($employee->refresh()->monthly_rate)->toBe(24200.0)
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->sole()->effective_date->format('Y-m-d'))->toBe('2026-09-01')
        ->and(fn () => app(ReviewSalaryChange::class)->handle($approver, $row->id, ReviewSalaryChange::APPROVE))
        ->toThrow(RuntimeException::class, 'That salary change was already reviewed.');

    // A rejection keeps the row and changes nothing.
    app(RecordSalaryChange::class)->handle($preparer, $employee->id, '2026-09-15', '26000', null, 'Too much');
    $second = EmployeeSalaryRate::query()->where('employee_id', $employee->id)->where('status', EmployeeSalaryRate::PENDING)->sole();
    expect(app(ReviewSalaryChange::class)->handle($approver, $second->id, ReviewSalaryChange::REJECT, 'Not approved by the board'))->toBe('Salary change from 15 Sep 2026 rejected for Nora Salcedo.')
        ->and($second->refresh()->status)->toBe(EmployeeSalaryRate::REJECTED)
        ->and($employee->refresh()->monthly_rate)->toBe(24200.0)
        ->and(AuditLog::query()->where('action', AuditEvent::SALARY_CHANGE_REVIEWED->value)->count())->toBe(2);
});

it('records a rate edit made on the record as a version dated today', function () {
    $employee = salrEmployee();
    $actor = salrActor(['EMPLOYEES']);
    $employee->forceFill(['monthly_rate' => 23000])->save();
    SalaryRates::recordProfileEdit($actor, $employee, 22000.0, null, '2026-09-20');
    SalaryRates::recordProfileEdit($actor, $employee, 23000.0, null, '2026-09-20');
    $row = EmployeeSalaryRate::query()->where('employee_id', $employee->id)->where('effective_from', '2026-09-20')->sole();
    expect($row->effective_from->format('Y-m-d'))->toBe('2026-09-20')
        ->and($row->monthly_rate)->toBe(23000.0)
        ->and($row->status)->toBe(EmployeeSalaryRate::APPROVED)
        ->and($row->reason)->toBe('Changed on Employee Details');
    // The same day again brings the version to the new figure instead of adding one.
    $employee->forceFill(['monthly_rate' => 23500])->save();
    SalaryRates::recordProfileEdit($actor, $employee, 23000.0, null, '2026-09-20');
    expect(EmployeeSalaryRate::query()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and($row->refresh()->monthly_rate)->toBe(23500.0);
});

/*
|--------------------------------------------------------------------------
| Payroll
|--------------------------------------------------------------------------
*/

it('prices each day at the salary in force on it, captures the versions in the evidence and replays them', function () {
    $employee = salrEmployee();
    salrWorked($employee, '2026-09-08');
    salrWorked($employee, '2026-09-11');
    $preparer = salrActor(name: 'Preparer');
    app(RecordSalaryChange::class)->handle($preparer, $employee->id, '2026-09-10', '24200', null, 'Step increment');
    $actor = salrActor(name: 'Approver');
    app(ReviewSalaryChange::class)->handle($actor, EmployeeSalaryRate::query()->where('status', EmployeeSalaryRate::PENDING)->sole()->id, ReviewSalaryChange::APPROVE);
    expect($employee->refresh()->monthly_rate)->toBe(24200.0);

    $result = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $inputs = $result['inputs'];
    // The record now shows 24,200, but the first day's salary was 22,000: 1,000 for the 8th, 1,100 for the 11th.
    expect($inputs->employees[0]->monthly_rate)->toBe(22000.0)
        ->and($inputs->employees[0]->salary_rates)->toBe([['from' => '2026-09-10', 'monthly_rate' => 24200.0, 'daily_rate' => null]])
        ->and($result['drafts'][0]->work_days_pay)->toBe(2100.0)
        ->and($result['drafts'][0]->daily_rate)->toBe(1000.0)
        ->and($inputs->toArray()['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($inputs->toArray()['employees'][0]['salary_rates'])->toBe([['from' => '2026-09-10', 'monthly_rate' => 24200.0, 'daily_rate' => null]]);

    // The evidence replays the captured salary, and never reads the live table.
    $restored = PayrollInputs::restore(PayrollInputs::json($inputs->toArray()));
    expect($restored->fingerprint())->toBe($inputs->fingerprint());
    EmployeeSalaryRate::query()->delete();
    $employee->forceFill(['monthly_rate' => 50000])->save();
    expect(PayrollData::calculateInputs($restored, strict: true)['drafts'][0]->work_days_pay)->toBe(2100.0);

    // Older evidence carries no segments: one salary for the period.
    $older = $inputs->toArray();
    $older['schema_version'] = 18;
    $older = PayrollEvidenceFixture::asSchema($older, 18);
    expect(PayrollInputs::restore(PayrollInputs::json($older))->employees[0]->salary_rates)->toBe([]);
    $broken = $inputs->toArray();
    $broken['employees'][0]['salary_rates'][] = ['from' => '2026-09-05', 'monthly_rate' => 1.0, 'daily_rate' => null];
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($broken)))->toThrow(RuntimeException::class, 'Invalid payroll salary evidence.');
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

it('records and reviews salary changes from the employee pay page', function () {
    $employee = salrEmployee();
    salrActor(name: 'Preparer');
    $page = Livewire::test(EmployeePaySettingsShow::class, ['employee' => $employee->id])
        ->assertSee('Record salary change')
        ->assertSee('No salary version yet')
        ->call('openSalaryChange')
        ->assertSet('salaryOpen', true)
        ->assertSet('salaryForm.effective_from', '2026-09-20')
        ->set('salaryForm.monthly', '24,200')
        ->call('saveSalaryChange')
        ->assertSet('salaryError', 'Enter the reason for the salary change.')
        ->set('salaryForm.reason', 'Step increment')
        ->set('salaryForm.effective_from', '2026-09-01')
        ->call('saveSalaryChange')
        ->assertSet('salaryOpen', false)
        ->assertSee('Awaiting approval');
    $pending = EmployeeSalaryRate::query()->where('employee_id', $employee->id)->where('status', EmployeeSalaryRate::PENDING)->sole();
    expect($pending->status)->toBe(EmployeeSalaryRate::PENDING);

    // The preparer approves it from the same page.
    $page->call('openSalaryReview', $pending->id)
        ->set('salaryReviewNote', 'Memo on file')
        ->call('approveSalaryChange')
        ->assertSet('salaryReviewOpen', false)
        ->assertSee('Current');
    expect($pending->refresh()->status)->toBe(EmployeeSalaryRate::APPROVED)
        ->and($employee->refresh()->monthly_rate)->toBe(24200.0);
});
