<?php

declare(strict_types=1);

use App\Livewire\Roster\EmployeeSchedulePage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Queries\RosterHistory;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * Custom hours on the Shift Roster: a weekday of the weekly schedule, or a
 * temporary schedule, may take hours typed in on the roster instead of a
 * Shifting Settings pattern. They are stored as a pattern's copy is — with
 * no pattern behind them (`shift_type_id` NULL) and the name "Custom hours" —
 * so the engine reads them like any other shift. Hours the shape rules
 * refuse leave no row and no audit. The Default schedule dialog on the
 * employee's month offers every set it lists on every day as `CUSTOM:n`,
 * and reopening it rebuilds the list from the saved days.
 */

// Monday, before any shift starts, so today is still an eligible date.
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-21T05:00:00+08:00'));
});

/**
 * An employee with a shift assigned before, so a Default schedule may leave
 * days to it; the no-shift case is RosterNoShiftTest's.
 */
function rchEmployee(): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'RCH-'.Str::random(10), 'first_name' => 'Custom', 'last_name' => 'Hours', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
    ScheduleFixture::standard($employee);

    return $employee;
}

/** @param list<string> $modules */
function rchActor(array $modules = ['ROSTER', 'RECORDS'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Custom hours '.Str::random(10), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/* |--------------------------------------------------------------------------
   | The weekly schedule
   |-------------------------------------------------------------------------- */

it('stores custom hours on a weekday without a pattern and reads them back as the day’s shift', function () {
    $employee = rchEmployee();
    $actor = rchActor();
    $custom = ['start' => '07:00', 'end' => '15:00', 'break_start' => '11:00', 'break_end' => '12:00', 'grace' => '10'];

    app(SaveRosterWeek::class)->handle($actor, $employee->id, [1 => $custom], []);

    $row = RosterShift::query()->sole();
    $day = app(ShiftRoster::class)->forDate($employee->id, '2026-09-28');
    expect($row->weekday)->toBe(1)
        ->and($row->shift_name)->toBe('Custom hours')
        ->and($row->shift_type_id)->toBeNull()
        ->and($row->is_rest_day)->toBeFalse()
        ->and($day?->startTime)->toBe('07:00:00')
        ->and($day?->endTime)->toBe('15:00:00')
        ->and($day?->breakStart)->toBe('11:00:00')
        ->and($day?->breakEnd)->toBe('12:00:00')
        ->and($day?->breakMinutes)->toBe(60)
        ->and($day?->graceMinutes)->toBe(10)
        ->and($day?->shiftTypeId)->toBeNull()
        ->and(AuditLog::query()->where('action', 'ROSTER_SHIFTS_SET')->count())->toBe(1);
});

it('refuses custom hours without an end time, or with a break outside the shift, with no row or audit', function (array $custom, string $message) {
    $employee = rchEmployee();
    $actor = rchActor();

    expect(fn () => app(SaveRosterWeek::class)->handle($actor, $employee->id, [1 => $custom], []))
        ->toThrow(RuntimeException::class, $message);
    expect(RosterShift::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'no end' => [['start' => '07:00', 'end' => '', 'break_start' => '', 'break_end' => '', 'grace' => '15'], 'Custom hours need a start and an end time.'],
    'break outside' => [['start' => '07:00', 'end' => '15:00', 'break_start' => '16:00', 'break_end' => '17:00', 'grace' => '15'], 'The break window must fall inside the shift.'],
]);

/* |--------------------------------------------------------------------------
   | A temporary schedule
   |-------------------------------------------------------------------------- */

it('saves custom hours on a temporary schedule and names them in the history', function () {
    $employee = rchEmployee();
    $actor = rchActor();

    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-09-22', null, ['start' => '13:00', 'end' => '21:00', 'break_start' => '', 'break_end' => '', 'grace' => '5'], 'DEFAULT');

    $row = RosterDayOverride::query()->sole();
    $history = app(RosterHistory::class)->forEmployee($actor, $employee->id)['temporary'][0];
    expect($row->shift_name)->toBe('Custom hours')
        ->and($row->shift_type_id)->toBeNull()
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-09-22')?->startTime)->toBe('13:00:00')
        ->and($history->shift_choice)->toBe('CUSTOM')
        ->and($history->custom_hours)->toBe(['start' => '13:00', 'end' => '21:00', 'break_start' => '', 'break_end' => '', 'grace' => '5']);
});

/* |--------------------------------------------------------------------------
   | The Default schedule dialog
   |-------------------------------------------------------------------------- */

it('saves custom hours from the Default schedule dialog and opens it again as saved', function () {
    $employee = rchEmployee();
    rchActor();

    Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])
        ->call('openDefaultSchedule')
        ->call('addCustomHours')
        ->set('customHours.0.start', '07:00')
        ->set('customHours.0.end', '15:00')
        ->set('shiftDays.1', 'CUSTOM:0')
        ->set('shiftDays.3', 'CUSTOM:0')
        ->call('saveDefaultSchedule')
        ->assertHasNoErrors()
        ->assertSet('shiftDays', [])
        ->assertDispatched('modal-close', name: 'default-schedule');

    $rows = RosterShift::query()->orderBy('weekday')->get();
    expect($rows->pluck('weekday')->all())->toBe([1, 3])
        ->and($rows->pluck('shift_name')->unique()->all())->toBe(['Custom hours'])
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-09-28')?->startTime)->toBe('07:00:00')
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-09-30')?->endTime)->toBe('15:00:00');

    // One set of hours used on two days is listed once.
    Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])
        ->call('openDefaultSchedule')
        ->assertSet('shiftDays.1', 'CUSTOM:0')
        ->assertSet('shiftDays.3', 'CUSTOM:0')
        ->assertSet('shiftDays.2', 'DEFAULT')
        ->assertSet('customSlots', [0])
        ->assertSet('customHours.0', ['start' => '07:00', 'end' => '15:00', 'break_start' => '', 'break_end' => '', 'grace' => '15'])
        ->assertSee('7:00 AM–3:00 PM (custom)');
});

it('draws every custom-hours slot and leaves the day selects and hours to the browser until Save', function () {
    $employee = rchEmployee();
    rchActor();

    $html = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->call('openDefaultSchedule')->html();

    // Seven slots, none in use and all hidden; adding and removing is Alpine's.
    expect(substr_count($html, 'wire:key="custom-hours-'))->toBe(EmployeeSchedulePage::CUSTOM_SETS)
        ->and($html)->toContain('x-on:click="addSet()"')
        ->and($html)->toContain('x-on:click="removeSet(0)"')
        // No change of a day or of an hour asks the server on its own.
        ->and($html)->toContain('wire:model="shiftDays.1"')
        ->and($html)->not->toContain('wire:model.live="shiftDays.')
        ->and($html)->not->toContain('wire:model.live="customHours.')
        ->and($html)->not->toContain('wire:click="addCustomHours"');
});

it('lists the custom hours first in each day’s shift choices, before the patterns', function () {
    $employee = rchEmployee();
    rchActor();
    ShiftType::query()->create(['code' => 'RCH-P', 'name' => 'Zeta pattern', 'mode' => 'FIXED', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'work_days' => [0, 1, 2, 3, 4, 5, 6], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);

    $html = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])
        ->call('openDefaultSchedule')
        ->call('addCustomHours')
        ->set('customHours.0.start', '07:00')
        ->set('customHours.0.end', '15:00')
        ->html();
    $monday = Str::after($html, 'id="shift-day-1"');

    expect(strpos($monday, '7:00 AM–3:00 PM (custom)'))->toBeInt()
        ->and(strpos($monday, '7:00 AM–3:00 PM (custom)'))->toBeLessThan((int) strpos($monday, 'Zeta pattern'));
});

it('puts the days of removed custom hours back on the default shift', function () {
    $employee = rchEmployee();
    rchActor();

    Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])
        ->call('openDefaultSchedule')
        ->call('addCustomHours')
        ->call('addCustomHours')
        ->set('shiftDays.1', 'CUSTOM:0')
        ->set('shiftDays.2', 'CUSTOM:1')
        ->call('removeCustomHours', 0)
        ->assertSet('shiftDays.1', 'DEFAULT')
        // The other set keeps its slot, so no other day's pick moves.
        ->assertSet('shiftDays.2', 'CUSTOM:1')
        ->assertSet('customSlots', [1])
        // A pick of the removed slot is refused rather than saved blank.
        ->set('shiftDays.3', 'CUSTOM:0')
        ->call('saveDefaultSchedule');
    expect(RosterShift::query()->count())->toBe(0);
});
