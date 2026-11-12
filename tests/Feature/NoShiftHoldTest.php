<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index as LeavesPage;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Payroll\ThirteenthMonthWorkspace;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\ScheduleHold;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\RecommendAbsence;
use App\Modules\Timekeeping\Application\Commands\ReviewAbsence;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollReviewFixture;
use Tests\Support\ScheduleFixture;

/**
 * A date with NO SHIFT — nothing decides its hours, so the engine's own
 * default week stands in — holds two decisions until the schedule is set:
 * finalizing a regular payroll that reaches it inside a member's employment
 * (ScheduleHold, in FinalizePayroll), and approving a leave or official
 * business that covers it (LeaveScheduleHold, in ReviewAbsence). Each is
 * refused by the command itself, called without a screen, and shown on the
 * screen before the click; a 13th month payroll, a disapproval and a
 * recommendation are never held. A rest day is a schedule, not a hold.
 */
/** @param list<string> $modules */
function nshActor(array $modules = ['PAYROLL', 'ROSTER', 'LEAVES'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'No shift '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/**
 * Paid by the month with no contributions or tax, so a period with no
 * punches posts at zero instead of tripping the deductions-over-gross check.
 */
function nshEmployee(string $first, string $last, string $hired = '2024-01-01', ?string $resigned = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'NSH-'.Str::random(6), 'first_name' => $first, 'last_name' => $last,
        'employment_status' => 'Regular', 'date_hired' => $hired, 'date_resigned' => $resigned, 'is_active' => true,
        'monthly_rate' => 30000, 'allow_sss' => false, 'allow_philhealth' => false, 'allow_pagibig' => false, 'allow_withholding_tax' => false,
    ])->save();

    return $employee;
}

/** @param list<EmployeeProfile> $members */
function nshRun(array $members, string $kind = 'REGULAR', string $start = '2026-09-01', string $end = '2026-09-15'): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-'.Str::random(5), 'name' => 'Hold '.$kind.' '.Str::random(5), 'status' => 'DRAFT', 'kind' => $kind,
        'period_start' => $start, 'period_end' => $end])->save();
    foreach ($members as $i => $member) {
        PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $member->id, 'position' => $i,
            'period_start' => $start, 'period_end' => $end]);
    }

    return $run;
}

function nshPattern(): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => 'Day', 'mode' => 'FIXED', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 15, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

function nshLeave(EmployeeProfile $employee, string $type = 'VACATION_LEAVE', string $start = '2026-09-21', string $end = '2026-09-23'): LeaveTravelRequest
{
    return LeaveTravelRequest::create([
        'code' => 'NSH-'.Str::random(5), 'employee_id' => $employee->id, 'request_type' => $type,
        'day_type' => 'WHOLE_DAY', 'start_date' => $start, 'end_date' => $end, 'status' => 'PENDING', 'reason' => 'Hold',
    ]);
}

/** Whether the first button whose tag matches carries a `disabled` attribute of its own (not `wire:loading.attr="disabled"`). */
function nshButtonDisabled(string $html, string $marker): bool
{
    preg_match('/<button[^>]*'.$marker.'[^>]*>/', $html, $match);
    expect($match)->not->toBe([]);

    return preg_match('/\sdisabled(?:="[^"]*")?(?=[\s>\/])/', $match[0]) === 1;
}

beforeEach(function () {
    // Sunday 2026-09-20: the 1st–15th cut-off is over.
    $this->travelTo(new DateTimeImmutable('2026-09-20T09:00:00+08:00'));
});

/* |---| Finalizing a payroll |---| */

it('refuses to finalize a regular payroll while a member has dates with no shift, naming them, and finalizes it once the schedule is set', function () {
    $ana = nshEmployee('Ana', 'Cruz');
    ScheduleFixture::standard($ana);
    $ben = nshEmployee('Ben', 'Diaz');
    $cid = nshEmployee('Cid', 'Abad');
    $actor = nshActor(['PAYROLL']);
    $run = nshRun([$ana, $ben, $cid]);

    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))
        ->toThrow(RuntimeException::class, 'Some dates in this period have no shift for Ben Diaz (15 days), Cid Abad (15 days). Set their schedule in Shift Roster before finalizing.')
        ->and($run->fresh()->status)->toBe('DRAFT')
        ->and(Payslip::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->count())->toBe(0);

    // Only Cid is left: the message names who is still held.
    ScheduleFixture::standard($ben);
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))
        ->toThrow(RuntimeException::class, 'Some dates in this period have no shift for Cid Abad (15 days).');

    ScheduleFixture::standard($cid);
    expect(app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))->toBe(3)
        ->and($run->fresh()->status)->toBe('FINALIZED');
});

it('counts only the dates inside each member\'s employment, and every date of the period', function () {
    $actor = nshActor(['PAYROLL']);
    $joined = nshEmployee('Joy', 'Bago', hired: '2026-09-10');
    $left = nshEmployee('Lito', 'Alis', resigned: '2026-09-05');
    $after = nshEmployee('Nat', 'Huli', hired: '2026-09-16');
    $members = app(EmployeePayContexts::class)->get($actor, [$joined->id, $left->id, $after->id]);

    $days = ScheduleHold::noShiftDays($actor, $members, '2026-09-01', '2026-09-15');
    ksort($days);
    $expected = [$joined->id => 6, $left->id => 5];
    ksort($expected);
    expect($days)->toBe($expected);

    // A period still running counts its days ahead too: they are paid as scheduled.
    $ahead = ScheduleHold::noShiftDays($actor, app(EmployeePayContexts::class)->get($actor, [$after->id]), '2026-09-16', '2026-09-30');
    expect($ahead)->toBe([$after->id => 15]);
});

it('finalizes a member whose only dates with no shift fall before they were hired', function () {
    // Monday 2026-09-07 at 05:00: both start on the Day shift from today.
    $this->travelTo(new DateTimeImmutable('2026-09-07T05:00:00+08:00'));
    $actor = nshActor(['PAYROLL', 'ROSTER']);
    $pattern = nshPattern();
    $hired = nshEmployee('Hana', 'Bago', hired: '2026-09-07');
    $veteran = nshEmployee('Vic', 'Luma');
    app(AssignShift::class)->handle($actor, $hired->id, $pattern->id);
    app(AssignShift::class)->handle($actor, $veteran->id, $pattern->id);
    $this->travelTo(new DateTimeImmutable('2026-09-20T09:00:00+08:00'));

    $held = nshRun([$veteran]);
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $held->id, PayrollReviewFixture::token($actor, $held->id)))
        ->toThrow(RuntimeException::class, 'Some dates in this period have no shift for Vic Luma (6 days).');

    $run = nshRun([$hired], start: '2026-09-01', end: '2026-09-15');
    expect(app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))->toBe(1);
});

it('never holds a 13th month payroll, which prices no attendance', function () {
    $this->travelTo(new DateTimeImmutable('2026-12-20T12:00:00+08:00'));
    $employee = nshEmployee('Tess', 'Trese');
    $regular = new PayrollRun;
    $regular->forceFill(['code' => 'PR-R', 'name' => 'Posted', 'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'FINALIZED', 'kind' => 'REGULAR'])->save();
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $regular->id, 'employee_id' => $employee->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-15',
        'work_days_pay' => 120_000, 'leave_pay' => 0, 'gross_pay' => 120_000, 'total_deductions' => 0, 'net_pay' => 120_000, 'taxable_compensation' => 0, 'exempt_benefits' => 0])->save();
    nshActor(['PAYROLL']);
    $run = nshRun([$employee], 'THIRTEENTH_MONTH', '2026-01-01', '2026-12-31');

    Livewire::test(ThirteenthMonthWorkspace::class, ['id' => $run->id])
        ->assertDontSee('have no shift for')
        ->call('finalize');

    expect($run->fresh()->status)->toBe('FINALIZED');
});

it('shows the hold on the payroll workspace and keeps Finalize off until the schedule is set', function () {
    $ben = nshEmployee('Ben', 'Diaz');
    nshActor(['PAYROLL']);
    $run = nshRun([$ben]);

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSee('data-no-shift-hold', false)
        ->assertSee('Some dates in this period have no shift for')
        ->assertSee('Ben Diaz (15 days)')
        ->assertDontSee('Check their Daily Time Record');
    expect(nshButtonDisabled($page->html(), 'wire:click="finalize"'))->toBeTrue();
    // The link to the roster is drawn only for whoever may open it.
    expect($page->html())->not->toContain(route('roster.index'));

    $page->call('finalize');
    expect($run->fresh()->status)->toBe('DRAFT');

    ScheduleFixture::standard($ben);
    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])->assertDontSee('data-no-shift-hold', false);
    expect(nshButtonDisabled($page->html(), 'wire:click="finalize"'))->toBeFalse();
});

it('links the hold to the Shift Roster for a payroll officer who may open it', function () {
    $ben = nshEmployee('Ben', 'Diaz');
    nshActor(['PAYROLL', 'ROSTER']);
    $run = nshRun([$ben]);

    expect(Livewire::test(RunWorkspace::class, ['id' => $run->id])->html())->toContain(route('roster.index'));
});

/* |---| Approving a leave |---| */

it('refuses to approve a leave with or without pay, or official business, while a date has no shift', function (string $type, ?bool $isPaid) {
    $employee = nshEmployee('Ana', 'Cruz');
    $actor = nshActor(['LEAVES']);
    $leave = nshLeave($employee, $type);

    expect(fn () => app(ReviewAbsence::class)->handle($actor, $leave->id, 'APPROVED', $isPaid))
        ->toThrow(RuntimeException::class, 'Ana Cruz has no shift on Sep 21–23, 2026. Set the schedule in Shift Roster before approving.');
    $fresh = $leave->fresh();
    expect($fresh->status)->toBe('PENDING')
        ->and($fresh->reviewed_by)->toBeNull()
        ->and(AuditLog::query()->where('action', 'REQUEST_APPROVED')->count())->toBe(0);

    ScheduleFixture::standard($employee);
    app(ReviewAbsence::class)->handle($actor, $leave->id, 'APPROVED', $isPaid);
    expect($leave->fresh()->status)->toBe('APPROVED');
})->with([
    'with pay' => ['VACATION_LEAVE', true],
    'without pay' => ['VACATION_LEAVE', false],
    'official business' => ['BUSINESS_TRAVEL', null],
]);

it('names only the dates with no shift, and a rest day is a schedule', function () {
    $employee = nshEmployee('Ana', 'Cruz');
    $actor = nshActor(['LEAVES', 'ROSTER']);
    // Monday the 21st has a temporary schedule and Tuesday the 22nd a
    // temporary Rest day; nothing decides the rest.
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-09-21', '2026-09-21', nshPattern()->id, 'DEFAULT', confirmed: DatedChange::ACCEPT);
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-09-22', '2026-09-22', 'REST', 'DEFAULT', confirmed: DatedChange::ACCEPT);
    $leave = nshLeave($employee, start: '2026-09-21', end: '2026-09-24');

    expect(fn () => app(ReviewAbsence::class)->handle($actor, $leave->id, 'APPROVED', false))
        ->toThrow(RuntimeException::class, 'Ana Cruz has no shift on Sep 23–24, 2026.');
});

it('still disapproves and recommends a request held for no shift', function () {
    $employee = nshEmployee('Ana', 'Cruz');
    $actor = nshActor(['LEAVES']);
    $recommended = nshLeave($employee, start: '2026-09-21', end: '2026-09-21');
    $disapproved = nshLeave($employee, start: '2026-09-24', end: '2026-09-24');

    app(RecommendAbsence::class)->handle($actor, $recommended->id, 'FOR_APPROVAL', null);
    app(ReviewAbsence::class)->handle($actor, $disapproved->id, 'REJECTED', null, 'Not this week.');

    expect($recommended->fresh()->status)->toBe('PENDING')
        ->and($recommended->fresh()->recommendation)->toBe('FOR_APPROVAL')
        ->and($disapproved->fresh()->status)->toBe('REJECTED');
});

it('shows the hold in the review dialog and keeps the approve buttons off', function () {
    $employee = nshEmployee('Ana', 'Cruz');
    nshActor(['LEAVES']);
    $leave = nshLeave($employee);

    $page = Livewire::test(LeavesPage::class)->call('show', $leave->id)
        ->assertSee('data-no-shift-hold', false)
        ->assertSee('Ana Cruz has no shift on Sep 21–23, 2026. Set the schedule in Shift Roster before approving.');
    expect(nshButtonDisabled($page->html(), 'data-approve-paid'))->toBeTrue();

    $page->call('review', 'APPROVED', true);
    expect($leave->fresh()->status)->toBe('PENDING');

    ScheduleFixture::standard($employee);
    $page = Livewire::test(LeavesPage::class)->call('show', $leave->id)->assertDontSee('data-no-shift-hold', false);
    expect(nshButtonDisabled($page->html(), 'data-approve-paid'))->toBeFalse();
});
