<?php

declare(strict_types=1);

use App\Livewire\Leaves\LeaveCashOuts;
use App\Livewire\Portal\PortalPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveType;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipIncentive;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Timekeeping\Application\Commands\RequestLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\ReviewLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\Commands\WithdrawLeaveCashOut;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\LeaveRuleFixture;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * Leave credits cashed out. An employee asks, in My Portal, to turn whole
 * or half days of a type Leave Settings allows into pay, within the balance
 * left and the type's limits (RequestLeaveCashOut; the limits are
 * CashConversionRulesTest's); an all-data LEAVES holder approves or
 * rejects (ReviewLeaveCashOut); the employee's next regular payroll pays
 * the approved days as a line of the Incentives & Bonuses type marked for
 * it, and posting takes the days off the ledger as a CASH_OUT entry
 * (LeaveCashOutQuery::post). The requests are in the payroll's evidence.
 *
 * The paying type values a converted day at the monthly rate times
 * 0.0481927 unless set otherwise: 2.5 days of PHP 30,000 a month are
 * 30,000 × 2.5 × 0.0481927 = PHP 3,614.45 (CashConversionRulesTest prices
 * the daily rate and terminal leave).
 */

/** @param list<string> $modules */
function lcosRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Lcos '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** A manager over leave and payroll (all-data), optionally linked to an employee; left signed in. */
function lcosManager(?string $employeeId = null): Access
{
    $user = User::factory()->create(['role_id' => lcosRole(['LEAVES', 'PAYROLL', 'RECORDS', 'EDTR'], true)->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** The employee's own portal sign-in (PORTAL, no all-data); left signed in. */
function lcosSelf(EmployeeProfile $employee): Access
{
    $user = User::factory()->create(['role_id' => lcosRole(['PORTAL'], false)->getKey(), 'employee_id' => $employee->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** Monday to Friday, 08:00-17:00 with a 60-minute unpaid break, PHP 30,000 a month. */
function lcosEmployee(string $last = 'Cashout'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LCOS-'.random_int(1000, 9999), 'first_name' => 'Nina', 'last_name' => $last,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'monthly_rate' => 30000,
    ])->save();
    $shift = new Shift;
    $shift->forceFill([
        'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 15,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $employee;
}

/** Vacation Leave may be cashed out, Sick Leave may not; credits for 2026: VL 10, SL 5. */
function lcosTypes(Access $manager, EmployeeProfile $employee): void
{
    foreach ([['VL', 'Vacation Leave', true], ['SL', 'Sick Leave', false]] as [$code, $name, $cashable]) {
        $type = new LeaveType;
        $type->forceFill(['code' => $code, 'name' => $name])->save();
        app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => false, 'charge_order' => '0', 'below_zero_limit' => '0', 'can_cash_out' => $cashable]);
    }
    foreach (['VACATION_LEAVE' => 10.0, 'SICK_LEAVE' => 5.0] as $token => $credits) {
        $row = new LeaveCredit;
        $row->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => $token, 'credits' => $credits])->save();
    }
}

/** The Incentives & Bonuses type cash-outs are paid as: typed, taxable. */
function lcosCashOutType(Access $manager): PayrollIncentiveType
{
    app(SaveIncentiveType::class)->handle($manager, null, 'Leave credits cash-out', null, true, null, true);

    return PayrollIncentiveType::query()->where('name', 'Leave credits cash-out')->sole();
}

/** Full days on every scheduled day of 1 to 15 September. */
function lcosAttendance(EmployeeProfile $employee): void
{
    foreach (['01', '02', '03', '04', '07', '08', '09', '10', '11', '14', '15'] as $day) {
        foreach (['TIME_IN' => '08:00', 'TIME_OUT' => '17:00'] as $type => $time) {
            $punch = new AttendanceLog;
            $punch->forceFill([
                'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
                'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant("2026-09-{$day}T{$time}:00")->utc(),
            ])->save();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Asking and withdrawing
|--------------------------------------------------------------------------
*/

it('lets an employee cash out whole or half days of a cashable type within the balance, and withdraw while pending', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $employee = lcosEmployee();
    $manager = lcosManager();
    lcosTypes($manager, $employee);
    // The leave rules are in force; they set no yearly cash-out limit, so the balance alone bounds a request.
    LeaveRuleFixture::approved('2026-01-01', ['earning' => []]);

    $self = lcosSelf($employee);
    // Refused before anything is asked: a type that cannot be cashed out, a third of a day, more than the 10 held.
    expect(fn () => app(RequestLeaveCashOut::class)->handle($self, 'SICK_LEAVE', '1', ''))->toThrow(RuntimeException::class, 'cannot be converted to cash');
    expect(fn () => app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '0.3', ''))->toThrow(RuntimeException::class, 'whole or half days');
    expect(fn () => app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '12', ''))->toThrow(RuntimeException::class, 'Only 10 days');

    Livewire::test(PortalPage::class)->set('tab', 'requests')
        ->assertSeeHtml('data-cash-out-button')
        ->set('cashOutType', 'VACATION_LEAVE')->set('cashOutDays', '2.5')->set('cashOutNote', 'For school fees')
        ->call('requestCashOut')
        ->assertSeeHtml('data-cash-out="PENDING"')
        ->assertSee('Vacation Leave')
        ->assertSee('2.5');
    $request = LeaveCashOut::query()->sole();
    expect($request->status)->toBe('PENDING')->and($request->days)->toBe(2.5)->and($request->note)->toBe('For school fees')
        ->and($request->requested_by)->toBe((int) $self->userId)
        ->and(AuditLog::query()->where('action', 'LEAVE_CASH_OUT_REQUESTED')->value('details'))->toBe('Cash-out of 2.5 days of Vacation Leave requested · For school fees');

    // While it is pending: no second request of the type. Withdrawn, the 2.5 are free again; only the balance bounds the next one.
    expect(fn () => app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '1', ''))->toThrow(RuntimeException::class, 'pending cash conversion');
    app(WithdrawLeaveCashOut::class)->handle($self, $request->id);
    expect($request->refresh()->status)->toBe('WITHDRAWN');
    expect(fn () => app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '12', ''))->toThrow(RuntimeException::class, 'Only 10 days');
    expect(LeaveCashOut::query()->count())->toBe(1);

    // Withdrawn twice is already decided; a manager cannot withdraw someone else's through the own path.
    expect(fn () => app(WithdrawLeaveCashOut::class)->handle($self, $request->id))->toThrow(RuntimeException::class, 'already been decided');
    $other = lcosManager(lcosEmployee('Other')->id);
    expect(fn () => app(WithdrawLeaveCashOut::class)->handle($other, $request->id))->toThrow(RuntimeException::class, 'no longer exists');
});

/*
|--------------------------------------------------------------------------
| Deciding and paying
|--------------------------------------------------------------------------
*/

it('has a manager decide it, the employee\'s own linked account included, pays it on the next payroll as the marked type at the monthly rate times the factor, and takes the days off the ledger', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-16T10:00:00+08:00'));
    $employee = lcosEmployee();
    $manager = lcosManager();
    lcosTypes($manager, $employee);
    lcosAttendance($employee);
    $self = lcosSelf($employee);
    app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '2.5', '');
    $request = LeaveCashOut::query()->sole();

    // The employee's own linked manager account decides it: one administrator runs the whole flow.
    $reviewer = lcosManager($employee->id);
    Livewire::test(LeaveCashOuts::class)
        ->assertSeeHtml('data-cash-out="PENDING"')
        ->assertSee('Nina Cashout')
        ->call('openReview', $request->id, true)
        ->assertSet('reviewingId', $request->id)
        ->set('reviewNote', 'Approved for September')
        ->call('confirmReview');
    expect($request->refresh()->status)->toBe('APPROVED')
        ->and($request->reviewed_by)->toBe((int) $reviewer->userId)
        ->and($request->review_note)->toBe('Approved for September')
        ->and(AuditLog::query()->where('action', 'LEAVE_CASH_OUT_REVIEWED')->value('details'))->toContain('cash-out of 2.5 days of Vacation Leave approved');
    // Decided once: a second decision is refused.
    expect(fn () => app(ReviewLeaveCashOut::class)->handle($reviewer, $request->id, false))->toThrow(RuntimeException::class, 'already been decided');

    // No benefit type is marked yet: the draft is refused with the reason.
    $calculation = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $reviewer);
    expect($calculation['combined'][0]['message'])->toContain('no Incentives & Bonuses type pays them');

    // Marked: the draft carries one line of that type, 30,000 × 2.5 × 0.0481927, taxed with the pay.
    $type = lcosCashOutType($reviewer);
    expect((bool) $type->getAttribute('pays_leave_cash_out'))->toBeTrue();
    $calculation = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $reviewer);
    $line = $calculation['incentiveLines'][$employee->id][0];
    expect($calculation['combined'])->toBe([])
        ->and($line['name'])->toBe('Leave credits cash-out · Vacation Leave 2.5 days')
        ->and($line['amount'])->toBe(3614.45)
        ->and($line['automatic'])->toBeTrue()
        ->and($line['cash_out'] ?? null)->toBe(['id' => $request->id, 'days' => 2.5, 'amount' => 3614.45])
        ->and($calculation['drafts'][0]->bonus)->toBe(3614.45)
        ->and($calculation['drafts'][0]->gross_pay)->toBe(round(15000.0 + 3614.45, 2));
    $inputs = $calculation['inputs'];
    assert($inputs !== null);
    expect($inputs->leaveCashOuts[$employee->id][0])->toBe(['id' => $request->id, 'request_type' => 'VACATION_LEAVE', 'name' => 'Vacation Leave', 'days' => 2.5, 'purpose' => 'MONETIZATION', 'salary' => null])
        ->and($inputs->cashOutType)->toMatchArray(['type_id' => $type->id, 'name' => 'Leave credits cash-out', 'tax' => 'TAXABLE', 'is_taxable' => true, 'day_value' => 'FACTOR', 'factor' => 0.0481927])
        ->and($inputs->toArray()['schema_version'])->toBe(24)
        ->and(PayrollInputs::restore(PayrollInputs::json($inputs->toArray()))->fingerprint())->toBe($inputs->fingerprint());
    // Schema 13 evidence restores without the two keys; malformed cash-out evidence is refused.
    $older = $inputs->toArray();
    $older['schema_version'] = 13;
    $older = PayrollEvidenceFixture::beforeSchema17($older);
    unset($older['leave_cash_outs'], $older['cash_out_type'], $older['gov_policies'], $older['adjustments']);
    expect(PayrollInputs::restore(PayrollInputs::json($older))->leaveCashOuts)->toBe([]);
    $bad = $inputs->toArray();
    $bad['leave_cash_outs'][$employee->id][0]['days'] = 0;
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($bad)))->toThrow(RuntimeException::class, 'cash conversion evidence');

    // The marked type cannot be typed on a draft.
    expect(fn () => app(SavePayroll::class)->handle($reviewer, new PayrollDraft(null, 'Typed', '2026-09-01', '2026-09-15', [$employee->id], [], [], [], incentives: [$employee->id => [$type->id => 500]])))
        ->toThrow(RuntimeException::class, 'cannot be typed');

    // Finalize: the line posts, the request is PAID with the payslip and the pesos, and 2.5 days leave the ledger.
    $result = app(SavePayroll::class)->handle($reviewer, new PayrollDraft(null, 'September A', '2026-09-01', '2026-09-15', [$employee->id], [], [], []));
    $run = PayrollRun::query()->findOrFail($result['id']);
    app(FinalizePayroll::class)->handle($reviewer, $run->id, PayrollReviewFixture::token($reviewer, $run->id));
    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and($payslip->bonus)->toBe(3614.45)
        ->and(PayslipIncentive::query()->where('payslip_id', $payslip->id)->value('name'))->toBe('Leave credits cash-out · Vacation Leave 2.5 days')
        ->and($request->refresh()->status)->toBe('PAID')
        ->and($request->payslip_id)->toBe($payslip->id)
        ->and($request->amount)->toBe(3614.45)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(7.5)
        ->and(LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'CASH_OUT')->value('days'))->toBe(-2.5)
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->value('details'))->toContain('1 leave credit cash-out paid')
        ->and(AuditLog::query()->where('action', 'LEAVE_CASH_OUT_PAID')->value('details'))->toContain('Vacation Leave 2.5 days · PHP 3,614.45');
    $evidence = json_decode((string) PayrollFinalizationSnapshot::query()->where('run_id', $run->id)->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($evidence['inputs']['leave_cash_outs'][$employee->id][0]['id'])->toBe($request->id);

    // Paid once: the next payroll captures nothing, and My Portal shows it paid.
    expect(PayrollData::calculateDrafts([$employee->id], '2026-09-16', '2026-09-30', actor: $reviewer)['inputs']?->leaveCashOuts)->toBe([]);
    lcosSelf($employee);
    Livewire::test(PortalPage::class)->set('tab', 'requests')->assertSeeHtml('data-cash-out="PAID"')->assertSee('3,614.45');
});

it('migrates the cash-out table and flags twice without complaint', function () {
    $migration = require base_path('database/migrations/2026_10_31_0008_create_leave_cash_outs_table.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('leave_cash_outs'))->toBeTrue()
        ->and(Schema::hasColumn('leave_types', 'can_cash_out'))->toBeTrue()
        ->and(Schema::hasColumn('payroll_incentive_types', 'pays_leave_cash_out'))->toBeTrue()
        ->and(Schema::hasIndex('leave_cash_outs', 'lco_employee_status_idx'))->toBeTrue();
});
