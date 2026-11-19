<?php

declare(strict_types=1);

use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Payroll\Support\PayslipDocument;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveAttendanceCharge;
use App\Models\LeaveConversionTable;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveEarningPosting;
use App\Models\LeaveType;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Modules\Timekeeping\Contracts\LeaveChargeSource;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\LeaveRuleFixture;
use Tests\Support\PayrollReviewFixture;

/**
 * Attendance charged to leave credits (DELIBERATE DIVERGENCE 15). A payroll
 * charges each absent day and each day's lateness and undertime to the
 * employee's leave credits first, by the approved policy in force and the
 * conversion table in use, and prices only what credits did not cover to
 * pay. The facts are captured with the run, so a changed balance stales the
 * preview; finalizing posts one charge row per day, a CHARGE ledger entry
 * per leave type and the allocation moved, and a day is never charged
 * twice. The earning job still counts a covered absence as a day without
 * pay: an absence is poor attendance whether credits or pay bore it.
 *
 * September 2026 has 22 scheduled Monday-to-Friday days: PHP 30,000 a
 * month is PHP 1,363.636… a day, PHP 170.4545… an hour. The period is 1 to
 * 15 September; the employee is absent on the 3rd and the 10th and an hour
 * late on the 2nd.
 */

/** @param list<string> $modules */
function lchgActor(array $modules = ['PAYROLL', 'EDTR', 'LEAVES', 'RECORDS']): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Lchg '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** Monday to Friday, 08:00-17:00 with a 60-minute unpaid break, PHP 30,000 a month. */
function lchgEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LCHG-'.random_int(1000, 9999), 'first_name' => 'Nina', 'last_name' => 'Charged',
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

function lchgPunch(EmployeeProfile $employee, string $type, string $manilaStamp): void
{
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
        'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant($manilaStamp)->utc(),
    ])->save();
}

/** Full days 1 to 15 September except the 3rd and the 10th; an hour late on the 2nd. */
function lchgAttendance(EmployeeProfile $employee): void
{
    foreach (['01', '02', '04', '07', '08', '09', '11', '14', '15'] as $day) {
        lchgPunch($employee, 'TIME_IN', "2026-09-{$day}T".($day === '02' ? '09:00' : '08:00').':00');
        lchgPunch($employee, 'TIME_OUT', "2026-09-{$day}T17:00:00");
    }
}

/**
 * Vacation Leave (charged for lateness and absences, first) and Sick Leave
 * (absences only, second); the employee's approved policy charging both;
 * credits for 2026. The conversion table is fixed: nothing sets it up.
 */
function lchgSetup(Access $actor, EmployeeProfile $employee, float $vacation = 3.0, float $sick = 1.0, string $combine = 'DAY'): void
{
    foreach ([['VL', 'Vacation Leave', true, '1'], ['SL', 'Sick Leave', false, '2']] as [$code, $name, $late, $order]) {
        $type = new LeaveType;
        $type->forceFill(['code' => $code, 'name' => $name])->save();
        app(SaveLeaveTypeCredits::class)->handle($actor, $type->id, ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => $late, 'charge_absence' => true, 'charge_order' => $order, 'below_zero_limit' => '0']);
    }
    LeaveRuleFixture::approved('2026-01-01', [
        'posting_day' => 'MONTH_END', 'unpaid_counts' => ['UNPAID_LEAVE', 'ABSENCE'],
        'earning' => [
            'VACATION_LEAVE' => ['amount' => '1.25', 'method' => 'FLAT', 'table' => [], 'partial' => 'PRO_RATA', 'cap' => null],
            'SICK_LEAVE' => ['amount' => '1.25', 'method' => 'TABLE', 'table' => [['up_to' => '0', 'credits' => '1.25'], ['up_to' => '2', 'credits' => '1']], 'partial' => 'PRO_RATA', 'cap' => null],
        ],
        'charges' => ['enabled' => true, 'combine' => $combine, 'late' => ['VACATION_LEAVE'], 'absence' => ['VACATION_LEAVE', 'SICK_LEAVE']],
    ]);
    foreach (['VACATION_LEAVE' => $vacation, 'SICK_LEAVE' => $sick] as $token => $credits) {
        $row = new LeaveCredit;
        $row->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => $token, 'credits' => $credits])->save();
    }
    test()->actingAs(User::query()->findOrFail((int) $actor->userId));
    app()->forgetInstance(Access::class);
}

function lchgDraft(Access $actor, EmployeeProfile $employee, string $start = '2026-09-01', string $end = '2026-09-15'): PayrollRun
{
    $result = app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'Charged '.Str::random(4), $start, $end, [(string) $employee->getKey()], [], [], []));

    return PayrollRun::query()->findOrFail($result['id']);
}

/*
|--------------------------------------------------------------------------
| The flow
|--------------------------------------------------------------------------
*/

it('charges absences and lateness to credits on the draft, posts them at finalization, and never twice', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-16T10:00:00+08:00'));
    $actor = lchgActor();
    $employee = lchgEmployee();
    lchgSetup($actor, $employee);
    lchgAttendance($employee);

    // The capture: the policy's rules, the table and the balances as they stand.
    $facts = app(LeaveChargeSource::class)->forPayroll($actor, [$employee->id], '2026-09-01', '2026-09-15');
    expect($facts[$employee->id]['charges'])->toMatchArray(['enabled' => true, 'combine' => 'DAY', 'late' => ['VACATION_LEAVE'], 'absence' => ['VACATION_LEAVE', 'SICK_LEAVE']])
        ->and($facts[$employee->id]['balances']['VACATION_LEAVE'])->toMatchArray(['name' => 'Vacation Leave', 'available' => 3.0, 'below_zero_limit' => 0.0])
        ->and($facts[$employee->id]['conversion']['decimals'])->toBe(3)
        ->and($facts[$employee->id]['year'])->toBe(2026)
        ->and($facts[$employee->id]['charged'])->toBe(['late' => [], 'absence' => []]);

    $run = lchgDraft($actor, $employee);
    $calculation = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $draft = $calculation['drafts'][0];
    $plan = $calculation['leaveChargeLines'][$employee->id];
    // Absences first (3rd, 10th: Vacation Leave), then the hour late on the 2nd (0.125 days, Vacation Leave).
    expect(array_map(static fn (array $l): string => $l['date'].' '.$l['kind'], $plan['lines']))->toBe(['2026-09-03 ABSENCE', '2026-09-10 ABSENCE', '2026-09-02 LATE'])
        ->and($plan['lines'][0]['covered'])->toBe(['VACATION_LEAVE' => 1.0])
        ->and($plan['lines'][2])->toMatchArray(['minutes' => 60, 'days' => 0.125, 'covered' => ['VACATION_LEAVE' => 0.125], 'uncovered_days' => 0.0])
        ->and($plan['balances'])->toBe(['VACATION_LEAVE' => 0.875, 'SICK_LEAVE' => 1.0])
        ->and($draft->leave_charged_days)->toBe(2.125)
        ->and($draft->absence_days)->toBe(2.0)
        ->and($draft->paid_leave_days)->toBe(2.0)
        ->and($draft->leave_pay)->toBe(2727.27)
        ->and($draft->late_minutes)->toBe(0.0)
        ->and($draft->late_deduction)->toBe(0.0)
        ->and($calculation['inputs']?->toArray()['leave_charges'][$employee->id]['balances']['VACATION_LEAVE']['available'])->toBe(3.0);

    // The grid shows the figure and explains it.
    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSeeHtml('data-leave-charged="'.$employee->id.'"')
        ->assertSee('Credits Deducted')
        ->call('openWhy', $employee->id, 'leave')
        ->assertSee('Leave Credits Deducted · Nina Charged')
        ->assertSee('Sep 03, 2026 · absence')
        ->assertSee('Deducted from Vacation Leave 1 credit')
        ->assertSee('Lateness and undertime of 60 min, 0.125 credits by the conversion table');
    $page->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED');

    // Posted: the payslip, the charge rows, the ledger and the allocation.
    $payslip = Payslip::query()->where('run_id', $run->id)->sole();
    expect($payslip->leave_charged_days)->toBe(2.125)
        ->and($payslip->late_deduction)->toBe(0.0)
        ->and($payslip->leave_pay)->toBe(2727.27);
    $charges = LeaveAttendanceCharge::query()->where('employee_id', $employee->id)->orderBy('date')->get();
    expect($charges)->toHaveCount(3)
        ->and($charges[0]->kind)->toBe('LATE')->and($charges[0]->minutes)->toBe(60)->and($charges[0]->days)->toBe(0.125)->and($charges[0]->payslip_id)->toBe($payslip->id)
        ->and($charges[0]->covered[0])->toMatchArray(['type' => 'VACATION_LEAVE', 'days' => 0.125])
        ->and($charges[1]->kind)->toBe('ABSENCE')->and($charges[1]->uncovered_days)->toBe(0.0);
    $entries = LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'CHARGE')->get();
    expect($entries)->toHaveCount(3)
        ->and((float) $entries->sum('days'))->toBe(-2.125)
        ->and($entries->first(fn ($e) => $e->days === -0.125)?->note)->toBe('Lateness and undertime of 60 min on 2026-09-02 · payroll '.$run->code)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(0.875)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('request_type', 'SICK_LEAVE')->value('credits'))->toBe(1.0)
        ->and(AuditLog::query()->where('action', 'LEAVE_CHARGED')->value('details'))->toBe('Payroll '.$run->code.': Vacation Leave −2.125 charged to leave credits (0.125 for lateness and undertime, 2 for absences)')
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->value('details'))->toContain('2.1250 days of leave credits charged for 1 employee');

    // The evidence carries the plan and the captured facts; the payslip document names the days.
    $evidence = json_decode((string) PayrollFinalizationSnapshot::query()->where('run_id', $run->id)->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($evidence['inputs']['schema_version'])->toBe(PayrollInputs::SCHEMA_VERSION)
        ->and($evidence['leave_charges'][$employee->id]['lines'])->toHaveCount(3)
        ->and(PayrollInputs::restore(PayrollInputs::json($evidence['inputs']))->leaveCharges[$employee->id]['year'])->toBe(2026);
    $document = PayslipDocument::issued(app(PayslipRecords::class)->find($actor, $run->id, $employee->id), $employee, false, [], [], collect());
    expect(array_column($document['basis'], 'label'))->toContain('Leave credits deducted');

    // Never twice: the charged dates are in the next capture, and the plan skips them.
    $again = app(LeaveChargeSource::class)->forPayroll($actor, [$employee->id], '2026-09-01', '2026-09-15')[$employee->id];
    expect($again['charged'])->toBe(['late' => ['2026-09-02'], 'absence' => ['2026-09-03', '2026-09-10']])
        ->and($again['balances']['VACATION_LEAVE']['available'])->toBe(0.875)
        ->and(app(LeaveChargeSource::class)->plan($again, $plan['lines'] === [] ? [] : [
            ['date' => '2026-09-03', 'late_minutes' => 0.0, 'undertime_minutes' => 0.0, 'absence_days' => 1.0],
            ['date' => '2026-09-02', 'late_minutes' => 60.0, 'undertime_minutes' => 0.0, 'absence_days' => 0.0],
        ])['lines'])->toBe([]);

    // The earning job counts the two covered absences as days without pay like any other:
    // September's 13 absences are the 3rd and the 10th plus the 11 unpunched days after the 15th.
    $this->travelTo(new DateTimeImmutable('2026-10-02T10:00:00+08:00'));
    app(PostLeaveEarnings::class)->run('2026-10-02', $actor);
    $september = LeaveEarningPosting::query()->where('employee_id', $employee->id)->where('period', '2026-09')->where('request_type', 'SICK_LEAVE')->sole();
    expect($september->inputs['absences'])->toBe(13)->and($september->inputs['unpaid'])->toBe(13)->and($september->inputs['present'])->toBe(9)->and($september->days)->toBe(0.0);
});

it('leaves what credits cannot cover to pay, and stales the preview when a balance changes', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-16T10:00:00+08:00'));
    $actor = lchgActor();
    $employee = lchgEmployee();
    // Vacation Leave covers one absence and nothing more; Sick Leave half of the second.
    lchgSetup($actor, $employee, vacation: 1.0, sick: 0.5);
    lchgAttendance($employee);
    $run = lchgDraft($actor, $employee);

    $draft = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['drafts'][0];
    // 1.5 of the two absent days paid as leave, the hour late priced in full.
    expect($draft->leave_charged_days)->toBe(1.5)
        ->and($draft->paid_leave_days)->toBe(1.5)
        ->and($draft->leave_pay)->toBe(2045.45)
        ->and($draft->late_minutes)->toBe(60.0)
        ->and($draft->late_deduction)->toBe(170.45);

    // The receipt was given on these balances; HR enters more credits, and the finalize refuses the old receipt.
    $receipt = PayrollReviewFixture::token($actor, $run->id);
    LeaveCredit::query()->where('employee_id', $employee->id)->where('request_type', 'VACATION_LEAVE')->update(['credits' => 5.0]);
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))->toThrow(RuntimeException::class);
    expect(Payslip::query()->count())->toBe(0)->and(LeaveAttendanceCharge::query()->count())->toBe(0);

    // Reviewed again on the new balances: everything covered.
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    expect(Payslip::query()->sole()->leave_charged_days)->toBe(2.125)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(2.875)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('request_type', 'SICK_LEAVE')->value('credits'))->toBe(0.5);
});

it('charges the live year: a December cut-off finalized after the year closed moves the new year\'s balance', function () {
    $this->travelTo(new DateTimeImmutable('2027-01-20T10:00:00+08:00'));
    $actor = lchgActor();
    $employee = lchgEmployee();
    lchgSetup($actor, $employee);
    // December 16 to 31: at work every scheduled day but Thursday the 17th.
    foreach (['16', '18', '21', '22', '23', '24', '25', '28', '29', '30', '31'] as $day) {
        lchgPunch($employee, 'TIME_IN', "2026-12-{$day}T08:00:00");
        lchgPunch($employee, 'TIME_OUT', "2026-12-{$day}T17:00:00");
    }

    // The earning job has run on the 20th: every month of 2026 posted (Vacation Leave 12 × 1.25 on the 3 held) and the year closed, so the balance now lives in 2027.
    app(PostLeaveEarnings::class)->run('2027-01-20', $actor);
    expect(LeaveEarningPosting::query()->where('employee_id', $employee->id)->where('period', '2026-YE')->where('request_type', 'VACATION_LEAVE')->value('days'))->toBe(18.0)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2027)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(18.0);

    // The December payroll charges 2027, where the balance is — not the closed 2026 row.
    $facts = app(LeaveChargeSource::class)->forPayroll($actor, [$employee->id], '2026-12-16', '2026-12-31');
    expect($facts[$employee->id]['year'])->toBe(2027)
        ->and($facts[$employee->id]['balances']['VACATION_LEAVE']['available'])->toBe(18.0);
    $run = lchgDraft($actor, $employee, '2026-12-16', '2026-12-31');
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and(Payslip::query()->where('run_id', $run->id)->value('leave_charged_days'))->toBe(1.0)
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2027)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(17.0)
        // The closed year is history: its row keeps the balance it closed at.
        ->and(LeaveCredit::query()->where('employee_id', $employee->id)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(18.0)
        ->and(LeaveCreditEntry::query()->where('employee_id', $employee->id)->where('kind', 'CHARGE')->where('year', 2027)->count())->toBe(1);
});

it('charges nothing for an employee without a policy, and the engine is the reference for them', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-16T10:00:00+08:00'));
    $actor = lchgActor();
    $employee = lchgEmployee();
    lchgAttendance($employee);
    $run = lchgDraft($actor, $employee);
    $calculation = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($calculation['leaveChargeLines'])->toBe([])
        ->and($calculation['inputs']?->leaveCharges)->toBe([])
        ->and($calculation['drafts'][0]->leave_charged_days)->toBe(0.0)
        ->and($calculation['drafts'][0]->late_deduction)->toBe(170.45)
        ->and($calculation['drafts'][0]->absence_days)->toBe(2.0);
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');
    expect(Payslip::query()->sole()->leave_charged_days)->toBe(0.0)->and(LeaveAttendanceCharge::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/

it('migrates the charges table and the payslip column twice without complaint', function () {
    $m = require base_path('database/migrations/2026_10_31_0006_create_leave_attendance_charges_table.php');
    $m->up();
    $m->up();
    expect(Schema::hasTable('leave_attendance_charges'))->toBeTrue()->and(Schema::hasColumn('payslips', 'leave_charged_days'))->toBeTrue();
});

it('converts with the fixed table, never with a stored one, and the run captures that table', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-16T10:00:00+08:00'));
    $actor = lchgActor();
    $employee = lchgEmployee();
    lchgSetup($actor, $employee, vacation: 5.0, sick: 1.0);
    lchgAttendance($employee);
    // A table an earlier build saved, its 1-hour row changed by hand: history only.
    LeaveConversionTable::query()->delete();
    $stored = new LeaveConversionTable;
    $stored->forceFill([
        'effective_from' => '2026-01-01', 'hours_per_day' => 8, 'decimals' => 3, 'rounding' => 'HALF_DOWN',
        'rows' => ['minutes' => MinuteConversion::rows(8, 3, 'HALF_DOWN')['minutes'], 'hours' => [...MinuteConversion::rows(8, 3, 'HALF_DOWN')['hours'], 1 => '0.200']],
        'reference' => 'Our own', 'status' => 'APPROVED', 'approved_at' => now('UTC'),
    ])->save();
    $run = lchgDraft($actor, $employee);

    // The hour late on the 2nd converts by the fixed table's 1-hour row, 0.125, not the stored 0.200.
    $calculation = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $late = array_values(array_filter($calculation['leaveChargeLines'][$employee->id]['lines'], static fn (array $l): bool => $l['kind'] === 'LATE'));
    expect($late[0])->toMatchArray(['date' => '2026-09-02', 'minutes' => 60, 'days' => 0.125])
        ->and($calculation['inputs']?->toArray()['leave_charges'][$employee->id]['conversion'])->toMatchArray([
            'version_id' => 'CSC_STANDARD', 'hours_per_day' => 8.0, 'decimals' => 3, 'rows' => MinuteConversion::rows(8, 3, 'HALF_DOWN'),
        ]);

    // Finalized, the payslip and the charge rows carry that conversion.
    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));
    expect(Payslip::query()->sole()->leave_charged_days)->toBe(2.125)
        ->and(LeaveAttendanceCharge::query()->where('kind', 'LATE')->sole()->days)->toBe(0.125);
});
