<?php

declare(strict_types=1);

use App\Livewire\Payroll\RunWorkspace;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\OvertimeApproval;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\OpenAttendanceItems;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollReviewFixture;

/**
 * Before you finalize: a regular payroll pays attendance as it stands, so
 * what attendance has not finished deciding is shown, and posts only when the
 * officer ticks "Finalize anyway" — the period not over yet (the days to come
 * pay as absences), overtime awaiting a decision (it pays nothing, and can
 * never be decided once posted), and leave or official business awaiting
 * review (unpaid, and approving it later changes no payslip). The tick holds
 * for exactly the figures it was given on; the audit trail and the evidence
 * record what was accepted.
 *
 * The run covers 1 to 15 September 2026; the officer works on the 20th.
 */
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-20T12:00:00+08:00'));
});

function openItemsActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Open items '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    foreach (['PAYROLL', 'EDTR', 'LEAVES'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function openItemsEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'OPEN-'.random_int(1000, 9999), 'first_name' => 'Ramon', 'last_name' => 'Aquino',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
        'monthly_rate' => 30000, 'hourly_rate' => 200,
    ])->save();
    $shift = new Shift;
    $shift->forceFill([
        'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 5,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $employee;
}

/** A day worked 08:00 to $out, Manila; past 17:00 is overtime. */
function openItemsWorked(EmployeeProfile $employee, string $date, string $out = '19:30'): void
{
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => $out] as $type => $at) {
        $punch = new AttendanceLog;
        $punch->forceFill([
            'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
            'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant("{$date}T{$at}:00")->utc(),
        ])->save();
    }
}

function openItemsDecide(EmployeeProfile $employee, string $date): void
{
    $row = new OvertimeApproval;
    $row->forceFill(['employee_id' => (string) $employee->getKey(), 'date' => $date, 'decision' => 'APPROVED',
        'computed_minutes' => 150, 'approved_minutes' => 150])->save();
}

function openItemsRequest(EmployeeProfile $employee, string $code, string $start, string $end, string $status = 'PENDING'): void
{
    $request = new LeaveTravelRequest;
    $request->forceFill(['code' => $code, 'employee_id' => (string) $employee->getKey(), 'request_type' => 'SICK',
        'day_type' => 'WHOLE_DAY', 'start_date' => $start, 'end_date' => $end, 'reason' => 'Flu', 'status' => $status,
        'is_paid' => $status === 'APPROVED' ? true : null])->save();
}

function openItemsRun(EmployeeProfile $employee): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-OPEN', 'name' => 'September 2026 — first cut-off', 'status' => 'DRAFT',
        'period_start' => '2026-09-01', 'period_end' => '2026-09-15'])->save();
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->getKey(), 'position' => 0,
        'period_start' => '2026-09-01', 'period_end' => '2026-09-15']);

    return $run;
}

function openItemsFor(EmployeeProfile $employee, string $today): OpenAttendanceItems
{
    $inputs = PayrollData::calculateDrafts([(string) $employee->getKey()], '2026-09-01', '2026-09-15')['inputs'];

    return OpenAttendanceItems::forInputs($inputs, $today);
}

it('lists undecided overtime, pending requests and a period not over, and nothing else', function () {
    $employee = openItemsEmployee();
    $id = (string) $employee->getKey();
    openItemsWorked($employee, '2026-09-08');
    openItemsWorked($employee, '2026-09-09');
    openItemsDecide($employee, '2026-09-09');
    openItemsWorked($employee, '2026-09-14', '17:00');
    openItemsRequest($employee, 'LR-PENDING', '2026-09-10', '2026-09-11');
    openItemsRequest($employee, 'LR-APPROVED', '2026-09-03', '2026-09-03', 'APPROVED');
    openItemsRequest($employee, 'LR-LATER', '2026-09-21', '2026-09-22');
    openItemsActor();

    $open = openItemsFor($employee, '2026-09-16');

    expect($open->periodOpen)->toBeFalse()
        ->and($open->overtime)->toBe([$id => ['2026-09-08']])
        ->and($open->requests)->toBe([$id => [['type' => 'SICK', 'start' => '2026-09-10', 'end' => '2026-09-11']]])
        ->and($open->summary())->toBe('1 overtime day undecided, 1 request pending')
        ->and(openItemsFor($employee, '2026-09-15')->summary())->toBe('the period is not over, 1 overtime day undecided, 1 request pending');
});

it('reads nothing outside the employment window', function () {
    $employee = openItemsEmployee();
    $employee->forceFill(['date_hired' => '2026-09-10'])->save();
    openItemsWorked($employee, '2026-09-08');
    openItemsRequest($employee, 'LR-BEFORE', '2026-09-01', '2026-09-02');
    openItemsActor();

    expect(openItemsFor($employee, '2026-09-16')->isEmpty())->toBeTrue();
});

it('agrees with the Daily Time Record on what overtime awaits a decision', function (float $minutes) {
    expect(OpenAttendanceItems::decidable($minutes))->toBe(OvertimeApprovalPolicy::decidable($minutes));
})->with([0.0, 0.4, 0.49, 0.5, 1.0, 1.5, 59.6, -3.0, INF, NAN]);

it('refuses to finalize open attendance without the tick, and posts and records it with the tick', function () {
    $employee = openItemsEmployee();
    $id = (string) $employee->getKey();
    openItemsWorked($employee, '2026-09-08');
    $actor = openItemsActor();
    $run = openItemsRun($employee);
    $receipt = PayrollReviewFixture::token($actor, $run->id);

    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))
        ->toThrow(RuntimeException::class, 'This payroll has open attendance: 1 overtime day undecided. Tick "Finalize anyway" to pay them as they stand.');
    expect($run->refresh()->status)->toBe('DRAFT')
        ->and(Payslip::query()->count())->toBe(0)
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0);

    app(FinalizePayroll::class)->handle($actor, $run->id, $receipt, acceptOpenItems: true);

    $payload = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and(Payslip::query()->sole()->overtime_pay)->toBe(0.0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->sole()->details)
        ->toEndWith('; finalized with open attendance: 1 overtime day undecided')
        ->and($payload['open_items'])->toBe(['overtime' => [$id => ['2026-09-08']], 'period_open' => false, 'requests' => []]);
});

it('finalizes with nothing open as before, recording that nothing was', function () {
    $employee = openItemsEmployee();
    openItemsWorked($employee, '2026-09-08');
    openItemsDecide($employee, '2026-09-08');
    $actor = openItemsActor();
    $run = openItemsRun($employee);

    app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id));

    $payload = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and(Payslip::query()->sole()->overtime_pay)->toBe(625.0)
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->sole()->details)->not->toContain('open attendance')
        ->and($payload['open_items'])->toBe(['overtime' => [], 'period_open' => false, 'requests' => []]);
});

it('asks before you finalize on the workspace, for exactly the figures shown', function () {
    $employee = openItemsEmployee();
    openItemsWorked($employee, '2026-09-08');
    openItemsRequest($employee, 'LR-PENDING', '2026-09-10', '2026-09-10');
    openItemsActor();
    $run = openItemsRun($employee);

    $page = Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSeeHtml('data-open-items')
        ->assertSee('Before you finalize')
        ->assertSee('Overtime awaiting a decision for Ramon Aquino:')
        ->assertSee('Sep 08, 2026')
        ->assertSee('Awaiting review for Ramon Aquino:')
        ->assertSee('Sick Leave, Sep 10, 2026')
        ->assertSet('openItemsAcceptedFor', null);

    // Without the tick nothing posts.
    $page->call('finalize');
    expect($run->refresh()->status)->toBe('DRAFT');

    // The tick belongs to the figures it was given on: another worked day
    // changes them, and it clears.
    $page->call('toggleOpenItems');
    expect($page->get('openItemsAcceptedFor'))->toBe($page->get('previewReceipt'))->not->toBeNull();
    openItemsWorked($employee, '2026-09-09');
    $page->call('$refresh')->assertSet('openItemsAcceptedFor', null);

    $page->call('toggleOpenItems')->call('finalize');
    expect($run->refresh()->status)->toBe('FINALIZED')
        ->and(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->sole()->details)
        ->toEndWith('; finalized with open attendance: 2 overtime days undecided, 1 request pending');
});

it('shows no panel and needs no tick when nothing is open', function () {
    $employee = openItemsEmployee();
    openItemsWorked($employee, '2026-09-08', '17:00');
    openItemsActor();
    $run = openItemsRun($employee);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertDontSeeHtml('data-open-items')
        ->call('finalize');

    expect($run->refresh()->status)->toBe('FINALIZED');
});
