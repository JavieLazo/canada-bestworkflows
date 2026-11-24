<?php

declare(strict_types=1);

use App\Livewire\Leaves\LeaveCashOuts;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Records\IncentiveTypesPage;
use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryRate;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\PayrollIncentiveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Timekeeping\Application\Commands\FileTerminalLeave;
use App\Modules\Timekeeping\Application\Commands\RequestLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\ReviewLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * Converting leave credits to cash within the limits HR sets, and terminal
 * leave. On Leave Settings a type that may be converted carries optional
 * LIMITS (LeaveCashOutLimits: the credits needed before converting, the
 * fewest days a request, the days that must remain, the most days and the
 * most requests a year, and the share of the balance from which a request
 * needs a reason — every one blank for none) and any type filed against
 * credits may be PAID OUT ON SEPARATION. An employee's own request
 * (RequestLeaveCashOut) is held to its type's limits, counted per type
 * over the Manila year; a LARGE request must give a reason, is kept
 * marked and may go past the yearly maximum. A leave manager files
 * TERMINAL LEAVE for an employee with a Date Resigned (FileTerminalLeave):
 * one pending cash conversion per type paid out on separation, for its
 * whole balance, decided like any other. Payroll values a converted day as
 * the paying Incentives & Bonuses type says (LeaveCashOutValue): the
 * monthly rate × the factor (0.0481927 to start) or the daily rate;
 * terminal leave from the highest salary on record. Evidence written
 * before the setting keeps pricing at the daily rate.
 */

/** @param  list<string>  $modules */
function ccrRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Cash rules '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * Signed in, with a fresh Access.
 *
 * @param  list<string>  $modules
 */
function ccrActor(array $modules, bool $allData = false, ?string $employeeId = null): Access
{
    $user = User::factory()->create(['role_id' => ccrRole($modules, $allData)->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** A manager over leave, payroll and the settings (all-data). */
function ccrManager(): Access
{
    return ccrActor(['LEAVES', 'PAYROLL', 'RECORDS', 'EDTR'], true);
}

/**
 * Monday to Friday, 08:00-17:00 with a 60-minute unpaid break, CAD 30,000 a month.
 *
 * @param  array<string, mixed>  $overrides
 */
function ccrEmployee(array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => 'CCR-'.random_int(1000, 9999), 'first_name' => 'Rosa', 'last_name' => 'Villanueva',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'monthly_rate' => 30000,
    ], $overrides))->save();
    $shift = new Shift;
    $shift->forceFill([
        'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 15,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $employee;
}

/**
 * A leave type filed against credits, with its settings saved through the command.
 *
 * @param  array<string, mixed>  $settings
 */
function ccrType(Access $manager, string $name, array $settings = []): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'CR-'.Str::random(6), 'name' => $name])->save();
    app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, array_merge([
        'earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0',
    ], $settings));

    return $type;
}

function ccrCredit(EmployeeProfile $employee, string $token, float $credits): void
{
    $row = new LeaveCredit;
    $row->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => $token, 'credits' => $credits])->save();
}

/**
 * The Incentives & Bonuses type cash conversions are paid as: typed, taxable.
 *
 * @param  array{day_value: string, factor: string|float|null}|null  $value
 */
function ccrPayType(Access $manager, ?array $value = null): PayrollIncentiveType
{
    app(SaveIncentiveType::class)->handle($manager, null, 'Leave conversion pay', null, true, null, true, $value);

    return PayrollIncentiveType::query()->where('name', 'Leave conversion pay')->sole();
}

/** An APPROVED salary version. */
function ccrSalary(EmployeeProfile $employee, string $from, ?float $monthly, ?float $daily = null): void
{
    $row = new EmployeeSalaryRate;
    $row->forceFill(['employee_id' => $employee->id, 'effective_from' => $from, 'monthly_rate' => $monthly, 'daily_rate' => $daily, 'reason' => 'Test', 'status' => EmployeeSalaryRate::APPROVED, 'approved_at' => $from.' 00:00:00.000'])->save();
}

/** A cash conversion as an approval left it. */
function ccrApproved(EmployeeProfile $employee, string $token, float $days, string $purpose = 'MONETIZATION'): LeaveCashOut
{
    $row = new LeaveCashOut;
    $row->forceFill(['employee_id' => $employee->id, 'request_type' => $token, 'days' => $days, 'status' => LeaveCashOut::APPROVED, 'purpose' => $purpose, 'requested_at' => '2026-09-01 00:00:00.000', 'reviewed_at' => '2026-09-01 01:00:00.000'])->save();

    return $row;
}

/** The limits most tests set on Vacation Leave. */
const CCR_VACATION_LIMITS = [
    'can_cash_out' => true, 'paid_on_separation' => true,
    'cash_out_min_balance' => '15', 'cash_out_min_days' => '10', 'cash_out_keep_days' => '5', 'cash_out_max_year' => '15', 'cash_out_per_year' => '', 'cash_out_reason_share' => '50',
];

/*
|--------------------------------------------------------------------------
| Leave Settings
|--------------------------------------------------------------------------
*/

it('keeps a type\'s cash conversion limits and its payout on separation, blank for none, and only where they apply', function () {
    $manager = ccrManager();
    $type = ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);

    $row = $type->refresh();
    expect($row->cash_out_min_balance)->toBe(15.0)
        ->and($row->cash_out_min_days)->toBe(10.0)
        ->and($row->cash_out_keep_days)->toBe(5.0)
        ->and($row->cash_out_max_year)->toBe(15.0)
        ->and($row->cash_out_per_year)->toBeNull()
        ->and($row->cash_out_reason_share)->toBe(50)
        ->and($row->paid_on_separation)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'LEAVE_TYPE_CREDITS_SET')->latest('created_at')->value('details'))
        ->toContain('may be cashed out (hold at least 15 days of credits before converting, convert at least 10 days at a time')
        ->toContain('paid on separation');

    // A type that may not be converted keeps no limits; one not filed against credits is never paid out.
    app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, [...CCR_VACATION_LIMITS, 'earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', 'can_cash_out' => false]);
    expect($type->refresh()->cash_out_min_days)->toBeNull()
        ->and($type->paid_on_separation)->toBeTrue();
    $unpaid = ccrType($manager, 'Leave Without Pay', ['filing' => 'UNPAID', 'paid_on_separation' => true]);
    expect($unpaid->refresh()->paid_on_separation)->toBeFalse();

    // A bad limit is refused, and nothing changes.
    expect(fn () => app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, [...CCR_VACATION_LIMITS, 'earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', 'cash_out_reason_share' => '120']))
        ->toThrow(RuntimeException::class, 'Reason needed from: enter a whole percent from 1 to 100');
    expect($type->refresh()->getAttribute('can_cash_out'))->toBeFalsy();
});

it('sets the limits and the payout on separation from the Filing and credit settings dialog, and lists them', function () {
    $manager = ccrManager();
    $type = ccrType($manager, 'Vacation Leave');

    Livewire::test(LeaveCreditSettingsPage::class)
        ->call('openType', $type->id)
        ->assertSeeHtml('data-paid-on-separation')
        ->assertDontSeeHtml('data-cash-out-limits')
        ->set('typeForm.can_cash_out', true)
        ->assertSeeHtml('data-cash-out-limits')
        ->set('typeForm.cash_out_min_days', '10')
        ->set('typeForm.cash_out_per_year', '1')
        ->set('typeForm.paid_on_separation', true)
        ->call('saveType')
        ->assertSet('typeOpen', false)
        ->assertSeeHtml('data-cash-out-limit>Convert at least 10 days at a time</span>')
        ->assertSeeHtml('data-cash-out-limit>Once a year</span>')
        ->assertSeeHtml('data-paid-on-separation-flag');

    $settings = LeaveTypeCreditRecords::of('VACATION_LEAVE');
    expect($settings->cashOutLimits->minDays)->toBe(10.0)
        ->and($settings->cashOutLimits->requestsPerYear)->toBe(1)
        ->and($settings->cashOutLimits->maxPerYear)->toBeNull()
        ->and($settings->paidOnSeparation)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| An employee's own request
|--------------------------------------------------------------------------
*/

it('holds an employee\'s request to its type\'s limits, counted per type over the year, and marks a large one', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
    ccrType($manager, 'Sick Leave', ['can_cash_out' => true]);
    $employee = ccrEmployee();
    ccrCredit($employee, 'VACATION_LEAVE', 40.0);
    ccrCredit($employee, 'SICK_LEAVE', 10.0);
    $self = ccrActor(['PORTAL'], employeeId: $employee->id);
    $request = app(RequestLeaveCashOut::class);

    expect(fn () => $request->handle($self, 'VACATION_LEAVE', '5', ''))->toThrow(RuntimeException::class, 'Convert at least 10 days at a time.');
    expect(LeaveCashOut::query()->count())->toBe(0);
    $request->handle($self, 'VACATION_LEAVE', '10', '');
    LeaveCashOut::query()->update(['status' => LeaveCashOut::APPROVED]);

    // 10 of the 15 a year are used; 30 days are left to convert from.
    expect(fn () => $request->handle($self, 'VACATION_LEAVE', '10', ''))->toThrow(RuntimeException::class, 'Cash conversions are limited to 15 days a year; 5 left this year.');
    expect(fn () => $request->handle($self, 'VACATION_LEAVE', '15', ''))->toThrow(RuntimeException::class, 'Converting 15 days or more needs a reason. Say why in the note.');
    // Half the balance with a reason is large, and may go past the yearly maximum.
    $request->handle($self, 'VACATION_LEAVE', '15', 'Hospital bills');
    $large = LeaveCashOut::query()->where('status', LeaveCashOut::PENDING)->sole();
    expect($large->large_request)->toBeTrue()
        ->and($large->note)->toBe('Hospital bills')
        ->and($large->purpose)->toBe('MONETIZATION')
        ->and(AuditLog::query()->where('action', 'LEAVE_CASH_OUT_REQUESTED')->pluck('details')->all())->toContain('Cash-out of 15 days of Vacation Leave (a large request) requested · Hospital bills');

    // Another type's limits are its own.
    $request->handle($self, 'SICK_LEAVE', '2', '');
    expect(LeaveCashOut::query()->where('request_type', 'SICK_LEAVE')->sole()->large_request)->toBeFalse();
});

it('states the picked type\'s limits in My Portal, and marks a large request for the reviewer', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
    $employee = ccrEmployee();
    ccrCredit($employee, 'VACATION_LEAVE', 40.0);
    ccrActor(['PORTAL'], employeeId: $employee->id);

    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->set('cashOutOpen', true)
        ->assertDontSeeHtml('data-cash-out-terms')
        ->set('cashOutType', 'VACATION_LEAVE')
        ->assertSeeHtml('data-cash-out-terms')
        ->assertSee('Hold at least 15 days of credits before converting')
        ->assertSee('Up to 15 days a year')
        ->assertSeeHtml('data-cash-out-reason-hint')
        ->assertSeeHtml('<span class="num">20</span> days or more needs a reason')
        ->set('cashOutDays', '20')->set('cashOutNote', 'Tuition')
        ->call('requestCashOut')
        ->assertSet('cashOutOpen', false);
    $row = LeaveCashOut::query()->sole();

    ccrManager();
    Livewire::test(LeaveCashOuts::class)
        ->assertSeeHtml('data-cash-out-large')
        ->call('openReview', $row->id, true)
        ->assertSeeHtml('data-cash-out-review-large');
});

/*
|--------------------------------------------------------------------------
| Terminal leave
|--------------------------------------------------------------------------
*/

it('files terminal leave for an employee who has left: the whole balance of each type paid out on separation, to approve', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
    ccrType($manager, 'Sick Leave', ['paid_on_separation' => true]);
    ccrType($manager, 'Emergency Leave', ['can_cash_out' => true]);
    $employee = ccrEmployee(['date_resigned' => '2026-09-15']);
    ccrCredit($employee, 'VACATION_LEAVE', 12.5);
    ccrCredit($employee, 'SICK_LEAVE', 30.0);
    ccrCredit($employee, 'EMERGENCY_LEAVE', 3.0);
    $manager = ccrManager();

    $message = app(FileTerminalLeave::class)->handle($manager, $employee->id, 'Last day 15 September');
    $rows = LeaveCashOut::query()->orderBy('request_type')->get();
    expect($message)->toContain('Terminal leave filed for Rosa Villanueva: Sick Leave 30 days · Vacation Leave 12.5 days.')
        ->and($rows->pluck('request_type')->all())->toBe(['SICK_LEAVE', 'VACATION_LEAVE'])
        ->and($rows->pluck('days')->all())->toBe([30.0, 12.5])
        ->and($rows->pluck('status')->unique()->all())->toBe(['PENDING'])
        ->and($rows->pluck('purpose')->unique()->all())->toBe(['TERMINAL'])
        ->and($rows->pluck('requested_by')->unique()->all())->toBe([(int) $manager->userId])
        ->and(AuditLog::query()->where('action', 'TERMINAL_LEAVE_FILED')->value('details'))->toBe('Rosa Villanueva · terminal leave of Sick Leave 30 days · Vacation Leave 12.5 days filed · Last day 15 September');

    // Once filed, not again — and a decision says it is paid on the final payroll.
    expect(fn () => app(FileTerminalLeave::class)->handle($manager, $employee->id))->toThrow(RuntimeException::class, 'Terminal leave was already filed for Rosa Villanueva.');
    $other = ccrManager();
    app(ReviewLeaveCashOut::class)->handle($other, (string) $rows->firstWhere('request_type', 'VACATION_LEAVE')?->id, true);
    expect(LeaveCashOut::query()->where('request_type', 'VACATION_LEAVE')->value('status'))->toBe('APPROVED')
        ->and(AuditLog::query()->where('action', 'LEAVE_CASH_OUT_REVIEWED')->value('details'))->toContain('terminal leave of 12.5 days of Vacation Leave approved');
});

it('refuses terminal leave to whoever cannot manage leave, before a Date Resigned, while a request waits and with nothing to pay', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
    ccrType($manager, 'Emergency Leave', ['can_cash_out' => true]);
    $staying = ccrEmployee();
    $leaving = ccrEmployee(['date_resigned' => '2026-09-15', 'first_name' => 'Ana']);
    ccrCredit($staying, 'VACATION_LEAVE', 20.0);
    ccrCredit($leaving, 'VACATION_LEAVE', 20.0);
    $nothing = ccrEmployee(['date_resigned' => '2026-09-15', 'first_name' => 'Ben']);
    ccrCredit($nothing, 'EMERGENCY_LEAVE', 3.0);

    $leaveClerk = ccrActor(['LEAVES']);
    expect(fn () => app(FileTerminalLeave::class)->handle($leaveClerk, $leaving->id))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    $manager = ccrManager();
    expect(fn () => app(FileTerminalLeave::class)->handle($manager, $staying->id))->toThrow(RuntimeException::class, "Set Rosa Villanueva's Date Resigned on the employee record first");
    expect(fn () => app(FileTerminalLeave::class)->handle($manager, $nothing->id))->toThrow(RuntimeException::class, 'Ben Villanueva has no leave credits to pay out.');
    expect(fn () => app(FileTerminalLeave::class)->handle($manager, 'missing'))->toThrow(RuntimeException::class, 'That employee no longer exists.');

    $pending = new LeaveCashOut;
    $pending->forceFill(['employee_id' => $leaving->id, 'request_type' => 'VACATION_LEAVE', 'days' => 10.0, 'status' => LeaveCashOut::PENDING, 'purpose' => 'MONETIZATION', 'requested_at' => '2026-09-01 00:00:00.000'])->save();
    expect(fn () => app(FileTerminalLeave::class)->handle($manager, $leaving->id))->toThrow(RuntimeException::class, 'Ana Villanueva has a cash conversion waiting for a decision. Approve or reject it first.');
    expect(LeaveCashOut::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'TERMINAL_LEAVE_FILED')->exists())->toBeFalse();
});

it('files terminal leave from the Cash conversions tab after reading what it pays out', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
    $employee = ccrEmployee(['date_resigned' => '2026-09-15', 'is_active' => false]);
    ccrCredit($employee, 'VACATION_LEAVE', 12.5);

    // A leave holder without all-data is offered nothing.
    ccrActor(['LEAVES']);
    Livewire::test(LeaveCashOuts::class)->assertDontSeeHtml('data-file-terminal')->call('openTerminal')->assertSet('terminalOpen', false);

    ccrManager();
    Livewire::test(LeaveCashOuts::class)
        ->assertSeeHtml('data-file-terminal')
        ->call('openTerminal')
        ->call('employeePicked', 'file-employee', $employee->id)
        ->assertSet('terminalEmployeeId', null)
        ->call('employeePicked', 'terminal-employee', $employee->id)
        ->assertSet('terminalEmployeeId', $employee->id)
        ->assertSeeHtml('data-terminal-lines')
        ->assertSee('Vacation Leave')
        ->assertSee('Sep 15, 2026')
        ->assertSeeHtml('data-terminal-inactive')
        ->set('terminalNote', 'Final pay')
        ->call('fileTerminal')
        ->assertSet('terminalOpen', false);
    expect(LeaveCashOut::query()->sole()->only(['request_type', 'days', 'status', 'purpose', 'note']))
        ->toBe(['request_type' => 'VACATION_LEAVE', 'days' => 12.5, 'status' => 'PENDING', 'purpose' => 'TERMINAL', 'note' => 'Final pay']);
});

/*
|--------------------------------------------------------------------------
| What a converted day pays
|--------------------------------------------------------------------------
*/

it('pays terminal leave from the highest salary on record, and keeps that salary in the evidence', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-16T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
    $employee = ccrEmployee(['date_resigned' => '2026-09-15', 'monthly_rate' => 28000]);
    ccrSalary($employee, '2024-01-01', 25000.0);
    ccrSalary($employee, '2026-03-01', 32000.0);
    ccrSalary($employee, '2026-08-01', 28000.0);
    // A raise dated after the employee left is not theirs.
    ccrSalary($employee, '2026-10-01', 40000.0);
    ccrCredit($employee, 'VACATION_LEAVE', 20.0);
    ccrApproved($employee, 'VACATION_LEAVE', 12.5, 'TERMINAL');
    ccrApproved($employee, 'VACATION_LEAVE', 2.5);
    $type = ccrPayType($manager);
    expect($type->cash_out_day_value)->toBe('FACTOR')->and($type->cash_out_factor)->toBe(0.0481927);

    $calculation = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $manager);
    $lines = collect($calculation['incentiveLines'][$employee->id])->keyBy('name');
    // 32,000 × 12.5 × 0.0481927 = 19,277.08; the monetization at the first day's 28,000: 28,000 × 2.5 × 0.0481927 = 3,373.489.
    expect($calculation['combined'])->toBe([])
        ->and($lines['Leave conversion pay · Terminal leave · Vacation Leave 12.5 days']['amount'])->toBe(19277.08)
        ->and($lines['Leave conversion pay · Vacation Leave 2.5 days']['amount'])->toBe(3373.49);
    $inputs = $calculation['inputs'];
    assert($inputs !== null);
    $terminal = collect($inputs->leaveCashOuts[$employee->id])->firstWhere('purpose', 'TERMINAL');
    expect($terminal['salary'] ?? null)->toBe(['from' => '2026-03-01', 'monthly_rate' => 32000.0, 'daily_rate' => null])
        ->and(PayrollInputs::restore(PayrollInputs::json($inputs->toArray()))->fingerprint())->toBe($inputs->fingerprint());
});

it('values a day at the daily rate when the paying type says so, and in evidence written before the setting', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-16T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', ['can_cash_out' => true]);
    $employee = ccrEmployee();
    ccrCredit($employee, 'VACATION_LEAVE', 10.0);
    ccrApproved($employee, 'VACATION_LEAVE', 2.5);
    $type = ccrPayType($manager);

    // The standard factor: 30,000 × 2.5 × 0.0481927.
    $factor = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $manager);
    expect($factor['incentiveLines'][$employee->id][0]['amount'])->toBe(3614.45);

    // Evidence of schema 23 restores as it was priced: the daily rate, whatever the type says now.
    $inputs = $factor['inputs'];
    assert($inputs !== null);
    $older = PayrollInputs::restore(PayrollInputs::json(PayrollEvidenceFixture::asSchema(['schema_version' => 23] + $inputs->toArray(), 23)));
    expect($older->cashOutType['day_value'] ?? null)->toBe('DAILY')
        ->and($older->leaveCashOuts[$employee->id][0]['purpose'])->toBe('MONETIZATION')
        ->and(PayrollData::calculateInputs($older)['incentiveLines'][$employee->id][0]['amount'])->toBe(3409.09)
        ->and(PayrollInputs::restore(PayrollInputs::json($older->toArray()))->fingerprint())->toBe($older->fingerprint());

    // September 2026 has 22 scheduled days: CAD 30,000 is CAD 1,363.636… a day, so 2.5 days are CAD 3,409.09.
    app(SaveIncentiveType::class)->handle($manager, $type->id, 'Leave conversion pay', null, true, null, true, ['day_value' => 'DAILY', 'factor' => null]);
    expect($type->refresh()->cash_out_day_value)->toBe('DAILY')
        ->and($type->cash_out_factor)->toBe(0.0481927)
        ->and(AuditLog::query()->where('action', 'INCENTIVE_TYPE_UPDATED')->latest('created_at')->value('details'))->toContain('a converted day of leave credits now pays daily rate');
    $daily = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $manager);
    expect($daily['incentiveLines'][$employee->id][0]['amount'])->toBe(3409.09);

    // A bad factor is refused, and nothing changes.
    expect(fn () => app(SaveIncentiveType::class)->handle($manager, $type->id, 'Leave conversion pay', null, true, null, true, ['day_value' => 'FACTOR', 'factor' => '2']))
        ->toThrow(RuntimeException::class, 'Enter the factor as a number above 0 and at most 1');
    expect($type->refresh()->cash_out_day_value)->toBe('DAILY');
});

it('sets what a converted day pays from the Incentives & Bonuses dialog', function () {
    $manager = ccrManager();
    $type = ccrPayType($manager);

    Livewire::test(IncentiveTypesPage::class)
        ->call('startEdit', $type->id)
        ->assertSeeHtml('data-cash-out-value')
        ->assertSeeHtml('data-cash-out-factor')
        ->assertSet('formCashOutFactor', '0.0481927')
        ->set('formCashOutFactor', '0.05')
        ->call('save');
    expect($type->refresh()->cash_out_factor)->toBe(0.05);

    Livewire::test(IncentiveTypesPage::class)
        ->call('startEdit', $type->id)
        ->set('formCashOutDayValue', 'DAILY')
        ->assertDontSeeHtml('data-cash-out-factor')
        ->call('save');
    expect($type->refresh()->cash_out_day_value)->toBe('DAILY')
        ->and($type->cash_out_factor)->toBe(0.05);
});

/*
|--------------------------------------------------------------------------
| Migrations and the window before them
|--------------------------------------------------------------------------
*/

it('migrates the limits, the large mark and the day value twice, every convertible type paid out on separation', function () {
    $manager = ccrManager();
    $vacation = ccrType($manager, 'Vacation Leave', ['can_cash_out' => true]);
    $sick = ccrType($manager, 'Sick Leave');
    $limits = require base_path('database/migrations/2026_11_09_0001_add_cash_out_limits_to_leave_types_table.php');
    $large = require base_path('database/migrations/2026_11_09_0002_add_large_request_to_leave_cash_outs_table.php');
    $value = require base_path('database/migrations/2026_11_09_0003_add_cash_out_value_to_payroll_incentive_types_table.php');

    $limits->down();
    $large->down();
    $value->down();
    expect(Schema::hasColumn('leave_types', 'paid_on_separation'))->toBeFalse()
        ->and(Schema::hasColumn('leave_cash_outs', 'large_request'))->toBeFalse()
        ->and(Schema::hasColumn('payroll_incentive_types', 'cash_out_day_value'))->toBeFalse();
    foreach ([$limits, $large, $value] as $migration) {
        $migration->up();
        $migration->up();
    }
    Once::flush();

    expect(DB::table('leave_types')->where('id', $vacation->id)->value('paid_on_separation'))->toBeTruthy()
        ->and(DB::table('leave_types')->where('id', $sick->id)->value('paid_on_separation'))->toBeFalsy()
        ->and(DB::table('leave_types')->where('id', $vacation->id)->value('cash_out_min_days'))->toBeNull()
        ->and(Schema::hasColumns('leave_types', ['cash_out_min_balance', 'cash_out_min_days', 'cash_out_keep_days', 'cash_out_max_year', 'cash_out_per_year', 'cash_out_reason_share']))->toBeTrue()
        ->and(Schema::hasColumn('leave_cash_outs', 'large_request'))->toBeTrue()
        ->and(Schema::hasColumns('payroll_incentive_types', ['cash_out_day_value', 'cash_out_factor']))->toBeTrue();
});

it('works as before until the cron migrates the limits', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $migration = require base_path('database/migrations/2026_11_09_0001_add_cash_out_limits_to_leave_types_table.php');
    $migration->down();
    Once::flush();
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $manager = ccrManager();
        // The settings save without the limits; a request has none to meet.
        ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
        expect(LeaveTypeCreditRecords::of('VACATION_LEAVE')->cashOutLimits->isNone())->toBeTrue();
        $employee = ccrEmployee(['date_resigned' => '2026-09-15']);
        ccrCredit($employee, 'VACATION_LEAVE', 40.0);
        $self = ccrActor(['PORTAL'], employeeId: $employee->id);
        app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '2', '');
        expect(LeaveCashOut::query()->sole()->days)->toBe(2.0);
        LeaveCashOut::query()->delete();
        // Terminal leave waits for the upgrade.
        $manager = ccrManager();
        expect(fn () => app(FileTerminalLeave::class)->handle($manager, $employee->id))->toThrow(RuntimeException::class, 'once this installation\'s upgrade finishes');
        Livewire::test(LeaveCashOuts::class)->assertDontSeeHtml('data-file-terminal');
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        $migration->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});

it('leaves terminal leave to a leave manager to reject: the employee cannot withdraw it', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
    $employee = ccrEmployee(['date_resigned' => '2026-09-15']);
    ccrCredit($employee, 'VACATION_LEAVE', 12.5);
    app(FileTerminalLeave::class)->handle(ccrManager(), $employee->id);
    $row = LeaveCashOut::query()->sole();

    ccrActor(['PORTAL'], employeeId: $employee->id);
    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSeeHtml('data-cash-out="PENDING"')
        ->assertDontSeeHtml('withdrawCashOut(&#039;'.$row->id)
        ->assertDontSeeHtml("withdrawCashOut('".$row->id)
        ->call('withdrawCashOut', $row->id);
    expect($row->refresh()->status)->toBe('PENDING')
        ->and(AuditLog::query()->where('action', 'LEAVE_CASH_OUT_WITHDRAWN')->exists())->toBeFalse();

    app(ReviewLeaveCashOut::class)->handle(ccrManager(), $row->id, false, 'Filed by mistake');
    expect($row->refresh()->status)->toBe('REJECTED');
});

it('notifies the leave managers when terminal leave is filed', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $manager = ccrManager();
    ccrType($manager, 'Vacation Leave', CCR_VACATION_LIMITS);
    $employee = ccrEmployee(['date_resigned' => '2026-09-15']);
    ccrCredit($employee, 'VACATION_LEAVE', 12.5);
    $colleague = ccrManager();
    $filer = ccrManager();

    app(FileTerminalLeave::class)->handle($filer, $employee->id);
    expect(Notification::query()->where('kind', 'TERMINAL_LEAVE_FILED')->where('user_id', (int) $colleague->userId)->value('body'))->toBe('Rosa Villanueva: Vacation Leave 12.5 days, waiting for approval.')
        ->and(Notification::query()->where('kind', 'TERMINAL_LEAVE_FILED')->where('user_id', (int) $filer->userId)->exists())->toBeFalse();
});
