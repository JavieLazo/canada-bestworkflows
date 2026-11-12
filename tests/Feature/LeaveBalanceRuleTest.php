<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveAttendanceCharge;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveEarningPosting;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Timekeeping\Application\Commands\CancelAbsence;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Commands\RecordLeaveCredit;
use App\Modules\Timekeeping\Application\Commands\RequestLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\ReviewLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveLedgerRecords;
use App\Modules\Timekeeping\Contracts\LeaveCashOutPoster;
use App\Support\Access;
use Illuminate\Support\Str;
use Tests\Support\LeaveRuleFixture;

/**
 * THE ONE BALANCE RULE (LeaveBalances). Every reader of a leave credit —
 * filing, the review dialog, My Portal, the ledger, the earning job, the
 * payroll charge plan and the cash-out poster — judges the same number:
 * the allocation less the leave that draws on the year, less what unpaid
 * cash-outs have spoken for. Approving leave with pay freezes what it costs
 * on the request: the SCHEDULED WORKING DAYS it covers (rest days and
 * holidays cost nothing; the owner's decision, 2026-10-31), less the dates
 * a posted payroll already charged to credits as an absence, drawn on the
 * live year — so a December request approved in January, after the year
 * closed and its balance carried forward, draws on the new year and the
 * closed one never moves again. A cancellation gives those days back: the
 * frozen cost shrinks while the year is open, an ADJUSTMENT in the live
 * year once it has closed. Policy approvals are confirmed in a dialog that
 * says what the approval will post, and the preparer's own second account
 * may approve.
 */

/** @param list<string> $modules */
function lbrRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Lbr '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** An all-data leave and payroll manager, optionally linked; left signed in. */
function lbrManager(?string $employeeId = null): Access
{
    $user = User::factory()->create(['role_id' => lbrRole(['LEAVES', 'PAYROLL', 'RECORDS', 'EDTR'], true)->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** The employee's own portal sign-in; left signed in. */
function lbrSelf(EmployeeProfile $employee): Access
{
    $user = User::factory()->create(['role_id' => lbrRole(['PORTAL'], false)->getKey(), 'employee_id' => $employee->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** Monday to Friday, 08:00-17:00, PHP 30,000 a month. */
function lbrEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LBR-'.Str::random(6), 'first_name' => 'Nina', 'last_name' => 'Balance',
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

/** Vacation Leave earns and may be cashed out; the employee holds `$credits` of it for `$year`. */
function lbrVacation(Access $manager, EmployeeProfile $employee, float $credits = 10.0, int $year = 2026): LeaveType
{
    $type = LeaveType::query()->where('code', 'VL')->first();
    if ($type === null) {
        $type = new LeaveType;
        $type->forceFill(['code' => 'VL', 'name' => 'Vacation Leave'])->save();
        app(SaveLeaveTypeCredits::class)->handle($manager, $type->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => true, 'charge_order' => '1', 'below_zero_limit' => '0', 'can_cash_out' => true]);
    }
    $row = new LeaveCredit;
    $row->forceFill(['employee_id' => $employee->id, 'year' => $year, 'request_type' => 'VACATION_LEAVE', 'credits' => $credits])->save();

    return $type;
}

/**
 * A charge-free policy approved by a second manager, so the earning job walks the employee; the second manager is left signed in.
 *
 * @param  array<string, array<string, mixed>>  $earning
 */
function lbrPolicy(EmployeeProfile $employee, string $from = '2026-01-01', array $earning = []): string
{
    lbrManager();
    $id = LeaveRuleFixture::approved($from, [
        'posting_day' => 'MONTH_END', 'unpaid_counts' => [], 'earning' => $earning,
        'charges' => ['enabled' => false, 'combine' => 'DAY', 'late' => [], 'absence' => []],
        'cash_out_max_per_year' => '',
    ]);
    lbrManager();

    return $id;
}

/** @return array<string, float|null> */
function lbrRemaining(Access $actor, EmployeeProfile $employee, int $year): array
{
    $rows = app(LeaveCreditRecords::class)->balances($actor, $employee->id, $year);
    foreach ($rows as $row) {
        if ($row->request_type === 'VACATION_LEAVE') {
            return ['used' => $row->used, 'remaining' => $row->remaining, 'spoken_for' => $row->spoken_for, 'available' => $row->available];
        }
    }

    return [];
}

/*
|--------------------------------------------------------------------------
| Filing and approval
|--------------------------------------------------------------------------
*/

it('costs a request its scheduled working days, judges filing by what is available once cash-outs are honoured, and freezes the cost on approval', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $employee = lbrEmployee();
    $manager = lbrManager();
    lbrVacation($manager, $employee);
    app(SaveIncentiveType::class)->handle($manager, null, 'Leave credits cash-out', null, true, null, true);

    // 5 of the 10 are spoken for by an approved, unpaid cash-out.
    $self = lbrSelf($employee);
    app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '5', '');
    app(ReviewLeaveCashOut::class)->handle(lbrManager(), LeaveCashOut::query()->sole()->id, true);
    $self = lbrSelf($employee);
    expect(lbrRemaining($self, $employee, 2026))->toBe(['used' => 0.0, 'remaining' => 10.0, 'spoken_for' => 5.0, 'available' => 5.0]);

    // Two Monday-to-Friday weeks are 10 working days of 12 calendar days:
    // more than the 5 available, so the notice says 5 would be without pay
    // (the request is filed; it is withdrawn here to keep the dates free).
    expect(app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-21', '2026-10-02', 'trip'))
        ->toBe('Your available Vacation Leave credits (5.000) cover 5 days of the 10 days; the other 5 days will be unpaid if approved.');
    LeaveTravelRequest::query()->delete();
    // Monday to Sunday is 5 working days of 7: exactly what is available.
    expect(app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-21', '2026-09-27', 'trip'))->toBeNull();
    $request = LeaveTravelRequest::query()->sole();
    expect($request->credit_days)->toBeNull();

    $manager = lbrManager();
    expect(app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true))->toBe('approved as paid leave');
    $request->refresh();
    expect($request->credits_year)->toBe(2026)
        ->and($request->credit_days)->toBe(5.0)
        ->and($request->credit_dates)->toBe(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'])
        ->and(lbrRemaining($manager, $employee, 2026))->toBe(['used' => 5.0, 'remaining' => 5.0, 'spoken_for' => 5.0, 'available' => 0.0])
        ->and(LeaveBalances::balance($employee->id, 2026, 'VACATION_LEAVE'))->toBe(5.0)
        ->and(LeaveBalances::available($employee->id, 2026, 'VACATION_LEAVE'))->toBe(0.0);
    // The ledger counts the same five, split at today (all booked ahead).
    $ledger = app(LeaveLedgerRecords::class)->summary($manager, $employee->id, 2026)[0];
    expect([$ledger->paid_taken, $ledger->booked_paid, $ledger->available])->toBe([0.0, 5.0, 5.0]);

    // Nothing is left: one more day is filed, and would be without pay.
    $self = lbrSelf($employee);
    expect(app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-10-05', '2026-10-05', 'one more'))
        ->toBe('You have no Vacation Leave credits available to cover this request: the whole request (1 day) will be unpaid if approved.');
});

it('refuses to pay a cash-out at finalization once the credits behind it were used', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $employee = lbrEmployee();
    $manager = lbrManager();
    lbrVacation($manager, $employee);
    $self = lbrSelf($employee);
    app(RequestLeaveCashOut::class)->handle($self, 'VACATION_LEAVE', '5', '');
    $cashOut = LeaveCashOut::query()->sole();
    $reviewer = lbrManager();
    app(ReviewLeaveCashOut::class)->handle($reviewer, $cashOut->id, true);
    // HR corrects the allocation down to 2 after the approval.
    app(RecordLeaveCredit::class)->handle($reviewer, $employee->id, 2026, 'VACATION_LEAVE', 'ADJUSTMENT', '-8', 'Correction', (string) Str::ulid());

    expect(fn () => app(LeaveCashOutPoster::class)->post($reviewer, $employee->id, (string) Str::ulid(), 'PR-9', [['id' => $cashOut->id, 'days' => 5.0, 'amount' => 6818.18]]))
        ->toThrow(RuntimeException::class, 'Only 2.000 days of Vacation Leave are left to convert to cash for this employee');
    expect($cashOut->refresh()->status)->toBe(LeaveCashOut::APPROVED)
        ->and(LeaveCreditEntry::query()->where('kind', 'CASH_OUT')->count())->toBe(0);
});

it('does not charge a date a posted payroll already charged to credits as an absence', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-20T10:00:00+08:00'));
    $employee = lbrEmployee();
    $manager = lbrManager();
    lbrVacation($manager, $employee);
    // Payroll charged Monday 7 September as an absence and credits covered it.
    LeaveAttendanceCharge::create(['employee_id' => $employee->id, 'payslip_id' => null, 'date' => '2026-09-07', 'kind' => 'ABSENCE', 'minutes' => null, 'days' => 1.0, 'covered' => [['type' => 'VACATION_LEAVE', 'days' => 1.0, 'entry_id' => (string) Str::ulid()]], 'uncovered_days' => 0.0]);
    // ... and Tuesday 8 September too, but credits covered none of it.
    LeaveAttendanceCharge::create(['employee_id' => $employee->id, 'payslip_id' => null, 'date' => '2026-09-08', 'kind' => 'ABSENCE', 'minutes' => null, 'days' => 1.0, 'covered' => [], 'uncovered_days' => 1.0]);

    $self = lbrSelf($employee);
    $cost = app(LeaveBalances::class)->cost($employee->id, 'WHOLE_DAY', '2026-09-07', '2026-09-09');
    expect($cost)->toBe(['days' => 2.0, 'dates' => ['2026-09-08', '2026-09-09'], 'excluded' => ['2026-09-07']]);
    app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-07', '2026-09-09', 'sick after the fact');
    $request = LeaveTravelRequest::query()->sole();

    $outcome = app(ReviewAbsence::class)->handle(lbrManager(), $request->id, 'APPROVED', true);
    expect($outcome)->toBe('approved as paid leave. Sep 07, 2026 was already deducted from leave credits by payroll and is not deducted again')
        ->and($request->refresh()->credit_days)->toBe(2.0)
        ->and($request->credit_dates)->toBe(['2026-09-08', '2026-09-09'])
        ->and(AuditLog::query()->where('action', 'REQUEST_APPROVED')->value('details'))->toContain('already charged to leave credits by payroll, not charged again: Sep 07, 2026');
});

/*
|--------------------------------------------------------------------------
| Closed years
|--------------------------------------------------------------------------
*/

it('draws a December request approved in January on the new year, so the carried balance is never spent twice', function () {
    $this->travelTo(new DateTimeImmutable('2026-12-20T10:00:00+08:00'));
    $employee = lbrEmployee();
    $manager = lbrManager();
    lbrVacation($manager, $employee);
    lbrPolicy($employee);
    $self = lbrSelf($employee);
    app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-12-28', '2026-12-29', 'year end');
    $request = LeaveTravelRequest::query()->sole();

    // The year closes with the request still pending: all 10 carry.
    $this->travelTo(new DateTimeImmutable('2027-01-02T07:00:00+08:00'));
    app(PostLeaveEarnings::class)->run('2027-01-02');
    expect(LeaveCreditEntry::query()->where('kind', 'CARRY_FORWARD')->sole()->days)->toBe(10.0);

    // Approved in January, the request draws on 2027.
    $manager = lbrManager();
    app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true);
    expect($request->refresh()->credits_year)->toBe(2027)
        ->and($request->credit_days)->toBe(2.0)
        ->and(lbrRemaining($manager, $employee, 2026)['remaining'])->toBe(10.0)
        ->and(lbrRemaining($manager, $employee, 2027))->toBe(['used' => 2.0, 'remaining' => 8.0, 'spoken_for' => 0.0, 'available' => 8.0]);
    // A manual carry on top of the automatic one is refused.
    expect(fn () => app(RecordLeaveCredit::class)->handle($manager, $employee->id, 2027, 'VACATION_LEAVE', 'CARRY_FORWARD', '10', 'again', (string) Str::ulid()))
        ->toThrow(RuntimeException::class, "2026's balance was already carried forward into 2027 automatically. Record an adjustment instead.");
    // Filing in January against a December start draws on 2027 too: its 8.
    $self = lbrSelf($employee);
    expect(app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-12-30', '2027-01-15', 'late filing'))
        ->toStartWith('Your available Vacation Leave credits (8.000) cover 8 days of the');
});

it('returns the days of a cancellation to the live year as an adjustment once the request\'s year has closed', function () {
    $this->travelTo(new DateTimeImmutable('2026-12-20T10:00:00+08:00'));
    $employee = lbrEmployee();
    $manager = lbrManager();
    lbrVacation($manager, $employee);
    lbrPolicy($employee);
    $self = lbrSelf($employee);
    // Mon 28 Dec to Tue 5 Jan: 7 working days (no holidays are seeded).
    app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-12-28', '2027-01-05', 'holidays');
    $request = LeaveTravelRequest::query()->sole();
    $manager = lbrManager();
    app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true);
    expect($request->refresh()->credit_days)->toBe(7.0)->and($request->credits_year)->toBe(2026);

    $this->travelTo(new DateTimeImmutable('2027-01-02T07:00:00+08:00'));
    app(PostLeaveEarnings::class)->run('2027-01-02');
    expect(LeaveCreditEntry::query()->where('kind', 'CARRY_FORWARD')->sole()->days)->toBe(3.0);

    // Back early: Monday 4 and Tuesday 5 January come back to 2027, not 2026.
    $manager = lbrManager();
    app(CancelAbsence::class)->handle($manager, $request->id, ['2027-01-04', '2027-01-05'], 'Back early');
    $adjustment = LeaveCreditEntry::query()->where('kind', 'ADJUSTMENT')->sole();
    expect($adjustment->year)->toBe(2027)
        ->and($adjustment->days)->toBe(2.0)
        ->and($adjustment->note)->toContain('cancelled after 2026 closed')
        ->and($request->refresh()->credit_days)->toBe(7.0)
        ->and(lbrRemaining($manager, $employee, 2026)['remaining'])->toBe(3.0)
        ->and(lbrRemaining($manager, $employee, 2027)['remaining'])->toBe(5.0)
        ->and(AuditLog::query()->where('action', 'REQUEST_CANCELLED')->value('details'))->toContain('2 returned to 2027');
});

it('shrinks the frozen cost while the year is open and gives back only working days', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $employee = lbrEmployee();
    $manager = lbrManager();
    lbrVacation($manager, $employee);
    $self = lbrSelf($employee);
    app(FileAbsence::class)->handle($self, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-21', '2026-09-27', 'week');
    $request = LeaveTravelRequest::query()->sole();
    $manager = lbrManager();
    app(ReviewAbsence::class)->handle($manager, $request->id, 'APPROVED', true);

    // Cancelling Friday and the weekend gives back one day: Saturday and Sunday never cost one.
    app(CancelAbsence::class)->handle($manager, $request->id, ['2026-09-25', '2026-09-26', '2026-09-27'], 'Back on Friday');
    expect($request->refresh()->credit_days)->toBe(4.0)
        ->and($request->credit_dates)->toBe(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24'])
        ->and(lbrRemaining($manager, $employee, 2026)['remaining'])->toBe(6.0)
        ->and(LeaveCreditEntry::query()->where('kind', 'ADJUSTMENT')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The earning job
|--------------------------------------------------------------------------
*/

it('stops earning a type HR un-ticked, skips an inactive record with no resigned date, and needs a leave manager when run by hand', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T10:00:00+08:00'));
    $employee = lbrEmployee();
    $manager = lbrManager();
    $type = lbrVacation($manager, $employee, 0.0);
    lbrPolicy($employee, '2026-07-01', ['VACATION_LEAVE' => ['amount' => '1.25', 'method' => 'FLAT', 'table' => [], 'partial' => 'FULL', 'cap' => null]]);

    $result = app(PostLeaveEarnings::class)->run('2026-09-10');
    expect($result['posted'])->toBe(2)
        ->and(LeaveEarningPosting::query()->whereIn('period', ['2026-07', '2026-08'])->count())->toBe(2);

    // Un-ticked: September posts nothing, and the job says so with a row of 0? No — it posts no row at all for the type.
    app(SaveLeaveTypeCredits::class)->handle(lbrManager(), $type->id, ['earns_credits' => false, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => true, 'charge_order' => '1', 'below_zero_limit' => '0', 'can_cash_out' => true]);
    $this->travelTo(new DateTimeImmutable('2026-10-02T07:00:00+08:00'));
    expect(app(PostLeaveEarnings::class)->run('2026-10-02')['posted'])->toBe(0)
        ->and(LeaveEarningPosting::query()->where('period', '2026-09')->exists())->toBeFalse();

    // Inactive without a Date Resigned: skipped and named, nothing posted.
    app(SaveLeaveTypeCredits::class)->handle(lbrManager(), $type->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => false, 'charge_absence' => true, 'charge_order' => '1', 'below_zero_limit' => '0', 'can_cash_out' => true]);
    $employee->forceFill(['is_active' => false])->save();
    $result = app(PostLeaveEarnings::class)->run('2026-10-02');
    expect($result['posted'])->toBe(0)
        ->and($result['skipped'])->toBe([$employee->id.': inactive without a Date Resigned']);

    // A person running it must manage leave for everyone.
    $viewer = lbrSelf($employee);
    expect(fn () => app(PostLeaveEarnings::class)->run('2026-10-02', $viewer))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
});
