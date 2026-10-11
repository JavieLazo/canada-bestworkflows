<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\BaseModel;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterShift;
use App\Models\User;
use App\Modules\Workforce\Application\Commands\DeleteEmployee;
use App\Modules\Workforce\Application\EmployeeRetention;
use App\Support\Access;
use Illuminate\Support\Str;

/** Saved weekly and date schedules protect their employee even without a standing shift or attendance history. */
function rretEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => 'RRET-'.Str::random(8), 'first_name' => 'Roster', 'last_name' => 'Retention',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => false,
    ]);
}

function rretActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Roster retention '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES])->save();

    return Access::forActor(User::factory()->create(['role_id' => $role->id]));
}

function rretSchedule(EmployeeProfile $employee, string $kind): BaseModel
{
    $hours = ['shift_name' => 'Regular', 'is_rest_day' => false, 'shift_data' => DomainShift::default()->toArray()];

    return match ($kind) {
        'weekly location' => RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 2, 'kind' => 'WFH', 'site_id' => null]),
        'weekly hours' => RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 2] + $hours),
        'date hours' => RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-29'] + $hours),
        'date location' => RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-29', 'location_kind' => 'WFH', 'is_rest_day' => false]),
        default => throw new InvalidArgumentException('Unknown roster fixture kind: '.$kind),
    };
}

it('refuses employee deletion when its only linked record is a saved roster setting', function (string $kind) {
    $employee = rretEmployee();
    $actor = rretActor();
    $retention = app(EmployeeRetention::class);
    expect($retention->any($employee->id))->toBeFalse();
    $schedule = rretSchedule($employee, $kind);

    expect($retention->any($employee->id))->toBeTrue();
    expect(fn () => app(DeleteEmployee::class)->handle($actor, $employee->id))
        ->toThrow(RuntimeException::class, 'linked or historical records');
    expect($employee->fresh())->not->toBeNull()
        ->and($schedule->fresh())->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditEvent::EMPLOYEE_DELETED)->count())->toBe(0);
})->with(['weekly location', 'weekly hours', 'date hours', 'date location']);

it('still permits deleting an unlinked inactive employee when another employee has a schedule', function () {
    $rostered = rretEmployee();
    $unlinked = rretEmployee();
    $schedule = rretSchedule($rostered, 'date location');

    expect(app(EmployeeRetention::class)->any($unlinked->id))->toBeFalse();
    app(DeleteEmployee::class)->handle(rretActor(), $unlinked->id);
    expect($unlinked->fresh())->toBeNull()
        ->and($rostered->fresh())->not->toBeNull()
        ->and($schedule->fresh())->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditEvent::EMPLOYEE_DELETED)->count())->toBe(1);
});
