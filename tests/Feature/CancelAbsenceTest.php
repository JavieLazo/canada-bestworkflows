<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\CancelAbsence;
use App\Modules\Timekeeping\Infrastructure\AccountReferences;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cancellation preserves approval evidence and removes only authorized future dates atomically. */
function cancelLeaveEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'CANCEL-'.Str::random(6), 'first_name' => 'Leave', 'last_name' => 'Holder',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true,
    ])->save();

    return $employee;
}

/** @param list<string> $modules */
function cancelLeaveActor(array $modules = [ModuleCode::LEAVES], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Cancel '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** @param array<string, mixed> $attributes */
function cancelLeaveRequest(EmployeeProfile $employee, array $attributes = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => 'CANCEL-'.Str::random(6), 'employee_id' => $employee->id,
        'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-10-09', 'end_date' => '2026-10-14', 'status' => 'APPROVED',
        'reason' => 'Original reason', 'is_paid' => true, 'reviewed_at' => '2026-10-01 00:00:00.000',
        'created_at' => '2026-10-01 00:00:00.000', 'updated_at' => '2026-10-01 00:00:00.000',
    ], $attributes))->save();

    return $request;
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10T10:00:00+08:00'));
});

it('cancels selected future dates including management own leave without rewriting approval evidence', function () {
    $employee = cancelLeaveEmployee();
    $actor = cancelLeaveActor(employeeId: $employee->id);
    $second = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
    User::factory()->create(['employee_id' => $employee->id, 'is_active' => false]);
    $request = cancelLeaveRequest($employee, ['reviewed_by' => (int) $actor->userId]);

    app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-14', '2026-10-12'], '  Plans changed  ');

    $request->refresh()->load('cancellations');
    expect($request->status)->toBe('APPROVED')
        ->and($request->start_date->format('Y-m-d'))->toBe('2026-10-09')
        ->and($request->end_date->format('Y-m-d'))->toBe('2026-10-14')
        ->and($request->reason)->toBe('Original reason')
        ->and($request->is_paid)->toBeTrue()
        ->and((int) $request->reviewed_by)->toBe((int) $actor->userId)
        ->and($request->reviewed_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-01 00:00:00.000')
        ->and($request->updated_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-10 02:00:00.000')
        ->and($request->cancelledDates())->toBe(['2026-10-12', '2026-10-14']);
    foreach ($request->cancellations as $row) {
        expect($row->reason)->toBe('Plans changed')
            ->and($row->cancelled_by)->toBe((int) $actor->userId)
            ->and($row->cancelled_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-10 02:00:00.000');
    }
    expect(AuditLog::query()->where('action', 'REQUEST_CANCELLED')->count())->toBe(1)
        ->and(Notification::query()->where('kind', 'LEAVE_CANCELLED')->count())->toBe(2)
        ->and(Notification::query()->where('user_id', $second->id)->value('href'))->toBe('/portal')
        ->and(app(AccountReferences::class)->references($actor->userId))->toBeTrue();
});

it('marks the request cancelled only after every original date has been cancelled', function (string $dayType, bool $isPaid) {
    $actor = cancelLeaveActor();
    $request = cancelLeaveRequest(cancelLeaveEmployee(), [
        'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'day_type' => $dayType, 'is_paid' => $isPaid,
    ]);
    app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-12'], 'One date changed');
    expect($request->fresh()->status)->toBe('APPROVED');
    app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-13'], 'Remaining date changed');
    expect($request->fresh()->status)->toBe('CANCELLED')
        ->and($request->fresh()->day_type)->toBe($dayType)
        ->and($request->fresh()->is_paid)->toBe($isPaid)
        ->and(LeaveRequestCancellation::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'REQUEST_CANCELLED')->count())->toBe(2);
})->with([['WHOLE_DAY', true], ['HALF_DAY', true], ['WHOLE_DAY', false], ['HALF_DAY', false]]);

it('refuses accounts without leave management authority including their own records', function (?string $module, bool $allData) {
    $employee = cancelLeaveEmployee();
    $request = cancelLeaveRequest($employee);
    $actor = cancelLeaveActor($module === null ? [] : [$module], $allData, $employee->id);
    expect(fn () => app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-12'], 'Changed'))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(LeaveRequestCancellation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
})->with([[ModuleCode::LEAVES, false], [ModuleCode::PORTAL, true], [null, false]]);

it('refuses malformed, repeated, current, elapsed and out-of-range dates without partial effects', function (array $dates) {
    $request = cancelLeaveRequest(cancelLeaveEmployee());
    $actor = cancelLeaveActor();
    expect(fn () => app(CancelAbsence::class)->handle($actor, $request->id, $dates, 'Changed'))
        ->toThrow(RuntimeException::class);
    expect(LeaveRequestCancellation::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe('APPROVED')
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
})->with([
    [[]], [['bad']], [['2026-02-30']], [[['2026-10-12']]], [[12]],
    [['2026-10-12', '2026-10-12']], [['2026-10-12', '2026-10-10']],
    [['2026-10-12', '2026-10-09']], [['2026-10-12', '2026-10-15']],
    [['selected' => '2026-10-12']],
]);

it('requires a meaningful bounded reason and an approved leave request', function () {
    $actor = cancelLeaveActor();
    $request = cancelLeaveRequest(cancelLeaveEmployee());
    foreach (['', '   ', str_repeat('a', 1001)] as $reason) {
        expect(fn () => app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-12'], $reason))
            ->toThrow(RuntimeException::class, 'Enter a cancellation reason');
    }
    foreach (['PENDING', 'REJECTED', 'WITHDRAWN', 'CANCELLED'] as $status) {
        $request->update(['status' => $status]);
        expect(fn () => app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-12'], 'Changed'))
            ->toThrow(RuntimeException::class, 'Only approved leave');
    }
    $request->update(['status' => 'APPROVED', 'request_type' => 'BUSINESS_TRAVEL']);
    expect(fn () => app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-12'], 'Changed'))
        ->toThrow(RuntimeException::class, 'Only approved leave');
    expect(fn () => app(CancelAbsence::class)->handle($actor, (string) Str::ulid(), ['2026-10-12'], 'Changed'))
        ->toThrow(RuntimeException::class, 'That request no longer exists.');
    expect(LeaveRequestCancellation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses a stale repeated cancellation and a mixture of cancelled and active dates as a whole', function () {
    $employee = cancelLeaveEmployee();
    User::factory()->create(['employee_id' => $employee->id]);
    $request = cancelLeaveRequest($employee);
    $actor = cancelLeaveActor();
    $command = app(CancelAbsence::class);
    $command->handle($actor, $request->id, ['2026-10-12'], 'Changed');
    foreach ([['2026-10-12'], ['2026-10-12', '2026-10-13']] as $dates) {
        expect(fn () => $command->handle($actor, $request->id, $dates, 'Changed again'))
            ->toThrow(RuntimeException::class, 'already been cancelled');
    }
    expect(LeaveRequestCancellation::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'REQUEST_CANCELLED')->count())->toBe(1)
        ->and(Notification::query()->where('kind', 'LEAVE_CANCELLED')->count())->toBe(1);
});

it('refuses a future date already on posted payroll without cancelling another selected date', function () {
    $employee = cancelLeaveEmployee();
    $request = cancelLeaveRequest($employee);
    $actor = cancelLeaveActor();
    $payslip = new Payslip;
    $payslip->forceFill(['employee_id' => $employee->id, 'period_start' => '2026-10-13', 'period_end' => '2026-10-14'])->save();
    expect(fn () => app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-12', '2026-10-13'], 'Changed'))
        ->toThrow(RuntimeException::class, 'already on a posted payslip');
    expect(LeaveRequestCancellation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('rolls back all date evidence and success effects when a surrounding transaction fails', function () {
    $employee = cancelLeaveEmployee();
    User::factory()->create(['employee_id' => $employee->id]);
    $request = cancelLeaveRequest($employee);
    $actor = cancelLeaveActor();
    expect(fn () => DB::transaction(function () use ($actor, $request): void {
        app(CancelAbsence::class)->handle($actor, $request->id, ['2026-10-12', '2026-10-13'], 'Changed');
        expect(AuditLog::query()->count())->toBe(0)->and(Notification::query()->count())->toBe(0);
        throw new RuntimeException('Roll back the outer transaction.');
    }))->toThrow(RuntimeException::class, 'Roll back the outer transaction.');
    expect(LeaveRequestCancellation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0)
        ->and($request->fresh()->updated_at->format('Y-m-d'))->toBe('2026-10-01');
});

it('keeps cancellation identity and time out of mass assignment', function () {
    $row = new LeaveRequestCancellation;
    $row->fill(['cancelled_by' => 7, 'cancelled_at' => '2026-10-10 02:00:00', 'reason' => 'Changed']);
    expect($row->getAttributes())->not->toHaveKeys(['cancelled_by', 'cancelled_at'])
        ->and($row->reason)->toBe('Changed');
});
