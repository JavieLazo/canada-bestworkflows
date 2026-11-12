<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Roster\EmployeeSchedulePage;
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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * An employee's month on the Shift Roster changes their schedule, with
 * fresh authorization: the Default schedule dialog saves weekday hours and
 * places together, all or nothing, and the dates picked on the calendar
 * change those dates alone, over a weekly schedule they never touch — back
 * to it when the usual schedule is picked again, and worked when a shift is
 * picked for a date the week rests. Each picked date loads its own choices.
 */
/**
 * An employee with a shift assigned before, so a Default schedule may leave
 * days to it ("Assigned shift"); the no-shift case is RosterNoShiftTest's.
 */
function rosterWeekPageEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'WEEK-'.Str::random(8), 'first_name' => 'Weekly',
        'last_name' => 'Worker', 'date_hired' => '2024-01-01',
        'employment_status' => 'Regular', 'is_active' => true,
    ])->save();
    ScheduleFixture::standard($employee);

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

/**
 * The employee's month on the Shift Roster, as the signed-in actor.
 *
 * @return Testable<EmployeeSchedulePage>
 */
function rosterWeekPageMonth(EmployeeProfile $employee): Testable
{
    return Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);
}

/** One date's box on the month's calendar. */
function rosterWeekPageDay(string $html, string $date): string
{
    $needle = 'data-date="'.$date.'"';
    expect($html)->toContain($needle);

    return Str::before(Str::after($html, $needle), '</button>');
}

/* |---| The Default schedule dialog |---| */

it('saves Monday night at home and Tuesday default onsite hours from one Default schedule', function () {
    // Monday 2026-09-21, before tonight's 10:00 PM shift starts.
    $this->travelTo(new DateTimeImmutable('2026-09-21T05:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();

    $html = rosterWeekPageMonth($employee)
        ->call('openDefaultSchedule')
        ->assertSet('shiftDays.1', 'DEFAULT')
        ->set('shiftDays.1', $night->id)
        ->set('locationDays.1', 'WFH')
        ->set('shiftDays.6', 'REST')
        ->call('saveDefaultSchedule')
        ->assertSet('shiftDays', [])
        ->assertSet('locationDays', [])
        ->assertDispatched('modal-close', name: 'default-schedule')
        ->assertSee('10:00 PM–6:00 AM')
        ->html();
    // The month repaints from today: Monday nights at home, Saturdays off.
    expect(rosterWeekPageDay($html, '2026-09-21'))->toContain('Night shift')->toContain('10:00 PM–6:00 AM')->toContain('Work from home')
        ->and(rosterWeekPageDay($html, '2026-09-26'))->toContain('Off');

    expect(RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 1)->first()?->shift_type_id)->toBe($night->id)
        ->and(RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 2)->exists())->toBeFalse()
        ->and(RosterLocation::query()->where('employee_id', $employee->id)->where('weekday', 1)->first()?->kind)->toBe('WFH');

    rosterWeekPageMonth($employee)
        ->call('openDefaultSchedule')
        ->assertSet('shiftDays.1', $night->id)
        ->assertSet('shiftDays.2', 'DEFAULT')
        ->assertSet('shiftDays.6', 'REST')
        ->assertSet('locationDays.1', 'WFH');
});

it('keeps hours and locations unchanged when a weekday pattern is invalid', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-21T05:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);

    rosterWeekPageMonth($employee)
        ->call('openDefaultSchedule')
        ->set('shiftDays.1', 'missing-pattern')
        ->set('locationDays.1', 'WFH')
        ->call('saveDefaultSchedule')
        ->assertSet('shiftDays.1', 'missing-pattern')
        ->assertSet('locationDays.1', 'WFH')
        ->assertNotDispatched('modal-close');

    expect(RosterShift::query()->count())->toBe(0)
        ->and(RosterLocation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('preserves saved weekday hours after deleting the source pattern while changing a location', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-21T05:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();
    rosterWeekPageMonth($employee)->call('openDefaultSchedule')
        ->set('shiftDays.1', $night->id)->call('saveDefaultSchedule');
    $night->delete();

    rosterWeekPageMonth($employee)->call('openDefaultSchedule')
        ->assertSet('shiftDays.1', 'KEEP')
        ->assertSee('Night shift')
        ->set('locationDays.1', 'WFH')->call('saveDefaultSchedule');

    $row = RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 1)->firstOrFail();
    expect($row->shift_type_id)->toBeNull()
        ->and($row->shift_data['start_time'])->toBe('22:00:00');
});

it('refuses a restricted actor trying to save weekday hours and locations', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-21T05:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee, false);
    $night = rosterWeekPageNight();

    // The dialog does not open, and a draft written straight to the
    // component is refused all the same.
    rosterWeekPageMonth($employee)->call('openDefaultSchedule')
        ->assertNotDispatched('modal-show')
        ->set('shiftDays.1', $night->id)->set('locationDays.1', 'WFH')->call('saveDefaultSchedule');

    expect(RosterShift::query()->count())->toBe(0)
        ->and(RosterLocation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

/* |---| Picked dates |---| */

it('changes tomorrow without changing the recurring week and restores it when the usual schedule is picked again', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T12:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();

    rosterWeekPageMonth($employee)->call('openDefaultSchedule')
        ->set('shiftDays.2', $night->id)->set('locationDays.2', 'WFH')->call('saveDefaultSchedule');
    rosterWeekPageMonth($employee)
        ->assertSee('10:00 PM–6:00 AM')
        ->call('pick', '2026-09-29')
        ->assertSet('picked', ['2026-09-29'])
        ->assertSet('dayShift', 'DEFAULT')
        ->set('dayShift', 'REST')->call('saveDays')
        ->assertSet('picked', []);

    expect(RosterDayOverride::query()->where('employee_id', $employee->id)->first()?->is_rest_day)->toBeTrue()
        ->and(RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 2)->first()?->shift_type_id)->toBe($night->id);

    rosterWeekPageMonth($employee)->call('pick', '2026-09-29')
        ->assertSet('dayShift', 'REST')
        ->set('dayShift', 'DEFAULT')->set('dayLocation', 'DEFAULT')
        ->call('saveDays')
        ->assertSet('picked', []);
    // Removed, not deleted: the date change stays in the history, cancelled.
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->count())->toBe(0)
        ->and(RosterDayOverride::query()->count())->toBe(1);
    $page = rosterWeekPageMonth($employee)->call('pick', '2026-09-29')
        ->assertSet('dayShift', 'DEFAULT');
    expect(rosterWeekPageDay($page->html(), '2026-09-29'))->toContain('10:00 PM–6:00 AM');
});

it('reloads a picked date’s own choices instead of carrying another date’s draft across', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T12:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();
    rosterWeekPageMonth($employee)->call('pick', '2026-09-29')
        ->set('dayShift', $night->id)->set('dayLocation', 'WFH')->call('saveDays');

    rosterWeekPageMonth($employee)->call('pick', '2026-09-29')
        ->assertSet('dayShift', $night->id)->assertSet('dayLocation', 'WFH')
        // A second date takes the card as it stands: one shift for both.
        ->call('pick', '2026-09-30')
        ->assertSet('picked', ['2026-09-29', '2026-09-30'])
        ->assertSet('dayShift', $night->id)
        // Left alone, the 30th shows its own choices again.
        ->call('pick', '2026-09-29')
        ->assertSet('picked', ['2026-09-30'])
        ->assertSet('dayShift', 'DEFAULT')->assertSet('dayLocation', 'DEFAULT')
        ->call('pick', '2026-09-30')
        ->assertSet('picked', [])
        ->call('pick', '2026-09-29')
        ->assertSet('dayShift', $night->id)->assertSet('dayLocation', 'WFH');
});

it('works a date the weekly schedule rests when a shift is picked for it, and shows it afterwards', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T12:00:00+08:00'));
    $employee = rosterWeekPageEmployee();
    rosterWeekPageActor($employee);
    $night = rosterWeekPageNight();

    // Saturday Oct 3 rests. Picking it is the explicit choice to work it:
    // there is no separate rest-day box.
    rosterWeekPageMonth($employee)->set('month', '2026-10')
        ->call('pick', '2026-10-03')
        ->assertSet('picked', ['2026-10-03'])
        ->assertDontSee('Schedule work on this rest day')
        ->set('dayShift', $night->id)->call('saveDays')
        ->assertSet('picked', []);
    expect(RosterDayOverride::query()->sole()->include_rest_days)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'ROSTER_DATE_SET')->count())->toBe(1);

    rosterWeekPageMonth($employee)->set('month', '2026-10')
        ->call('pick', '2026-10-03')
        ->assertSet('dayShift', $night->id);
});
