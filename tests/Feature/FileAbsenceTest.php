<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * Filing reads the actor's own credits on the server, independently of the
 * form — and a short balance NEVER refuses a filing: the request is filed
 * and the command says which days would be without pay if approved.
 */
function filingEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'FILING-'.Str::random(8), 'first_name' => 'Leave', 'last_name' => 'Applicant',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true])->save();

    return $employee;
}

function filingActor(?EmployeeProfile $employee, bool $portal = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Filing '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    foreach ($portal ? ['PORTAL', 'LEAVES'] : ['LEAVES'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function filingType(string $name = 'Vacation Leave'): void
{
    $type = new LeaveType;
    $type->forceFill(['code' => 'FT-'.Str::random(8), 'name' => $name])->save();
}

function filingCredit(EmployeeProfile $employee, float $days, string $type = 'VACATION_LEAVE', int $year = 2026): void
{
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => $year, 'request_type' => $type, 'credits' => $days])->save();
}

function filingUsed(EmployeeProfile $employee, int $days, ?bool $paid = true, string $type = 'VACATION_LEAVE'): void
{
    $request = new LeaveTravelRequest;
    $request->forceFill(['code' => '1', 'employee_id' => $employee->id, 'request_type' => $type,
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-01-01', 'end_date' => sprintf('2026-01-%02d', $days),
        'status' => 'APPROVED', 'is_paid' => $paid])->save();
}

it('files a request its credits do not cover and says it would be without pay, never refusing it', function (?float $credited, int $used) {
    filingType();
    $employee = filingEmployee();
    if ($credited !== null) {
        filingCredit($employee, $credited);
    }
    if ($used > 0) {
        filingUsed($employee, $used);
    }
    $actor = filingActor($employee);
    $before = LeaveTravelRequest::query()->count();

    $notice = app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-28', '2026-09-28', '');

    expect($notice)->toBe('You have no Vacation Leave credits available to cover this request: the whole request (1 day) will be unpaid if approved.')
        ->and(LeaveTravelRequest::query()->count())->toBe($before + 1)
        ->and(LeaveTravelRequest::query()->where('status', 'PENDING')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->count())->toBe(1);
})->with([
    'no allocation' => [null, 0],
    'zero allocation' => [0.0, 0],
    'fully used' => [1.0, 1],
    'overdrawn' => [1.0, 2],
    'half a day does not cover a whole day' => [0.5, 0],
]);

it('says which days the credits cover and which would be without pay', function () {
    filingType();
    $employee = filingEmployee();
    filingCredit($employee, 2);
    $actor = filingActor($employee);

    // Monday to Friday: five scheduled working days against two credits.
    $notice = app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-28', '2026-10-02', '');

    expect($notice)->toBe('Your available Vacation Leave credits (2.000) cover 2 days of the 5 days; the other 3 days will be unpaid if approved.')
        ->and(LeaveTravelRequest::query()->sole()->status)->toBe('PENDING');
});

it('cannot borrow another type year or employee balance even with all-data access', function () {
    filingType();
    $employee = filingEmployee();
    filingCredit($employee, 15, 'SICK_LEAVE');
    filingCredit($employee, 15, year: 2025);
    filingCredit(filingEmployee(), 15);
    $actor = filingActor($employee);

    $notice = app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-28', '2026-09-28', '');

    expect($notice)->toStartWith('You have no Vacation Leave credits available')
        ->and(LeaveTravelRequest::query()->count())->toBe(1);
});

it('files requests covered exactly by credits in their start year', function (float $credits, string $dayType, string $start, string $end, int $year) {
    filingType();
    $employee = filingEmployee();
    filingCredit($employee, $credits, year: $year);
    $actor = filingActor($employee);

    $notice = app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', $dayType, $start, $end, 'Planned leave');

    expect($notice)->toBeNull()
        ->and(LeaveTravelRequest::query()->sole()->status)->toBe('PENDING')
        ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->count())->toBe(1);
})->with([
    'half day' => [0.5, 'HALF_DAY', '2026-09-28', '2026-09-28', 2026],
    'two half days' => [1.0, 'HALF_DAY', '2026-09-28', '2026-09-29', 2026],
    'whole day' => [1.0, 'WHOLE_DAY', '2026-09-28', '2026-09-28', 2026],
    'next year' => [1.0, 'WHOLE_DAY', '2027-01-05', '2027-01-05', 2027],
    'range across New Year' => [4.0, 'WHOLE_DAY', '2026-12-30', '2027-01-02', 2026],
]);

it('counts legacy approved leave against the matching current credit type', function () {
    filingType('Sick Leave');
    $employee = filingEmployee();
    filingCredit($employee, 1, 'SICK_LEAVE');
    filingUsed($employee, 1, paid: null, type: 'SICK');
    $actor = filingActor($employee);

    expect(app(FileAbsence::class)->handle($actor, 'SICK_LEAVE', 'WHOLE_DAY', '2026-09-28', '2026-09-28', ''))
        ->toStartWith('You have no Sick Leave credits available');
    expect(LeaveTravelRequest::query()->count())->toBe(2);
});

it('files Leave Without Pay with no credits at all, saying it is not paid', function () {
    filingType('Leave Without Pay');
    $actor = filingActor(filingEmployee());

    $notice = app(FileAbsence::class)->handle($actor, 'LEAVE_WITHOUT_PAY', 'WHOLE_DAY', '2026-09-28', '2026-09-28', '');

    expect($notice)->toBe('Leave Without Pay is unpaid leave: the 1 day will not be paid.')
        ->and(LeaveTravelRequest::query()->sole()->status)->toBe('PENDING')
        ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->count())->toBe(1);
});

it('keeps official business outside the leave credit requirement', function () {
    $actor = filingActor(filingEmployee());

    app(FileAbsence::class)->handle($actor, 'BUSINESS_TRAVEL', 'WHOLE_DAY', '2026-09-28', '2026-09-28', 'Client visit');

    expect(LeaveTravelRequest::query()->sole()->request_type)->toBe('BUSINESS_TRAVEL');
});

it('preserves module and employee-link checks before credit validation', function (bool $linked, bool $portal, string $error) {
    filingType();
    $employee = $linked ? filingEmployee() : null;
    if ($employee !== null) {
        filingCredit($employee, 15);
    }
    $actor = filingActor($employee, $portal);

    expect(fn () => app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-28', '2026-09-28', ''))
        ->toThrow(RuntimeException::class, $error);
    expect(LeaveTravelRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
})->with([
    'missing portal grant' => [true, false, 'You do not have access to that.'],
    'missing employee link' => [false, true, "Your account isn't linked to an employee record yet."],
]);
