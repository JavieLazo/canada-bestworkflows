<?php

declare(strict_types=1);

use App\Livewire\Portal\MySchedule;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Roster\EmployeeSchedulePage;
use App\Livewire\Roster\RosterPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\FillUnscheduledDates;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDays;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Queries\RosterCalendar;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A date with NO SHIFT is measured against the engine's 8:00–5:00 weekday
 * and holds the payroll that covers it, so the Shift Roster keeps such dates
 * from arising and lets HR settle the past ones:
 *
 *   - Default schedule asks a shift or Rest day for every day of an
 *     employee with no shift assigned before, and SaveRosterWeek refuses a
 *     week that leaves one;
 *   - a past date with no shift while employed is picked on the employee's
 *     month apart from later dates and filled (FillUnscheduledDates) — never
 *     a date with a schedule, never today or later, never one on a posted
 *     payslip, never so that a neighbouring date's record moves;
 *   - the narrow undos (a future Default schedule cancelled, a whole
 *     temporary schedule removed), Go back to the Default schedule, and a
 *     save refused over a date someone changed after the page drew it;
 *   - holidays are named, an employee who left during the week is listed;
 *   - every saved change tells the employee, and My Portal shows their own
 *     month, even to an all-data holder.
 */
beforeEach(function () {
    // Monday 2026-11-16, before any shift here starts.
    $this->travelTo(new DateTimeImmutable('2026-11-16T05:00:00+08:00'));
});

/** @param list<string> $modules */
function rnsActor(array $modules = ['ROSTER'], bool $allData = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'No shift '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function rnsPattern(string $name, string $start, string $end): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => $name, 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [0, 1, 2, 3, 4, 5, 6], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

function rnsEmployee(string $first, string $last, ?ShiftType $default = null, string $hired = '2024-01-01', bool $active = true, ?string $resigned = null): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'NS-'.Str::random(8), 'first_name' => $first, 'last_name' => $last,
        'position' => 'Clerk', 'employment_status' => 'Regular', 'date_hired' => $hired, 'date_resigned' => $resigned, 'is_active' => $active]);
    if ($default !== null) {
        Shift::query()->create(ShiftPolicy::attributesFromPattern($default) + ['employee_id' => $employee->id]);
    }

    return $employee;
}

/** One date's box on the employee's month. */
function rnsBox(string $html, string $date): string
{
    $needle = 'data-date="'.$date.'"';
    expect($html)->toContain($needle);

    return Str::before(Str::after($html, $needle), '</button>');
}

/* |---| Default schedule: a shift or Rest day on every day |---| */

it('asks a shift or Rest day for every day of an employee with no shift assigned before', function () {
    $employee = rnsEmployee('Nina', 'Cruz');
    $day = rnsPattern('Day', '08:00:00', '17:00:00');
    $hr = rnsActor();
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->call('openDefaultSchedule');

    // Every day starts to be chosen; there is no "No shift" to leave it on.
    expect($page->get('shiftDays'))->toBe(array_fill(0, 7, ''))
        ->and($page->html())->toContain('Choose a shift…')
        ->and($page->html())->not->toContain('Assigned shift');
    $page->call('addCustomHours')->set('shiftDays.1', 'CUSTOM:0')->call('removeCustomHours', 0)->assertSet('shiftDays.1', '');

    // Two days chosen, five blank: nothing is saved.
    $page->set('shiftDays.1', $day->id)->set('shiftDays.2', $day->id)->call('saveDefaultSchedule');
    expect(RosterShift::query()->count())->toBe(0);

    // The command refuses the same gap, naming the days, with no row or audit.
    expect(fn () => app(SaveRosterWeek::class)->handle(Access::forActor($hr), $employee->id, [1 => $day->id], []))
        ->toThrow(RuntimeException::class, 'Choose a shift or Rest day for Sunday, Tuesday, Wednesday, Thursday, Friday and Saturday.');
    expect(RosterShift::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'ROSTER_SHIFTS_SET')->count())->toBe(0);

    // Every day set: saved.
    foreach ([3, 4, 5] as $weekday) {
        $page->set("shiftDays.{$weekday}", $day->id);
    }
    $page->set('shiftDays.6', 'REST')->set('shiftDays.0', 'REST')->call('saveDefaultSchedule')->assertSet('shiftDays', []);
    expect(RosterShift::query()->where('employee_id', $employee->id)->count())->toBe(7)
        ->and(app(RosterCalendar::class)->forEmployees(Access::forActor($hr), [$employee->id], '2026-11-21', '2026-11-21')[$employee->id]['2026-11-21']->label)->toBe('Rest day');
});

it('goes back from the review to the Default schedule with the week exactly as it was sent', function () {
    $employee = rnsEmployee('Liza', 'Domingo', rnsPattern('Day', '08:00:00', '17:00:00'));
    $am = rnsPattern('AM', '06:00:00', '14:00:00');
    rnsActor();
    // 10:00 on Monday: today's Day shift has started, so the change is held.
    $this->travelTo(new DateTimeImmutable('2026-11-16T10:00:00+08:00'));
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])
        ->call('openDefaultSchedule')
        ->call('addCustomHours')->set('customHours.0.start', '07:00')->set('customHours.0.end', '15:00')
        ->set('shiftDays.1', $am->id)->set('shiftDays.3', 'REST')->set('shiftDays.4', 'CUSTOM:0')->set('locationDays.1', 'WFH')
        ->call('saveDefaultSchedule');
    expect($page->get('reviewAction'))->toBe('week');

    // Closing the dialog for the review dropped its draft, as the browser does.
    $page->set('shiftDays', [])->set('locationDays', [])->set('customHours', [])->set('customSlots', [])
        ->call('backToEditor')
        ->assertDispatched('modal-show', name: 'default-schedule')
        ->assertSet('reviewAction', '')
        ->assertSet('shiftDays.1', $am->id)
        ->assertSet('shiftDays.3', 'REST')
        ->assertSet('shiftDays.4', 'CUSTOM:0')
        ->assertSet('customHours.0.start', '07:00')
        ->assertSet('locationDays.1', 'WFH');
    expect(RosterShift::query()->count())->toBe(0);
});

/* |---| Filling past dates that had no shift |---| */

it('fills past dates that had no shift as one run, audits it and tells the employee', function () {
    $employee = rnsEmployee('Nina', 'Cruz', hired: '2026-11-09');
    $day = rnsPattern('Day', '08:00:00', '17:00:00');
    $account = User::factory()->create(['employee_id' => $employee->id]);
    $hr = rnsActor();
    $actor = Access::forActor($hr);

    $message = app(FillUnscheduledDates::class)->handle($actor, $employee->id, ['2026-11-11', '2026-11-10'], $day->id, 'DEFAULT');

    $days = app(RosterCalendar::class)->forEmployees($actor, [$employee->id], '2026-11-10', '2026-11-12')[$employee->id];
    expect($message)->toContain('Saved: 2 past dates for Nina Cruz')
        ->and($days['2026-11-10']->rostered)->toBeTrue()
        ->and($days['2026-11-11']->label)->toBe('Day')
        ->and($days['2026-11-12']->rostered)->toBeFalse()
        ->and(RosterDayOverride::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ROSTER_UNSCHEDULED_FILLED')->sole()->details)->toContain('2026-11-10 to 2026-11-11')
        ->and(Notification::query()->where('user_id', $account->id)->where('kind', 'SCHEDULE_CHANGED')->sole()->href)->toBe('/portal?tab=schedule')
        ->and(Notification::query()->where('user_id', $account->id)->sole()->title)->toBe('Your schedule for Nov 10–11, 2026 changed')
        ->and(Notification::query()->where('user_id', $hr->id)->count())->toBe(0);
});

it('fills only past dates with no shift, and only for an all-data roster manager', function () {
    $employee = rnsEmployee('Nina', 'Cruz', hired: '2026-11-09');
    $day = rnsPattern('Day', '08:00:00', '17:00:00');
    $actor = Access::forActor(rnsActor());
    $fill = app(FillUnscheduledDates::class);
    $fill->handle($actor, $employee->id, ['2026-11-10'], $day->id, 'DEFAULT');

    expect(fn () => $fill->handle($actor, $employee->id, ['2026-11-10'], 'REST', 'DEFAULT'))->toThrow(RuntimeException::class, 'has a schedule now')
        ->and(fn () => $fill->handle($actor, $employee->id, ['2026-11-16'], 'REST', 'DEFAULT'))->toThrow(RuntimeException::class, 'Only dates before today')
        ->and(fn () => $fill->handle($actor, $employee->id, ['2026-11-12'], 'DEFAULT', 'DEFAULT'))->toThrow(RuntimeException::class, 'Pick a shift or Rest day')
        // Before the date hired there was no employment to schedule.
        ->and(fn () => $fill->handle($actor, $employee->id, ['2026-11-05', '2026-11-12'], 'REST', 'DEFAULT'))->toThrow(RuntimeException::class, 'Nina Cruz was not employed on Nov 5, 2026.');
    $viewer = Access::forActor(rnsActor(allData: false));
    expect(fn () => $fill->handle($viewer, $employee->id, ['2026-11-12'], 'REST', 'DEFAULT'))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(RosterDayOverride::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ROSTER_UNSCHEDULED_FILLED')->count())->toBe(1);
});

it('refuses a date already paid on a posted payslip', function () {
    $employee = rnsEmployee('Nina', 'Cruz', hired: '2026-11-09');
    $actor = Access::forActor(rnsActor());
    app()->instance(PostedPayrollDates::class, new class implements PostedPayrollDates
    {
        public function coveredPeriods(Access $actor, array $employeeIds, string $start, string $end, string $module = 'EDTR'): array
        {
            return [];
        }

        public function lock(): void {}

        public function lockAndCovers(string $employeeId, string $date): bool
        {
            return $date === '2026-11-10';
        }

        public function lockAndPaidThrough(string $employeeId, string $from, ?string $to = null): ?string
        {
            return null;
        }

        public function lockAndPaidDates(string $employeeId, string $from, string $to): array
        {
            return $from <= '2026-11-10' && $to >= '2026-11-10' ? ['2026-11-10'] : [];
        }
    });

    expect(fn () => app(FillUnscheduledDates::class)->handle($actor, $employee->id, ['2026-11-10', '2026-11-11'], 'REST', 'DEFAULT'))
        ->toThrow(RuntimeException::class, 'Nov 10, 2026 is already paid on a posted payslip');
    expect(RosterDayOverride::query()->count())->toBe(0);
});

it('refuses a fill that would take a punch from the date after it', function () {
    $employee = rnsEmployee('Nina', 'Cruz');
    $day = rnsPattern('Day', '08:00:00', '17:00:00');
    $night = rnsPattern('Night', '22:00:00', '06:00:00');
    // Tuesdays are scheduled; Monday 2026-11-09 had no shift. Its 10:00 PM
    // Time In and a 6:05 AM Time Out entered on Tuesday's record.
    RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 2, 'shift_type_id' => $day->id, 'shift_name' => 'Day', 'is_rest_day' => false,
        'shift_data' => App\Domain\Attendance\Shift::fromArray(array_replace(ShiftPolicy::attributesFromPattern($day), ['work_days' => [2]]))->toArray()]);
    foreach ([['TIME_IN', '2026-11-09 14:00:00'], ['TIME_OUT', '2026-11-09 22:05:00']] as [$type, $at]) {
        AttendanceLog::query()->create(['employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL', 'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => $at]);
    }
    $actor = Access::forActor(rnsActor());

    expect(fn () => app(FillUnscheduledDates::class)->handle($actor, $employee->id, ['2026-11-09'], $night->id, 'DEFAULT'))
        ->toThrow(RuntimeException::class, 'would also change the attendance of a date next to the ones picked');
    expect(RosterDayOverride::query()->count())->toBe(0);
});

it('picks a past date with no shift apart from later dates, and fills it from the side card', function () {
    $employee = rnsEmployee('Nina', 'Cruz', hired: '2026-11-11');
    $day = rnsPattern('Day', '08:00:00', '17:00:00');
    rnsActor();
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);

    // Nov 10 is before the date hired; Nov 12 had no shift while employed.
    expect(rnsBox($page->html(), '2026-11-12'))->toContain('Pick to set')
        ->and(rnsBox($page->html(), '2026-11-10'))->not->toContain('Pick to set');
    $page->call('pick', '2026-11-10')->assertSet('picked', [])
        ->call('pick', '2026-11-12')->call('pick', '2026-11-13')->assertSet('picked', ['2026-11-12', '2026-11-13'])->assertSet('dayShift', '')
        ->assertSee('payroll cannot be finalized over them')
        // A later date starts a selection of its own, and back again.
        ->call('pick', '2026-11-18')->assertSet('picked', ['2026-11-18'])
        ->call('pick', '2026-11-12')->assertSet('picked', ['2026-11-12'])
        ->set('dayShift', $day->id)->call('saveDays')->assertSet('picked', []);
    expect(RosterDayOverride::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ROSTER_UNSCHEDULED_FILLED')->count())->toBe(1);

    // Past and later dates written straight into the draft are refused.
    $page->set('picked', ['2026-11-13', '2026-11-18'])->set('dayShift', 'REST')->call('saveDays');
    expect(RosterDayOverride::query()->count())->toBe(1);
});

it('offers the custom hours’ break and grace in the side card', function () {
    $employee = rnsEmployee('Nina', 'Cruz', rnsPattern('Day', '08:00:00', '17:00:00'));
    rnsActor();

    Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])
        ->call('pick', '2026-11-18')->set('dayShift', 'CUSTOM')
        ->assertSee('Break From')->assertSee('Break To')->assertSee('Grace (min)');
});

/* |---| Saving over a change, and the undos |---| */

it('refuses a save over a date someone changed after the page drew it, then saves once it is seen', function () {
    $employee = rnsEmployee('Nina', 'Cruz', rnsPattern('Day', '08:00:00', '17:00:00'));
    $am = rnsPattern('AM', '06:00:00', '14:00:00');
    $hr = rnsActor();
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])
        ->set('picked', ['2026-11-18'])->set('dayShift', $am->id);

    app(SaveRosterDays::class)->handle(Access::forActor($hr), $employee->id, ['2026-11-18'], 'REST', 'DEFAULT');
    $page->call('saveDays');
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->sole()->is_rest_day)->toBeTrue();

    $page->call('saveDays');
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->sole()->shift_type_id)->toBe($am->id);
});

it('cancels a Default schedule saved for a later date from the month', function () {
    $employee = rnsEmployee('Nina', 'Cruz', rnsPattern('Day', '08:00:00', '17:00:00'));
    $am = rnsPattern('AM', '06:00:00', '14:00:00');
    $hr = rnsActor();
    app(SaveRosterWeek::class)->handle(Access::forActor($hr), $employee->id, [1 => $am->id], [], '2026-11-23');
    $change = RosterScheduleChange::query()->where('employee_id', $employee->id)->sole();
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);
    expect($page->html())->toContain('data-upcoming-changes')->toContain('Nov 23, 2026');

    $page->call('cancelChange', 'not-a-change');
    expect($change->fresh()?->status)->toBe(RosterScheduleChange::STATUS_ACTIVE);

    $page->call('cancelChange', $change->id);
    expect($change->fresh()?->status)->toBe(RosterScheduleChange::STATUS_CANCELLED)
        ->and($page->html())->not->toContain('data-upcoming-changes');
});

it('removes a whole temporary schedule from one of its dates', function () {
    $employee = rnsEmployee('Nina', 'Cruz', rnsPattern('Day', '08:00:00', '17:00:00'));
    $hr = rnsActor();
    app(SaveRosterDays::class)->handle(Access::forActor($hr), $employee->id, ['2026-11-18', '2026-11-19', '2026-11-20'], 'REST', 'DEFAULT');
    $row = RosterDayOverride::query()->sole();

    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->call('pick', '2026-11-19');
    expect($page->html())->toContain('Nov 18 – Nov 20, 2026');

    $page->call('removeTemporary', 'not-a-schedule');
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->count())->toBe(1);

    $page->call('removeTemporary', $row->id)->assertSet('picked', []);
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->count())->toBe(0);
});

/* |---| Holidays and employees who left |---| */

it('names a holiday under its day on the week table and on the month', function () {
    $employee = rnsEmployee('Nina', 'Cruz', rnsPattern('Day', '08:00:00', '17:00:00'));
    Holiday::query()->create(['code' => 'NS-HOL', 'name' => 'Bonifacio Day', 'date' => '2026-11-30', 'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 200]);
    rnsActor();

    Livewire::test(RosterPage::class)->set('week', '2026-11-30')->assertSee('Bonifacio Day');
    Livewire::test(RosterPage::class)->assertDontSee('Bonifacio Day');
    $html = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->html();
    expect(rnsBox($html, '2026-11-30'))->toContain('Bonifacio Day')
        ->and(rnsBox($html, '2026-11-27'))->not->toContain('Bonifacio Day');
});

it('lists an employee who left during the week, marked, and not in the weeks after', function () {
    rnsEmployee('Paolo', 'Reyes', active: false, resigned: '2026-11-18');
    rnsEmployee('Ana', 'Lim');
    rnsActor();

    $page = Livewire::test(RosterPage::class);
    expect($page->html())->toContain('Reyes')->toContain('Lim')->toContain('Left on Nov 18, 2026');
    $page->set('week', '2026-11-23');
    expect($page->html())->not->toContain('Reyes')
        ->and($page->html())->toContain('Lim');
});

/* |---| Telling the employee, and My Portal |---| */

it('tells the employee when a change is saved, and nothing for a preview', function () {
    $employee = rnsEmployee('Nina', 'Cruz', rnsPattern('Day', '08:00:00', '17:00:00'));
    $account = User::factory()->create(['employee_id' => $employee->id]);
    $hr = rnsActor();
    $command = app(SaveRosterDays::class);

    $command->preview(Access::forActor($hr), $employee->id, ['2026-11-18'], 'REST', 'DEFAULT');
    expect(Notification::query()->count())->toBe(0);

    $command->handle(Access::forActor($hr), $employee->id, ['2026-11-18'], 'REST', 'DEFAULT');
    $notice = Notification::query()->where('user_id', $account->id)->sole();
    expect($notice->kind)->toBe('SCHEDULE_CHANGED')
        ->and($notice->title)->toBe('Your schedule for Nov 18, 2026 changed')
        ->and($notice->href)->toBe('/portal?tab=schedule')
        ->and(Notification::query()->where('user_id', $hr->id)->count())->toBe(0);

    // The same again changes nothing and tells nobody.
    $command->handle(Access::forActor($hr), $employee->id, ['2026-11-18'], 'REST', 'DEFAULT');
    expect(Notification::query()->count())->toBe(1);
});

it('shows the employee their own month in My Portal, even with all-data access', function () {
    $mine = rnsEmployee('Mara', 'Santos', rnsPattern('Sunrise', '06:00:00', '14:00:00'));
    rnsEmployee('Olga', 'Diaz', rnsPattern('Moonlight', '22:00:00', '06:00:00'));
    rnsActor(['PORTAL', 'ROSTER'], true, $mine->id);

    // The notice's link opens the tab.
    $this->get('/portal?tab=schedule')->assertOk()->assertSee('data-my-schedule', false);
    Livewire::test(PortalPage::class)->set('tab', 'schedule')->assertSet('tab', 'schedule');
    Livewire::test(MySchedule::class)->assertSee('Sunrise')->assertSee('Usual week')->assertDontSee('Moonlight');

    rnsActor(['PORTAL'], false);
    Livewire::test(MySchedule::class)->assertSee('linked to an employee record yet')->assertDontSee('Sunrise');
});
