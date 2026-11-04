<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Roster\RosterPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** The weekly editor saves hours and places together, with fresh authorization. */
function rosterWeekPageEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'WEEK-'.Str::random(8), 'first_name' => 'Weekly',
        'last_name' => 'Worker', 'date_hired' => '2024-01-01',
        'employment_status' => 'Regular', 'is_active' => true,
    ])->save();

    return $employee;
}

function rosterWeekPageActor(EmployeeProfile $employee, bool $allData = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Week '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::ROSTER])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function rosterWeekPageNight(): ShiftType
{
    return ShiftType::query()->create([
        'code' => 'N-'.Str::random(8), 'name' => 'Night shift', 'mode' => 'FIXED',
        'start_time' => '22:00:00', 'end_time' => '06:00:00',
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 15,
        'break_minutes' => 0, 'requires_break_punch' => false,
    ]);
}

it('saves Monday night at home and Tuesday default onsite hours from one weekly editor', function () {
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();

    Livewire::test(RosterPage::class)
        ->call('openLocations', $employee->id)
        ->assertSet('shiftDays.1', 'DEFAULT')
        ->set('shiftDays.1', $night->id)
        ->set('locationDays.1', 'WFH')
        ->set('shiftDays.6', 'REST')
        ->call('saveLocations')
        ->assertSet('locationsEmployeeId', '')
        ->assertSet('shiftDays', [])
        ->assertSee('10:00 PM–6:00 AM')
        ->assertSee('Rest day');

    expect(RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 1)->first()?->shift_type_id)->toBe($night->id)
        ->and(RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 2)->exists())->toBeFalse()
        ->and(RosterLocation::query()->where('employee_id', $employee->id)->where('weekday', 1)->first()?->kind)->toBe('WFH');

    Livewire::test(RosterPage::class)
        ->call('openLocations', $employee->id)
        ->assertSet('shiftDays.1', $night->id)
        ->assertSet('shiftDays.2', 'DEFAULT')
        ->assertSet('shiftDays.6', 'REST')
        ->assertSet('locationDays.1', 'WFH');
});

it('keeps hours and locations unchanged when a weekday pattern is invalid', function () {
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);

    Livewire::test(RosterPage::class)
        ->call('openLocations', $employee->id)
        ->set('shiftDays.1', 'missing-pattern')
        ->set('locationDays.1', 'WFH')
        ->call('saveLocations')
        ->assertSet('locationsEmployeeId', $employee->id)
        ->assertNotDispatched('modal-close');

    expect(RosterShift::query()->count())->toBe(0)
        ->and(RosterLocation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('preserves saved weekday hours after deleting the source pattern while changing a location', function () {
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();
    Livewire::test(RosterPage::class)->call('openLocations', $employee->id)
        ->set('shiftDays.1', $night->id)->call('saveLocations');
    $night->delete();

    Livewire::test(RosterPage::class)->call('openLocations', $employee->id)
        ->assertSet('shiftDays.1', 'KEEP')
        ->assertSee('Night shift')
        ->set('locationDays.1', 'WFH')->call('saveLocations');

    $row = RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 1)->firstOrFail();
    expect($row->shift_type_id)->toBeNull()
        ->and($row->shift_data['start_time'])->toBe('22:00:00');
});

it('refuses a restricted actor trying to save weekday hours and locations', function () {
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee, false);
    $night = rosterWeekPageNight();

    Livewire::test(RosterPage::class)->call('openLocations', $employee->id)
        ->set('shiftDays.1', $night->id)->set('locationDays.1', 'WFH')->call('saveLocations');

    expect(RosterShift::query()->count())->toBe(0)
        ->and(RosterLocation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('includes weekday-only pattern assignments in the roster filter', function () {
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();
    Livewire::test(RosterPage::class)->call('openLocations', $employee->id)
        ->set('shiftDays.1', $night->id)->call('saveLocations');

    Livewire::test(RosterPage::class)->set('pattern', $night->id)
        ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1);
    Livewire::test(RosterPage::class)->set('pattern', 'UNASSIGNED')
        ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 0);
});

it('changes tomorrow without changing the recurring week and restores it when the date change is removed', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T12:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();

    Livewire::test(RosterPage::class)->call('openLocations', $employee->id)
        ->set('shiftDays.2', $night->id)->set('locationDays.2', 'WFH')->call('saveLocations');
    Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->assertSet('scheduleDate', '2026-09-29')
        ->assertSee('10:00 PM–6:00 AM')
        ->set('dateShiftChoice', 'REST')->call('saveDateSchedule')
        ->assertSet('dateEmployeeId', '');

    expect(RosterDayOverride::query()->where('employee_id', $employee->id)->first()?->is_rest_day)->toBeTrue()
        ->and(RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 2)->first()?->shift_type_id)->toBe($night->id);

    Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->assertSet('dateShiftChoice', 'REST')
        ->set('dateShiftChoice', 'DEFAULT')->set('dateLocationChoice', 'DEFAULT')
        ->call('saveDateSchedule');
    // Removed, not deleted: the date change stays in the history, cancelled.
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->count())->toBe(0)
        ->and(RosterDayOverride::query()->count())->toBe(1);
    Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->assertSet('dateShiftChoice', 'DEFAULT')->assertSee('10:00 PM–6:00 AM');
});

it('reloads the selected dates own choices instead of carrying another dates draft across', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T12:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();
    Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->set('dateShiftChoice', $night->id)->set('dateLocationChoice', 'WFH')->call('saveDateSchedule');

    Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->assertSet('dateShiftChoice', $night->id)
        ->set('scheduleDate', '2026-09-30')
        ->assertSet('dateShiftChoice', 'DEFAULT')->assertSet('dateLocationChoice', 'DEFAULT')
        ->set('scheduleDate', '2026-09-29')
        ->assertSet('dateShiftChoice', $night->id)->assertSet('dateLocationChoice', 'WFH');
});

it('offers the rest-day choice only on a date the weekly schedule rests, and refuses a shift there without it', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T12:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();

    // Tuesday works: no rest-day choice is shown.
    Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->assertSet('scheduleDate', '2026-09-29')
        ->assertDontSee('Schedule work on this rest day');

    // Saturday rests: the shift alone is refused and nothing is written.
    $page = Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->set('scheduleDate', '2026-10-03')
        ->assertSee('Schedule work on this rest day')
        ->set('dateShiftChoice', $night->id)->call('saveDateSchedule')
        ->assertSet('dateEmployeeId', $employee->id);
    expect(RosterDayOverride::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'ROSTER_DATE_SET')->count())->toBe(0);

    // The explicit choice saves it, and the dialog shows it ticked afterwards.
    $page->set('dateWorkOnRestDay', true)->call('saveDateSchedule')->assertSet('dateEmployeeId', '');
    expect(RosterDayOverride::query()->sole()->include_rest_days)->toBeTrue();
    Livewire::test(RosterPage::class)->call('openDateSchedule', $employee->id)
        ->set('scheduleDate', '2026-10-03')
        ->assertSet('dateWorkOnRestDay', true)
        ->assertSet('dateShiftChoice', $night->id);
});
