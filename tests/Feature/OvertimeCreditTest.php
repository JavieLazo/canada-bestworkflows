<?php

declare(strict_types=1);

use App\Livewire\Edtr\EdtrPage;
use App\Livewire\Portal\MyAttendance;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\OvertimeApproval;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Timekeeping\Application\Commands\DecideOvertime;
use App\Modules\Timekeeping\Application\OvertimeDecisionRefused;
use App\Modules\Timekeeping\Application\Queries\CompensatoryHours;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Overtime pay or credit (the PPMC plan's Phase 22, reduced): an approver
 * may credit part or all of a day's approved overtime as Compensatory
 * Leave instead of paying it — pay, credit or split — within the approved
 * minutes; payroll receives only the PAID minutes, the DTR shows the
 * credit, the audit names both parts, and My Portal shows the hours
 * earned. Nothing credited is the reference's reading: every approved
 * minute paid.
 */

/** @param list<string> $modules */
function otcrActor(array $modules, bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'OTCredit '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function otcrEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'OTC-'.Str::upper(Str::random(5)), 'first_name' => 'Cris', 'last_name' => 'Credit',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'monthly_rate' => 30000, 'hourly_rate' => 200])->save();
    $shift = new Shift;
    $shift->forceFill(['employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 5,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false])->save();
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => '19:30'] as $type => $at) {
        $punch = new AttendanceLog;
        $punch->forceFill(['employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
            'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => LocalClock::instant("2026-09-08T{$at}:00")->utc()])->save();
    }

    return $employee;
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-10T18:00:00+08:00'));
});

it('credits part of an approval as Compensatory Leave, within the approved minutes, pays the rest and audits both', function () {
    $employee = otcrEmployee();
    $actor = otcrActor(['EDTR']);
    $decide = app(DecideOvertime::class);
    $id = (string) $employee->getKey();

    expect(fn () => $decide->handle($actor, $id, '2026-09-08', 'APPROVED', '120', credited: '121'))->toThrow(OvertimeDecisionRefused::class, 'Credit between 0 and 2h')
        ->and(fn () => $decide->handle($actor, $id, '2026-09-08', 'APPROVED', '120', credited: '-5'))->toThrow(OvertimeDecisionRefused::class, 'Credit between')
        ->and(fn () => $decide->handle($actor, $id, '2026-09-08', 'APPROVED', '120', credited: '1.5'))->toThrow(OvertimeDecisionRefused::class, 'Credit between')
        ->and(OvertimeApproval::query()->count())->toBe(0);

    $message = $decide->handle($actor, $id, '2026-09-08', 'APPROVED', '120', 'Split', credited: '45');
    $row = OvertimeApproval::query()->sole();
    expect($message)->toContain('approved 2h of 2h 30m computed (45m credited as compensatory leave, 1h 15m paid)')
        ->and($row->approved_minutes)->toBe(120)->and($row->credited_minutes)->toBe(45)
        ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->value('details'))->toContain('45m credited as compensatory leave, 1h 15m paid');

    // The same decision again changes nothing; a different credit is a change.
    expect($decide->handle($actor, $id, '2026-09-08', 'APPROVED', '120', 'Split', credited: '45'))->toBe('Nothing changed.')
        ->and($decide->handle($actor, $id, '2026-09-08', 'APPROVED', '120', 'Split', credited: '120'))->toContain('2h credited as compensatory leave, 0m paid')
        ->and($row->refresh()->credited_minutes)->toBe(120);
    // A rejection credits nothing, whatever was typed.
    $decide->handle($actor, $id, '2026-09-08', 'REJECTED', null, null, credited: '30');
    expect($row->refresh()->credited_minutes)->toBe(0)->and($row->approved_minutes)->toBe(0);

    // The DTR reads the credit and pays the rest; the hours earned follow the approved rows.
    $decide->handle($actor, $id, '2026-09-08', 'APPROVED', '120', credited: '45');
    $view = app(DayCorrections::class)->view($actor, $id, '2026-09-08')?->overtime;
    expect($view?->credited_minutes)->toBe(45)->and($view?->payable_minutes)->toBe(75)->and($view?->status)->toBe(OvertimeApprovalPolicy::PARTIAL)
        ->and(app(CompensatoryHours::class)->earnedMinutes($actor, $id))->toBe(45);
});

it('pays payroll only the minutes not credited, and every approved minute when nothing is', function () {
    $employee = otcrEmployee();
    $actor = otcrActor(['EDTR', 'PAYROLL']);
    $id = (string) $employee->getKey();
    app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '120');
    $paidAll = PayrollData::calculateDrafts([$id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($paidAll['drafts'][0]->overtime_hours)->toBe(2.0)
        ->and($paidAll['inputs']->attendance[$id]->overtimeApprovals)->toBe(['2026-09-08' => 120]);

    app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '120', credited: '60');
    $split = PayrollData::calculateDrafts([$id], '2026-09-01', '2026-09-15', actor: $actor);
    expect($split['drafts'][0]->overtime_hours)->toBe(1.0)
        ->and($split['inputs']->attendance[$id]->overtimeApprovals)->toBe(['2026-09-08' => 60])
        ->and($split['drafts'][0]->overtime_pay)->toBe(round($paidAll['drafts'][0]->overtime_pay / 2, 2))
        // A different credit stales the preview: the paid minutes are what the evidence carries.
        ->and($split['inputs']->fingerprint())->not->toBe($paidAll['inputs']->fingerprint());

    app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '120', credited: '120');
    expect(PayrollData::calculateDrafts([$id], '2026-09-01', '2026-09-15', actor: $actor)['drafts'][0]->overtime_hours)->toBe(0.0);
});

it('takes the credit from the DTR dialog and shows the hours earned in My Portal', function () {
    $employee = otcrEmployee();
    $id = (string) $employee->getKey();
    otcrActor(['EDTR']);
    $page = Livewire::test(EdtrPage::class)->set('tab', 'dtr')->call('employeePicked', 'dtr-employee', $id)->call('openOvertime', '2026-09-08');
    expect($page->get('overtimeForm'))->toBe(['minutes' => '150', 'note' => '', 'seaport' => false, 'credited' => ''])
        ->and($page->html())->toContain('data-overtime-credit');
    $page->set('overtimeForm.minutes', '90')->set('overtimeForm.credited', '30')->call('approveOvertime');
    expect($page->get('overtimeError'))->toBeNull()
        ->and(OvertimeApproval::query()->sole()->credited_minutes)->toBe(30)
        ->and($page->html())->toContain('data-overtime-credited="30"');
    $page->set('overtimeForm.credited', '91')->call('approveOvertime');
    expect($page->get('overtimeError'))->toContain('Credit between 0 and 1h 30m')
        ->and(OvertimeApproval::query()->sole()->credited_minutes)->toBe(30);

    otcrActor(['PORTAL'], employeeId: $id);
    Livewire::test(MyAttendance::class)->set('start', '2026-09-01')->set('end', '2026-09-15')
        ->assertSee('Comp. leave earned')->assertSee('30m')->assertSee('1h 30m approved · 30m credited');
});
