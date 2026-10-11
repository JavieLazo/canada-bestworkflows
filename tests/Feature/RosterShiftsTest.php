<?php

declare(strict_types=1);

use App\Domain\Attendance\ShiftResolver;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\SaveRosterShifts;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\Queries\RosterShifts;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * Weekly hours are scoped, copied, atomic with locations and kept after
 * pattern deletion. Each change is a dated row from today, so the history is
 * kept.
 */

// Weekly changes apply from today, so "today" sits before the dates these
// tests read.
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-21T06:00:00+08:00'));
});

function rshiftEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'RS-'.Str::random(10), 'first_name' => 'Weekly', 'last_name' => 'Employee', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
}

/** @param list<string> $modules */
function rshiftActor(array $modules = ['ROSTER', 'RECORDS'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Weekly '.Str::random(10), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides */
function rshiftPattern(array $overrides = []): ShiftType
{
    return ShiftType::query()->create(array_replace(['code' => Str::random(10), 'name' => 'Night '.Str::random(8), 'mode' => 'FIXED', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'work_days' => [3], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false], $overrides));
}

/** @param list<int> $days */
function rshiftDraft(ShiftType $pattern, array $days = [3]): ShiftTypeDraft
{
    return new ShiftTypeDraft($pattern->name.' edited', 'FIXED', $days, '21:00', '05:00', '0', '8', false, '00:00', '01:00', '0', false);
}

it('copies a chosen weekday independently of the pattern work week and combines hours with places', function () {
    $employee = rshiftEmployee();
    $actor = rshiftActor();
    $night = rshiftPattern();
    app(SaveRosterWeek::class)->handle($actor, $employee->id, [1 => $night->id, 2 => 'DEFAULT', 3 => 'REST'], [1 => 'WFH', 2 => 'DEFAULT']);
    $week = app(ShiftRoster::class)->scheduleForEmployee($employee->id);
    expect($week)->not->toBeNull();
    assert($week !== null);
    expect($week->forDate('2026-09-28')->startTime)->toBe('22:00:00')
        ->and($week->forDate('2026-09-28')->workDays)->toBe([1])
        ->and($week->forDate('2026-09-29')->startTime)->toBe('08:00:00')
        ->and(ShiftResolver::workWeek($week))->toBe([1, 2, 4, 5])
        ->and(RosterLocation::query()->sole()->kind)->toBe('WFH')
        ->and(AuditLog::query()->where('action', 'ROSTER_SHIFTS_SET')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ROSTER_LOCATIONS_SET')->count())->toBe(1);
});

it('leaves omitted weekdays unchanged, audits no-op saves once and sends DEFAULT weekdays back to the default shift', function () {
    $employee = rshiftEmployee();
    $actor = rshiftActor();
    $pattern = rshiftPattern();
    $command = app(SaveRosterShifts::class);
    $command->handle($actor, $employee->id, [1 => $pattern->id, 3 => 'REST']);
    $command->handle($actor, $employee->id, [1 => $pattern->id, 3 => 'REST']);
    expect(RosterShift::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'ROSTER_SHIFTS_SET')->count())->toBe(1);
    $command->handle($actor, $employee->id, [1 => 'DEFAULT']);
    // Monday's pattern row stays in the history behind a later row with no
    // hours; the week in effect has only Wednesday's rest.
    $week = app(RosterShifts::class)->weekly($actor, $employee->id);
    expect(array_keys($week))->toBe([3])
        ->and($week[3]->shift_type_id)->toBeNull()
        ->and($week[3]->shift_data['shift_type_id'])->toBeNull()
        ->and(RosterShift::query()->count())->toBe(3)
        ->and(RosterShift::query()->where('weekday', 1)->whereNull('shift_data')->count())->toBe(1);
});

it('refuses unauthorized writes without a roster row or audit', function (string $module, bool $allData) {
    $employee = rshiftEmployee();
    $actor = rshiftActor([$module], $allData);
    expect(fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => 'REST']))->toThrow(RuntimeException::class);
    expect(RosterShift::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
})->with([['ROSTER', false], ['RECORDS', true]]);

it('validates every weekday and pattern before leaving any write or audit', function (array $days) {
    $employee = rshiftEmployee();
    $actor = rshiftActor();
    expect(fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, $days))->toThrow(RuntimeException::class);
    expect(RosterShift::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
})->with([[['monday' => 'REST']], [[7 => 'REST']], [[1 => []]], [[1 => 'missing']], [[1 => 'KEEP']], [[1 => '']]]);

it('rolls weekday hours back if the same dialog contains an invalid worksite', function () {
    $employee = rshiftEmployee();
    $actor = rshiftActor();
    $pattern = rshiftPattern();
    expect(fn () => app(SaveRosterWeek::class)->handle($actor, $employee->id, [1 => $pattern->id], [1 => 'missing']))->toThrow(RuntimeException::class);
    expect(RosterShift::query()->count())->toBe(0)->and(RosterLocation::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

it('refuses a missing employee and KEEP on linked patterns or rest days', function () {
    $employee = rshiftEmployee();
    $actor = rshiftActor();
    $command = app(SaveRosterShifts::class);
    expect(fn () => $command->handle($actor, (string) Str::ulid(), [1 => 'REST']))->toThrow(RuntimeException::class, 'no longer exists');
    $command->handle($actor, $employee->id, [1 => rshiftPattern()->id, 2 => 'REST']);
    foreach ([1, 2] as $weekday) {
        expect(fn () => $command->handle($actor, $employee->id, [$weekday => 'KEEP']))->toThrow(RuntimeException::class, 'no longer available');
    }
    expect(RosterShift::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'ROSTER_SHIFTS_SET')->count())->toBe(1);
});

it('retains copied hours after pattern deletion and accepts KEEP only for that saved copy', function () {
    $employee = rshiftEmployee();
    $actor = rshiftActor();
    $pattern = rshiftPattern();
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => $pattern->id]);
    $pattern->delete();
    app(SaveRosterWeek::class)->handle($actor, $employee->id, [1 => 'KEEP'], [1 => 'WFH']);
    expect(RosterShift::query()->sole()->shift_type_id)->toBeNull()
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-09-28')?->startTime)->toBe('22:00:00')
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-09-28')?->shiftTypeId)->toBeNull();
});

it('scopes detached weekday reads before employee filters and refreshes actor access', function () {
    $own = rshiftEmployee();
    $other = rshiftEmployee();
    $manager = rshiftActor();
    app(SaveRosterShifts::class)->handle($manager, $own->id, [1 => 'REST']);
    app(SaveRosterShifts::class)->handle($manager, $other->id, [2 => 'REST']);
    $query = app(RosterShifts::class);
    expect(count($query->weeklyForEmployees($manager, [$own->id, $other->id])))->toBe(2);
    $self = rshiftActor(['ROSTER'], false, $own->id);
    expect(array_keys($query->weeklyForEmployees($self, [$own->id, $other->id])))->toBe([$own->id]);
    $unlinked = rshiftActor(['ROSTER'], false);
    expect($query->weeklyForEmployees($unlinked, [$own->id, $other->id]))->toBe([]);
});

it('re-copies edited hours onto linked weekdays and counts each affected employee once', function () {
    $employee = rshiftEmployee();
    $other = rshiftEmployee();
    $actor = rshiftActor();
    $pattern = rshiftPattern();
    app(AssignShift::class)->handle($actor, $employee->id, $pattern->id);
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => $pattern->id, 2 => $pattern->id]);
    app(SaveRosterShifts::class)->handle($actor, $other->id, [1 => $pattern->id]);
    expect(app(ReferenceData::class)->shiftTypeUsage($actor)[$pattern->id])->toBe(2);
    app(SaveShiftType::class)->handle($actor, $pattern->id, rshiftDraft($pattern));
    // The weeks in effect carry the new copy; the rows they replaced keep
    // the old one as history.
    foreach (app(RosterShifts::class)->weeklyForEmployees($actor, [$employee->id, $other->id]) as $week) {
        foreach ($week as $weekday => $view) {
            expect($view->shift_data['start_time'])->toBe('21:00:00')
                ->and($view->shift_data['work_days'])->toBe([$weekday]);
        }
    }
    expect(RosterShift::query()->count())->toBe(6);
    foreach (RosterShift::query()->whereNull('change_id')->orWhereNotIn('change_id', RosterScheduleChange::query()->select('id')->where('source', 'PATTERN_EDIT'))->get() as $row) {
        expect($row->shift_data['start_time'] ?? null)->toBe('22:00:00');
    }
    expect(AuditLog::query()->where('action', 'SHIFT_TYPE_UPDATED')->sole()->details)->toContain('2 rostered employees');
});

it('requires roster access to edit a pattern linked only through a weekday', function () {
    $employee = rshiftEmployee();
    $manager = rshiftActor();
    $pattern = rshiftPattern();
    app(SaveRosterShifts::class)->handle($manager, $employee->id, [1 => $pattern->id]);
    $records = rshiftActor(['RECORDS']);
    expect(fn () => app(SaveShiftType::class)->handle($records, $pattern->id, rshiftDraft($pattern)))->toThrow(RuntimeException::class, 'Shift Roster access');
    expect($pattern->fresh()?->start_time)->toBe('22:00:00')
        ->and(RosterShift::query()->sole()->shift_data['start_time'])->toBe('22:00:00')
        ->and(AuditLog::query()->where('action', 'SHIFT_TYPE_UPDATED')->count())->toBe(0);
});

it('refuses an all-rest week and a standing reassignment that would create one', function () {
    $employee = rshiftEmployee();
    $actor = rshiftActor();
    expect(fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, array_fill(0, 7, 'REST')))->toThrow(RuntimeException::class, 'at least one working day');
    expect(RosterShift::query()->count())->toBe(0);
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [3 => 'REST']);
    $pattern = rshiftPattern(['work_days' => [3]]);
    expect(fn () => app(AssignShift::class)->handle($actor, $employee->id, $pattern->id))->toThrow(RuntimeException::class, 'at least one working day');
    expect(Shift::query()->count())->toBe(0);
});

it('returns no schedule for an unrostered employee and batches mixed schedules', function () {
    $employee = rshiftEmployee();
    $unrostered = rshiftEmployee();
    $actor = rshiftActor();
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => rshiftPattern()->id]);
    expect(app(ShiftRoster::class)->scheduleForEmployee($unrostered->id))->toBeNull()
        ->and(array_keys(app(ShiftRoster::class)->scheduleForEmployees([$employee->id, $unrostered->id])))->toBe([$employee->id]);
});

it('rolls a pattern edit back when its new standing week would leave only rest overrides', function () {
    $employee = rshiftEmployee();
    $actor = rshiftActor();
    $pattern = rshiftPattern(['work_days' => [1, 3]]);
    app(AssignShift::class)->handle($actor, $employee->id, $pattern->id);
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [3 => 'REST']);
    expect(fn () => app(SaveShiftType::class)->handle($actor, $pattern->id, rshiftDraft($pattern, [3])))->toThrow(RuntimeException::class, 'at least one working day');
    expect($pattern->fresh()?->work_days)->toBe([1, 3])
        ->and(Shift::query()->sole()->work_days)->toBe([1, 3])
        ->and(AuditLog::query()->where('action', 'SHIFT_TYPE_UPDATED')->count())->toBe(0);
});
