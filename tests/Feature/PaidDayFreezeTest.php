<?php

declare(strict_types=1);

use App\Domain\Enums\PayrollRunKind;
use App\Livewire\Edtr\EdtrPage;
use App\Livewire\Leaves\Index as LeavesIndex;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\CorrectPunches;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * A day on a posted payslip is what payroll paid. Its overtime decision,
 * a schedule change and a leave cancellation were already refused there;
 * a punch correction is refused too, so the DTR never disagrees with the
 * payslip without anyone knowing. The dialog says so before anyone types.
 *
 * A leave or official business approved onto such days still lands — a
 * sick leave is often filed after the payroll — but the reviewer is warned
 * first, the audit sentence names the dates, and everyone holding Payroll
 * is told to settle the difference on the next payroll.
 *
 * The posted payslip below covers 1 to 15 September 2026.
 */
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-20T12:00:00+08:00'));
});

/** @param list<string> $modules */
function paidActor(array $modules, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Paid '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function paidEmployee(string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PAID-'.random_int(10000, 99999), 'first_name' => $first, 'last_name' => $last,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'monthly_rate' => 30000,
    ])->save();

    ScheduleFixture::standard($employee);

    return $employee;
}

function paidPunch(EmployeeProfile $employee, string $type, string $manilaStamp): AttendanceLog
{
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'WEB_GPS',
        'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant($manilaStamp)->utc(),
    ])->save();

    return $punch;
}

/** A payslip posted for 1–15 September: a legacy one with no run, or one of a run of the given kind. */
function paidPayslip(EmployeeProfile $employee, ?string $kind = null, string $start = '2026-09-01', string $end = '2026-09-15'): Payslip
{
    $runId = null;
    if ($kind !== null) {
        $run = PayrollRun::create(['code' => 'PR-'.Str::random(5), 'name' => 'Posted '.Str::random(5), 'status' => 'FINALIZED',
            'period_start' => $start, 'period_end' => $end, 'kind' => $kind]);
        $runId = $run->id;
    }
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $runId, 'employee_id' => (string) $employee->getKey(),
        'period_start' => $start, 'period_end' => $end, 'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();

    return $payslip;
}

/** @return array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string} */
function paidSlots(string $in, string $out): array
{
    return ['time_in' => $in, 'break_out' => null, 'break_in' => null, 'time_out' => $out];
}

/* ── punch corrections ──────────────────────────────────────────────────── */

it('refuses to correct a day a posted payslip covers', function (string $date) {
    $employee = paidEmployee();
    paidPunch($employee, 'TIME_IN', "{$date}T08:00:00");
    paidPunch($employee, 'TIME_OUT', "{$date}T17:00:00");
    paidPayslip($employee, PayrollRunKind::REGULAR);
    paidActor(['EDTR']);

    expect(fn () => CorrectPunches::apply(app(Access::class), (string) $employee->getKey(), $date, paidSlots('08:00', '19:00'), PunchSlots::SAME_DAY, 'Stayed late'))
        ->toThrow(RuntimeException::class, CorrectionWindow::PAID_DAY);
    expect(AttendanceLog::query()->count())->toBe(2)
        ->and(AttendanceLog::query()->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'DTR_CORRECTED')->count())->toBe(0);
})->with(['first day' => '2026-09-01', 'last day' => '2026-09-15']);

it('refuses it on a legacy payslip with no run as well', function () {
    $employee = paidEmployee();
    paidPayslip($employee);
    paidActor(['EDTR']);

    expect(fn () => CorrectPunches::apply(app(Access::class), (string) $employee->getKey(), '2026-09-08', paidSlots('08:00', '17:00'), PunchSlots::SAME_DAY, null))
        ->toThrow(RuntimeException::class, CorrectionWindow::PAID_DAY);
});

it('corrects the day after a posted period, and any day a 13th month payslip spans', function () {
    $employee = paidEmployee();
    paidPayslip($employee, PayrollRunKind::REGULAR);
    paidPayslip($employee, PayrollRunKind::THIRTEENTH_MONTH, '2026-01-01', '2026-12-31');
    paidActor(['EDTR']);

    CorrectPunches::apply(app(Access::class), (string) $employee->getKey(), '2026-09-16', paidSlots('08:00', '17:00'), PunchSlots::SAME_DAY, null);

    expect(AttendanceLog::query()->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(2);
});

it('shows a paid day in the correction dialog without a Save', function () {
    $employee = paidEmployee();
    paidPunch($employee, 'TIME_IN', '2026-09-08T08:00:00');
    paidPunch($employee, 'TIME_OUT', '2026-09-08T17:00:00');
    paidPayslip($employee, PayrollRunKind::REGULAR);
    paidActor(['EDTR']);

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->set('start', '2026-09-01')
        ->set('end', '2026-09-20')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey());

    $page->call('openCorrection', '2026-09-08')
        ->assertSet('correctionOpen', true)
        ->assertSee(CorrectionWindow::PAID_DAY)
        ->assertSeeHtml('data-correction-paid')
        ->assertDontSee('Save punches');

    $page->set('correctionOpen', false)
        ->call('openCorrection', '2026-09-16')
        ->assertDontSeeHtml('data-correction-paid')
        ->assertSee('Save punches');
});

/* ── leave and official business approvals ──────────────────────────────── */

/** @param array<string, mixed> $overrides */
function paidRequest(EmployeeProfile $employee, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => 'LR-PAID', 'employee_id' => $employee->getKey(), 'request_type' => 'SICK', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-09-14', 'end_date' => '2026-09-17', 'reason' => 'Flu', 'status' => 'PENDING',
    ], $overrides))->save();

    return $request;
}

/** Sick credits that cover the request: approval with pay pays what the credits cover. */
function paidSickCredits(EmployeeProfile $employee): void
{
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->getKey(), 'year' => 2026, 'request_type' => 'SICK', 'credits' => 10])->save();
}

/**
 * Someone holding Payroll, who should hear about it, and the reviewer who approves.
 *
 * @return array{officer: User, reviewer: User}
 */
function paidReviewers(): array
{
    $officer = paidActor(['PAYROLL']);
    $reviewer = paidActor(['LEAVES']);

    return ['officer' => $officer, 'reviewer' => $reviewer];
}

it('approves leave onto paid days, and says so to the reviewer, the audit trail and Payroll', function () {
    $employee = paidEmployee('Ramon', 'Aquino');
    paidPayslip($employee, PayrollRunKind::REGULAR);
    paidSickCredits($employee);
    $request = paidRequest($employee);
    ['officer' => $officer, 'reviewer' => $reviewer] = paidReviewers();

    Livewire::test(LeavesIndex::class)
        ->call('show', $request->getKey())
        ->assertSeeHtml('data-paid-dates-warning')
        ->assertSee('Sep 14, 2026, Sep 15, 2026')
        ->call('review', 'APPROVED', true)
        ->assertSet('detailOpen', false);

    expect($request->fresh()->status)->toBe('APPROVED')
        ->and(AuditLog::query()->where('action', 'REQUEST_APPROVED')->sole()->details)
        ->toBe('Request LR-PAID approved; already on a posted payslip: Sep 14, 2026, Sep 15, 2026');

    $told = Notification::query()->where('user_id', $officer->getKey())->sole();
    expect($told->kind)->toBe('LEAVE_APPROVED_ON_PAID_DAYS')
        ->and($told->title)->toBe('Request LR-PAID approved for days already paid')
        ->and($told->body)->toBe('Ramon Aquino: Sep 14, 2026, Sep 15, 2026 already on a posted payslip. Settle any difference on the next payroll.')
        // Every PAYROLL holder can open it; the Leaves page needs LEAVES.
        ->and($told->href)->toBe('/payroll')
        ->and(Notification::query()->where('user_id', $reviewer->getKey())->where('kind', 'LEAVE_APPROVED_ON_PAID_DAYS')->exists())->toBeFalse();
});

it('returns what it reached, naming the first dates and counting the rest', function () {
    $employee = paidEmployee();
    paidPayslip($employee, PayrollRunKind::REGULAR);
    $request = paidRequest($employee, ['start_date' => '2026-09-08', 'end_date' => '2026-09-16', 'request_type' => 'BUSINESS_TRAVEL']);
    paidReviewers();

    $outcome = app(ReviewAbsence::class)->handle(app(Access::class), $request->id, 'APPROVED');

    expect($outcome)->toBe('approved as official business. Sep 08, 2026, Sep 09, 2026, Sep 10, 2026 and 5 more are already on a posted payslip, so Payroll has been notified to settle it');
});

it('approves leave clear of any posted payslip exactly as before', function () {
    $employee = paidEmployee();
    paidPayslip($employee, PayrollRunKind::REGULAR);
    paidPayslip($employee, PayrollRunKind::THIRTEENTH_MONTH, '2026-01-01', '2026-12-31');
    paidSickCredits($employee);
    $request = paidRequest($employee, ['start_date' => '2026-09-16', 'end_date' => '2026-09-17']);
    paidReviewers();

    Livewire::test(LeavesIndex::class)
        ->call('show', $request->getKey())
        ->assertDontSeeHtml('data-paid-dates-warning');
    $outcome = app(ReviewAbsence::class)->handle(app(Access::class), $request->id, 'APPROVED', true);

    expect($outcome)->toBe('approved as paid leave')
        ->and(AuditLog::query()->where('action', 'REQUEST_APPROVED')->sole()->details)->toBe('Request LR-PAID approved')
        ->and(Notification::query()->where('kind', 'LEAVE_APPROVED_ON_PAID_DAYS')->exists())->toBeFalse();
});

it('disapproves a request onto paid days without telling Payroll', function () {
    $employee = paidEmployee();
    paidPayslip($employee, PayrollRunKind::REGULAR);
    $request = paidRequest($employee);
    paidReviewers();

    expect(app(ReviewAbsence::class)->handle(app(Access::class), $request->id, 'REJECTED', null, 'Already paid as an absence'))->toBe('disapproved')
        ->and(AuditLog::query()->where('action', 'REQUEST_REJECTED')->sole()->details)->toBe('Request LR-PAID disapproved')
        ->and(Notification::query()->where('kind', 'LEAVE_APPROVED_ON_PAID_DAYS')->exists())->toBeFalse();
});

it('still refuses a second review, and lets a reviewer approve their own request', function () {
    $employee = paidEmployee();
    paidPayslip($employee, PayrollRunKind::REGULAR);
    $request = paidRequest($employee);
    paidReviewers();
    app(ReviewAbsence::class)->handle(app(Access::class), $request->id, 'APPROVED', true);

    expect(fn () => app(ReviewAbsence::class)->handle(app(Access::class), $request->id, 'APPROVED', true))
        ->toThrow(RuntimeException::class, 'That request has already been reviewed.');

    // Their own request, outside the paid days: approved by the same all-data holder.
    $own = paidRequest($employee, ['code' => 'LR-OWN', 'start_date' => '2026-09-21', 'end_date' => '2026-09-21']);
    paidActor(['LEAVES'], (string) $employee->getKey());
    app(ReviewAbsence::class)->handle(app(Access::class), $own->id, 'APPROVED', true);
    expect($own->refresh()->status)->toBe('APPROVED')
        ->and(Notification::query()->where('kind', 'LEAVE_APPROVED_ON_PAID_DAYS')->count())->toBe(1);
});
