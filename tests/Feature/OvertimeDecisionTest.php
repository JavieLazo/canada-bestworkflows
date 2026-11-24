<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\OvertimeApproval;
use App\Models\PayrollFormula;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\DecideOvertime;
use App\Modules\Timekeeping\Application\Commands\DecidePendingOvertime;
use App\Modules\Timekeeping\Application\OvertimeDecisionRefused;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Str;

/**
 * Overtime is approved per day on the Daily Time Record. The commands are
 * called directly, with no screen: only an all-data EDTR holder
 * decides, including on their own day; an approval is 1..min(computed, Formulas
 * cap) whole minutes; a day already on a posted payslip is frozen; every real
 * decision is one strict audit row and one notice to the employee's accounts,
 * and an unchanged one writes nothing. The bulk action decides only PENDING
 * days, at their full approvable figure.
 */

/** @param list<string> $modules */
function otdecActor(array $modules, bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'OT '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    $user = User::factory()->create(['name' => 'Rhea Approver', 'role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function otdecEmployee(string $code = 'OT-001'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code, 'first_name' => 'Liza', 'last_name' => 'Domingo',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'monthly_rate' => 30000,
    ])->save();

    $shift = new Shift;
    $shift->forceFill([
        'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 5,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $employee;
}

/** A day worked 08:00 to $out, Manila: past 17:00 is overtime. */
function otdecWorked(EmployeeProfile $employee, string $date, string $out = '19:30'): void
{
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => $out] as $type => $at) {
        $punch = new AttendanceLog;
        $punch->forceFill([
            'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
            'is_geofenced' => true, 'is_anomaly' => false,
            'logged_at' => LocalClock::instant("{$date}T{$at}:00")->utc(),
        ])->save();
    }
}

function otdecAccount(EmployeeProfile $employee): User
{
    return User::factory()->create(['employee_id' => (string) $employee->getKey(), 'is_active' => true]);
}

/** @return array{approved: int, rejected: int} */
function otdecAudits(): array
{
    return [
        'approved' => AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->count(),
        'rejected' => AuditLog::query()->where('action', 'DTR_OVERTIME_REJECTED')->count(),
    ];
}

beforeEach(function () {
    // Thursday evening; the 8th (Tuesday) and 9th (Wednesday) are behind us.
    $this->travelTo(new DateTimeImmutable('2026-09-10T18:00:00+08:00'));
});

it('approves the minutes the approver typed and records who decided', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $account = otdecAccount($employee);
    $actor = otdecActor(['EDTR']);

    $message = app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'APPROVED', '90', '  Client deadline  ');

    $row = OvertimeApproval::query()->sole();
    expect($message)->toBe('Overtime approved 1h 30m of 2h 30m computed for Liza Domingo on Sep 08, 2026.')
        ->and($row->decision)->toBe('APPROVED')
        ->and($row->date->format('Y-m-d'))->toBe('2026-09-08')
        ->and($row->computed_minutes)->toBe(150)
        ->and($row->approved_minutes)->toBe(90)
        ->and($row->note)->toBe('Client deadline')
        // The actor column comes from Access, never from the call.
        ->and($row->reviewed_by)->toBe((int) $actor->userId);

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe('DTR_OVERTIME_APPROVED')
        ->and($audit->entity)->toBe('overtime_approvals')
        ->and($audit->entity_id)->toBe((string) $employee->getKey())
        ->and($audit->details)->toBe('Liza Domingo · Sep 08, 2026 · approved 1h 30m of 2h 30m computed · Client deadline');

    // The employee's account hears of it, linked to My Portal; the actor does not.
    $notice = Notification::query()->sole();
    expect($notice->user_id)->toBe($account->id)
        ->and($notice->kind)->toBe('OVERTIME_APPROVED')
        ->and($notice->title)->toBe('Overtime approved for Sep 08, 2026')
        ->and($notice->href)->toBe('/portal');
});

it('rejects a day, storing no approved minutes whatever was typed', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $actor = otdecActor(['EDTR']);

    app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'REJECTED', '90');

    $row = OvertimeApproval::query()->sole();
    expect($row->decision)->toBe('REJECTED')
        ->and($row->approved_minutes)->toBe(0)
        ->and($row->computed_minutes)->toBe(150)
        ->and(otdecAudits())->toBe(['approved' => 0, 'rejected' => 1]);
});

it('writes nothing when the decision has not changed, and one row per real change', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $actor = otdecActor(['EDTR']);
    $decide = app(DecideOvertime::class);
    $id = (string) $employee->getKey();

    $decide->handle($actor, $id, '2026-09-08', 'APPROVED', '150');
    expect($decide->handle($actor, $id, '2026-09-08', 'APPROVED', 150))->toBe('Nothing changed.')
        ->and(otdecAudits())->toBe(['approved' => 1, 'rejected' => 0])
        ->and(Notification::query()->count())->toBe(0);

    $decide->handle($actor, $id, '2026-09-08', 'REJECTED');
    expect(OvertimeApproval::query()->count())->toBe(1)
        ->and(OvertimeApproval::query()->sole()->decision)->toBe('REJECTED')
        ->and(otdecAudits())->toBe(['approved' => 1, 'rejected' => 1]);
});

it('refuses every actor but an all-data EDTR holder, writing nothing', function (string $who) {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $actor = match ($who) {
        'EDTR without all-data' => otdecActor(['EDTR'], false),
        'own EDTR without all-data' => otdecActor(['EDTR'], false, (string) $employee->getKey()),
        'PAYROLL all-data' => otdecActor(['PAYROLL']),
        'no grants' => otdecActor([]),
        default => throw new LogicException('Unknown actor'),
    };

    expect(fn () => app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'APPROVED', '60'))
        ->toThrow(RuntimeException::class, 'You are not allowed to decide overtime.');
    expect(fn () => app(DecidePendingOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-01', '2026-09-10', 'APPROVED'))
        ->toThrow(RuntimeException::class, 'You are not allowed to decide overtime.');

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['EDTR without all-data', 'own EDTR without all-data', 'PAYROLL all-data', 'no grants']);

it('lets an all-data EDTR holder approve their own overtime with their identity recorded', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $otherAccount = otdecAccount($employee);
    $actor = otdecActor(['EDTR'], employeeId: (string) $employee->getKey());

    expect(app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-09-08')?->can_decide_overtime)->toBeTrue();
    app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'APPROVED', '60');

    $row = OvertimeApproval::query()->sole();
    $audit = AuditLog::query()->sole();
    expect($row->employee_id)->toBe((string) $employee->getKey())
        ->and($row->approved_minutes)->toBe(60)
        ->and($row->reviewed_by)->toBe((int) $actor->userId)
        ->and($audit->action)->toBe('DTR_OVERTIME_APPROVED')
        ->and($audit->actor_id)->toBe((int) $actor->userId)
        ->and($audit->entity_id)->toBe((string) $employee->getKey())
        // The actor is still excluded from notifications; their other account hears it.
        ->and(Notification::query()->sole()->user_id)->toBe($otherAccount->id);
});

it('lets an all-data EDTR holder approve every pending day of their own overtime', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-07', '18:00');
    otdecWorked($employee, '2026-09-08');
    $actor = otdecActor(['EDTR'], employeeId: (string) $employee->getKey());

    $result = app(DecidePendingOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-01', '2026-09-10', 'APPROVED');

    expect($result)->toBe(['decided' => 2, 'skipped' => 0])
        ->and(OvertimeApproval::query()->orderBy('date')->pluck('approved_minutes')->all())->toBe([60, 150])
        ->and(OvertimeApproval::query()->pluck('reviewed_by')->unique()->values()->all())->toBe([(int) $actor->userId])
        ->and(otdecAudits())->toBe(['approved' => 2, 'rejected' => 0])
        ->and(AuditLog::query()->pluck('actor_id')->unique()->values()->all())->toBe([(int) $actor->userId])
        ->and(Notification::query()->count())->toBe(0);
});

it('refuses a day that has not happened, a day with no overtime and an unknown employee', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    otdecWorked($employee, '2026-09-09', '17:00');
    $actor = otdecActor(['EDTR']);
    $decide = app(DecideOvertime::class);
    $id = (string) $employee->getKey();

    expect(fn () => $decide->handle($actor, $id, '2026-09-11', 'APPROVED', '60'))->toThrow(RuntimeException::class, 'That day has not happened yet.')
        ->and(fn () => $decide->handle($actor, $id, 'not-a-date', 'APPROVED', '60'))->toThrow(RuntimeException::class, 'That day has not happened yet.')
        ->and(fn () => $decide->handle($actor, $id, '2026-09-09', 'APPROVED', '60'))->toThrow(RuntimeException::class, 'This day has no overtime to decide.')
        ->and(fn () => $decide->handle($actor, $id, '2026-09-09', 'REJECTED'))->toThrow(RuntimeException::class, 'This day has no overtime to decide.')
        ->and(fn () => $decide->handle($actor, 'missing', '2026-09-08', 'APPROVED', '60'))->toThrow(RuntimeException::class, 'That employee no longer exists.');

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses minutes outside one to the approvable ceiling, and a long note', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $actor = otdecActor(['EDTR']);
    $decide = app(DecideOvertime::class);
    $id = (string) $employee->getKey();

    foreach (['0', '151', '45.5', '', null] as $minutes) {
        expect(fn () => $decide->handle($actor, $id, '2026-09-08', 'APPROVED', $minutes))
            ->toThrow(RuntimeException::class, 'Approve between 1m and 2h 30m of overtime.');
    }
    expect(fn () => $decide->handle($actor, $id, '2026-09-08', 'PENDING', '60'))
        ->toThrow(RuntimeException::class, 'Choose whether to approve or reject the overtime.');
    expect(fn () => $decide->handle($actor, $id, '2026-09-08', 'APPROVED', '60', str_repeat('x', 501)))
        ->toThrow(RuntimeException::class, 'Keep the note to 500 characters.');

    expect(OvertimeApproval::query()->count())->toBe(0);
});

it('caps an approval at the Formulas overtime maximum', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    PayrollFormula::create(['code' => 'F-OT', 'key' => 'OT', 'name' => 'Overtime', 'expression' => 'Display only',
        'params' => [['key' => 'working_hours', 'value' => 8], ['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 1]]]);
    $actor = otdecActor(['EDTR']);

    expect(fn () => app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'APPROVED', '90'))
        ->toThrow(RuntimeException::class, 'Approve between 1m and 1h of overtime.');

    app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'APPROVED', '60');
    expect(OvertimeApproval::query()->sole()->approved_minutes)->toBe(60)
        ->and(OvertimeApproval::query()->sole()->computed_minutes)->toBe(150);
});

it('freezes a day already on a posted payslip, including its first and last day', function (string $date) {
    $employee = otdecEmployee();
    otdecWorked($employee, $date);
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-POSTED', 'name' => 'Posted', 'status' => 'FINALIZED', 'period_start' => '2026-09-01', 'period_end' => '2026-09-08'])->save();
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => (string) $employee->getKey(),
        'period_start' => '2026-09-01', 'period_end' => '2026-09-08', 'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();
    $actor = otdecActor(['EDTR']);

    expect(fn () => app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), $date, 'APPROVED', '60'))
        ->toThrow(RuntimeException::class, 'That day is already on a posted payslip, so its overtime can no longer change.');
    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
})->with(['first day' => '2026-09-01', 'last day' => '2026-09-08']);

it('decides a day just after a posted payslip', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-09');
    $payslip = new Payslip;
    $payslip->forceFill(['employee_id' => (string) $employee->getKey(),
        'period_start' => '2026-09-01', 'period_end' => '2026-09-08', 'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();
    $actor = otdecActor(['EDTR']);

    app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-09', 'APPROVED', '150');

    expect(OvertimeApproval::query()->sole()->approved_minutes)->toBe(150);
});

it('rolls the decision back when its audit cannot be written', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    otdecAccount($employee);
    $actor = otdecActor(['EDTR']);
    AuditLog::creating(static function (): void {
        throw new RuntimeException('Synthetic audit failure');
    });

    expect(fn () => app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'APPROVED', '60'))
        ->toThrow(RuntimeException::class, 'audit record could not be recorded');

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('approves every pending day in full, keeps decided days and tells the employee once', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-07', '18:00');
    otdecWorked($employee, '2026-09-08');
    otdecWorked($employee, '2026-09-09', '17:00');
    $account = otdecAccount($employee);
    $actor = otdecActor(['EDTR']);
    $id = (string) $employee->getKey();
    app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'REJECTED', notify: false);

    $result = app(DecidePendingOvertime::class)->handle($actor, $id, '2026-09-01', '2026-09-15', 'APPROVED');

    // The 7th was pending (60m); the 8th keeps its rejection; the 9th has no overtime.
    expect($result)->toBe(['decided' => 1, 'skipped' => 0]);
    $rows = OvertimeApproval::query()->orderBy('date')->get()->mapWithKeys(fn (OvertimeApproval $row) => [$row->date->format('Y-m-d') => [$row->decision, $row->approved_minutes]])->all();
    expect($rows)->toBe(['2026-09-07' => ['APPROVED', 60], '2026-09-08' => ['REJECTED', 0]])
        ->and(otdecAudits())->toBe(['approved' => 1, 'rejected' => 1]);

    $notice = Notification::query()->sole();
    expect($notice->user_id)->toBe($account->id)
        ->and($notice->title)->toBe('Overtime approved for 1 day')
        ->and($notice->href)->toBe('/portal');
});

it('rejects every pending day and skips one already on a posted payslip', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-01');
    otdecWorked($employee, '2026-09-07', '18:00');
    otdecWorked($employee, '2026-09-08');
    $payslip = new Payslip;
    $payslip->forceFill(['employee_id' => (string) $employee->getKey(),
        'period_start' => '2026-09-01', 'period_end' => '2026-09-01', 'gross_pay' => 1000, 'total_deductions' => 0, 'net_pay' => 1000])->save();
    $actor = otdecActor(['EDTR']);

    $result = app(DecidePendingOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-01', '2026-09-15', 'REJECTED');

    expect($result)->toBe(['decided' => 2, 'skipped' => 1])
        ->and(OvertimeApproval::query()->pluck('decision')->unique()->values()->all())->toBe(['REJECTED'])
        ->and(otdecAudits())->toBe(['approved' => 0, 'rejected' => 2]);
});

it('skips approving a pending day the Formulas make unpayable', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    PayrollFormula::create(['code' => 'F-OT', 'key' => 'OT', 'name' => 'Overtime', 'expression' => 'Display only',
        'params' => [['key' => 'working_hours', 'value' => 8], ['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 0]]]);
    $actor = otdecActor(['EDTR']);

    expect(app(DecidePendingOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-01', '2026-09-15', 'APPROVED'))
        ->toBe(['decided' => 0, 'skipped' => 1])
        ->and(OvertimeApproval::query()->count())->toBe(0);
});

it('refuses a bulk period that is invalid, reversed or over two months', function () {
    $employee = otdecEmployee();
    $actor = otdecActor(['EDTR']);
    $bulk = app(DecidePendingOvertime::class);
    $id = (string) $employee->getKey();

    expect(fn () => $bulk->handle($actor, $id, '2026-09-15', '2026-09-01', 'APPROVED'))->toThrow(RuntimeException::class, 'Choose a valid period.')
        ->and(fn () => $bulk->handle($actor, $id, 'nope', '2026-09-01', 'APPROVED'))->toThrow(RuntimeException::class, 'Choose a valid period.')
        ->and(fn () => $bulk->handle($actor, $id, '2026-06-01', '2026-09-10', 'APPROVED'))->toThrow(RuntimeException::class, 'Decide at most two months at a time.')
        ->and(fn () => $bulk->handle($actor, $id, '2026-09-01', '2026-09-10', 'MAYBE'))->toThrow(RuntimeException::class, 'Choose whether to approve or reject the overtime.')
        ->and(fn () => $bulk->handle($actor, 'missing', '2026-09-01', '2026-09-10', 'APPROVED'))->toThrow(RuntimeException::class, 'That employee no longer exists.');

    // A period wholly in the future has nothing to decide.
    expect($bulk->handle($actor, $id, '2026-09-11', '2026-09-15', 'APPROVED'))->toBe(['decided' => 0, 'skipped' => 0]);
});

it('refuses to overwrite a day decided after the bulk action looked', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $actor = otdecActor(['EDTR']);
    $id = (string) $employee->getKey();
    app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'REJECTED', notify: false);

    expect(fn () => app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '150', notify: false, onlyIfPending: true))
        ->toThrow(OvertimeDecisionRefused::class, 'That day was decided meanwhile.');
    expect(OvertimeApproval::query()->sole()->decision)->toBe('REJECTED')
        ->and(otdecAudits())->toBe(['approved' => 0, 'rejected' => 1]);
});

it('undoes every day of a bulk decision when one audit cannot be written', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-07', '18:00');
    otdecWorked($employee, '2026-09-08');
    otdecAccount($employee);
    $actor = otdecActor(['EDTR']);
    $audits = 0;
    AuditLog::creating(static function () use (&$audits): void {
        // The first day's audit lands; the second one fails.
        if (++$audits === 2) {
            throw new RuntimeException('Synthetic audit failure');
        }
    });

    expect(fn () => app(DecidePendingOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-01', '2026-09-15', 'APPROVED'))
        ->toThrow(RuntimeException::class, 'audit record could not be recorded');

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('freezes a day on a legacy payslip posted before runs existed', function () {
    // A payslip with no run is regular pay, so it freezes its days.
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => null, 'employee_id' => (string) $employee->getKey(),
        'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();
    $actor = otdecActor(['EDTR']);

    expect(fn () => app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'REJECTED'))
        ->toThrow(OvertimeDecisionRefused::class, 'That day is already on a posted payslip, so its overtime can no longer change.');
    expect(OvertimeApproval::query()->count())->toBe(0);
});

it('never freezes a day on a 13th month payslip, which spans the whole year', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-13', 'name' => '13th month 2026', 'status' => 'FINALIZED', 'kind' => 'THIRTEENTH_MONTH',
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31'])->save();
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => (string) $employee->getKey(),
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'thirteenth_month' => 20000, 'gross_pay' => 20000, 'total_deductions' => 0, 'net_pay' => 20000])->save();
    $actor = otdecActor(['EDTR']);

    app(DecideOvertime::class)->handle($actor, (string) $employee->getKey(), '2026-09-08', 'REJECTED');

    expect(OvertimeApproval::query()->sole()->decision)->toBe('REJECTED');
});

it('approves a day as seaport overtime with no cap, and drops the category on a rejection', function () {
    $employee = otdecEmployee();
    otdecWorked($employee, '2026-09-08');
    PayrollFormula::create(['code' => 'F-OT', 'key' => 'OT', 'name' => 'Overtime', 'expression' => 'Display only',
        'params' => [['key' => 'working_hours', 'value' => 8], ['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 1]]]);
    $actor = otdecActor(['EDTR']);
    $id = (string) $employee->getKey();

    // Ordinary overtime is capped at the hour; seaport overtime is the whole day.
    expect(fn () => app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '150'))
        ->toThrow(RuntimeException::class, 'Approve between 1m and 1h of overtime.')
        ->and(fn () => app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '150', category: 'HARBOR'))
        ->toThrow(RuntimeException::class, 'Choose ordinary or seaport overtime.');

    $message = app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '150', category: 'SEAPORT');
    $row = OvertimeApproval::query()->sole();
    expect($message)->toBe('Overtime approved 2h 30m of 2h 30m computed as seaport overtime for Liza Domingo on Sep 08, 2026.')
        ->and($row->category)->toBe('SEAPORT')
        ->and($row->approved_minutes)->toBe(150)
        ->and(AuditLog::query()->sole()->details)->toBe('Liza Domingo · Sep 08, 2026 · approved 2h 30m of 2h 30m computed as seaport overtime')
        // The same decision again changes nothing; the category is part of it.
        ->and(app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '150', category: 'SEAPORT'))->toBe('Nothing changed.')
        ->and(AuditLog::query()->count())->toBe(1);

    // Back to ordinary: the cap applies again, and a rejection carries no category.
    expect(fn () => app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'APPROVED', '150'))
        ->toThrow(RuntimeException::class, 'Approve between 1m and 1h of overtime.');
    app(DecideOvertime::class)->handle($actor, $id, '2026-09-08', 'REJECTED', category: 'SEAPORT');
    expect(OvertimeApproval::query()->sole()->category)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(2);
});
