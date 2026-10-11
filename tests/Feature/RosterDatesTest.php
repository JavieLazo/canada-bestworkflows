<?php

declare(strict_types=1);

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDate;
use App\Modules\Timekeeping\Application\Commands\SaveRosterShifts;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\Queries\RosterDates;
use App\Modules\Timekeeping\Application\ScheduleImpactPending;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Timekeeping\Infrastructure\SiteReferences;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;

/**
 * One date wins over the weekly roster independently for hours and worksite.
 * A replaced or removed date change is cancelled, never deleted.
 */

// Weekly changes apply from today, so "today" sits before the dates these
// tests change.
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-21T06:00:00+08:00'));
});

function rdateEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'RD-'.Str::random(10), 'first_name' => 'Date', 'last_name' => 'Employee', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
}

/** @param list<string> $modules */
function rdateActor(array $modules = ['ROSTER', 'RECORDS'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Date '.Str::random(10), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function rdatePattern(string $start = '22:00:00', string $end = '06:00:00'): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => 'Date pattern '.Str::random(8), 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end, 'work_days' => [3], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

it('overrides one date and restores each weekly setting independently when DEFAULT is chosen', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $night = rdatePattern();
    $day = rdatePattern('09:00:00', '17:00:00');
    $site = WorkSite::query()->create(['name' => 'Date site', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 150, 'geofence_exempt' => false]);
    app(SaveRosterWeek::class)->handle($actor, $employee->id, [1 => $night->id], [1 => 'WFH']);
    $command = app(SaveRosterDate::class);
    $command->handle($actor, $employee->id, '2026-09-28', $day->id, $site->id);
    $roster = app(ShiftRoster::class);
    expect($roster->forDate($employee->id, '2026-09-28')?->startTime)->toBe('09:00:00')
        ->and($roster->locationForDate($employee->id, '2026-09-28')?->siteId)->toBe($site->id)
        ->and($roster->forDate($employee->id, '2026-10-05')?->startTime)->toBe('22:00:00');
    $command->handle($actor, $employee->id, '2026-09-28', $day->id, 'DEFAULT');
    expect($roster->locationForDate($employee->id, '2026-09-28')?->kind)->toBe('WFH')
        ->and($roster->forDate($employee->id, '2026-09-28')?->startTime)->toBe('09:00:00');
    $command->handle($actor, $employee->id, '2026-09-28', 'DEFAULT', $site->id);
    expect($roster->forDate($employee->id, '2026-09-28')?->startTime)->toBe('22:00:00')
        ->and($roster->locationForDate($employee->id, '2026-09-28')?->siteId)->toBe($site->id);
    $command->handle($actor, $employee->id, '2026-09-28', 'DEFAULT', 'DEFAULT');
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->count())->toBe(0)
        ->and(RosterDayOverride::query()->count())->toBe(3)
        ->and($roster->forDate($employee->id, '2026-09-28')?->startTime)->toBe('22:00:00')
        ->and($roster->locationForDate($employee->id, '2026-09-28')?->kind)->toBe('WFH');
});

it('makes a chosen date working regardless of pattern weekdays and supports one-date rest', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $pattern = rdatePattern();
    $command = app(SaveRosterDate::class);
    $command->handle($actor, $employee->id, '2026-09-28', $pattern->id, 'WFH');
    $facts = app(RosterDates::class)->effective($actor, $employee->id, '2026-09-28');
    expect($facts)->not->toBeNull();
    assert($facts !== null);
    expect($facts['is_work_day'])->toBeTrue()->and($facts['start_time'])->toBe('22:00:00')->and($facts['location_label'])->toBe('Work from home');
    $command->handle($actor, $employee->id, '2026-09-28', 'REST', 'DEFAULT');
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->sole()->shift_type_id)->toBeNull()
        ->and(RosterDayOverride::query()->where('status', 'ACTIVE')->sole()->shift_data['shift_type_id'] ?? null)->toBeNull();
    expect(app(RosterDates::class)->effective($actor, $employee->id, '2026-09-28')['is_work_day'] ?? null)->toBeFalse()
        ->and(app(RosterDates::class)->effective($actor, $employee->id, '2026-09-29')['is_work_day'] ?? null)->toBeTrue();
});

it('writes no row or audit for unchanged date settings and an absent DEFAULT reset', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $command = app(SaveRosterDate::class);
    $command->handle($actor, $employee->id, '2026-09-28', 'DEFAULT', 'DEFAULT');
    expect(AuditLog::query()->count())->toBe(0);
    $command->handle($actor, $employee->id, '2026-09-28', 'REST', 'WFH');
    $command->handle($actor, $employee->id, '2026-09-28', 'REST', 'WFH');
    expect(RosterDayOverride::query()->count())->toBe(1)->and(AuditLog::query()->where('action', 'ROSTER_DATE_SET')->count())->toBe(1);
});

it('refuses date writes without roster all-data access', function (string $module, bool $allData) {
    $employee = rdateEmployee();
    $actor = rdateActor([$module], $allData);
    expect(fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-09-28', 'REST', 'WFH'))->toThrow(RuntimeException::class);
    expect(RosterDayOverride::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
})->with([['ROSTER', false], ['RECORDS', true]]);

it('validates date choices before leaving a row or audit', function (string $date, string $shift, string $location) {
    $employee = rdateEmployee();
    $actor = rdateActor();
    expect(fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, $date, $shift, $location))->toThrow(RuntimeException::class);
    expect(RosterDayOverride::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
})->with([
    ['2026-02-30', 'REST', 'WFH'], ['tomorrow', 'REST', 'WFH'], ['2026-09-28', '', 'WFH'],
    ['2026-09-28', 'missing', 'WFH'], ['2026-09-28', 'KEEP', 'WFH'], ['2026-09-28', 'REST', 'missing'],
]);

it('keeps deleted pattern snapshots when only the date location changes', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $pattern = rdatePattern();
    $command = app(SaveRosterDate::class);
    $command->handle($actor, $employee->id, '2026-09-28', $pattern->id, 'DEFAULT');
    $pattern->delete();
    $command->handle($actor, $employee->id, '2026-09-28', 'KEEP', 'WFH');
    expect(RosterDayOverride::query()->where('status', 'ACTIVE')->sole()->shift_type_id)->toBeNull()
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-09-28')?->startTime)->toBe('22:00:00');
});

it('scopes date rows and upcoming counts before employee and date filters', function () {
    $own = rdateEmployee();
    $other = rdateEmployee();
    $manager = rdateActor();
    $command = app(SaveRosterDate::class);
    $command->handle($manager, $own->id, '2026-09-28', 'REST', 'DEFAULT');
    $command->handle($manager, $own->id, '2026-10-01', 'REST', 'DEFAULT');
    $command->handle($manager, $other->id, '2026-10-01', 'REST', 'DEFAULT');
    $query = app(RosterDates::class);
    expect($query->upcomingCounts($manager, [$own->id, $other->id], '2026-09-29'))->toBe([$own->id => 1, $other->id => 1]);
    $self = rdateActor(['ROSTER'], false, $own->id);
    expect($query->upcomingCounts($self, [$own->id, $other->id], '2026-09-29'))->toBe([$own->id => 1])
        ->and($query->forEmployee($self, $other->id))->toBe([])
        ->and($query->forDate($self, $own->id, '2026-10-01')?->is_rest_day)->toBeTrue();
    $unlinked = rdateActor(['ROSTER'], false);
    expect($query->forEmployee($unlinked, $own->id))->toBe([]);
});

it('loads only date shifts in the requested batch range and ignores location-only rows for hours', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $command = app(SaveRosterDate::class);
    $command->handle($actor, $employee->id, '2026-09-28', rdatePattern()->id, 'DEFAULT');
    $command->handle($actor, $employee->id, '2026-09-29', 'DEFAULT', 'WFH');
    $command->handle($actor, $employee->id, '2026-10-01', 'REST', 'DEFAULT');
    $schedule = app(ShiftRoster::class)->scheduleForEmployee($employee->id, '2026-09-01', '2026-09-30');
    expect($schedule)->not->toBeNull();
    assert($schedule !== null);
    expect(array_keys($schedule->dateShifts))->toBe(['2026-09-28'])
        ->and($schedule->forDate('2026-09-29')->startTime)->toBe('08:00:00');
});

it('fans pattern edits out to date snapshots and counts date-only employees in settings', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $pattern = rdatePattern();
    $command = app(SaveRosterDate::class);
    $command->handle($actor, $employee->id, '2026-09-28', $pattern->id, 'DEFAULT');
    $command->handle($actor, $employee->id, '2026-09-29', $pattern->id, 'DEFAULT');
    expect(app(ReferenceData::class)->shiftTypeUsage($actor)[$pattern->id])->toBe(1);
    $draft = new ShiftTypeDraft('Date edit', 'FIXED', [3], '21:00', '05:00', '0', '8', false, '00:00', '01:00', '0', false);
    app(SaveShiftType::class)->handle($actor, $pattern->id, $draft);
    foreach (RosterDayOverride::query()->get() as $row) {
        expect($row->shift_data['start_time'] ?? null)->toBe('21:00:00')
            ->and($row->shift_data['work_days'] ?? null)->toBe([(int) $row->date->format('w')]);
    }
    expect(AuditLog::query()->where('action', 'SHIFT_TYPE_UPDATED')->sole()->details)->toContain('1 rostered employee');
});

it('refuses deletion of a site still named by a date-specific schedule', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $site = WorkSite::query()->create(['name' => 'Dated onsite', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 150, 'geofence_exempt' => false]);
    app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-09-28', 'DEFAULT', $site->id);
    expect(app(SiteReferences::class)->refusal($site->id))->toBe('This work site is in employee schedule history and must remain. Make it inactive instead.');
});

it('refuses the final rest exception that would leave a whole month with no working day', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    foreach (CalendarDays::each('2026-09-01', '2026-09-29') as $date) {
        if (in_array(CalendarDays::dayOfWeek($date), [1, 2, 3, 4, 5], true)) {
            RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => $date, 'shift_name' => 'Rest day', 'is_rest_day' => true, 'shift_data' => DomainShift::restDay()->toArray()]);
        }
    }
    $before = RosterDayOverride::query()->count();
    expect(fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-09-30', 'REST', 'DEFAULT'))
        ->toThrow(RuntimeException::class, 'at least one working day in 2026-09');
    expect(RosterDayOverride::query()->count())->toBe($before)->and(AuditLog::query()->count())->toBe(0);
});

it('refuses recurring changes whose remaining weekday is rested on every date in a month', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    foreach (['2026-09-02', '2026-09-09', '2026-09-16', '2026-09-23', '2026-09-30'] as $date) {
        RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => $date, 'shift_name' => 'Rest day', 'is_rest_day' => true, 'shift_data' => DomainShift::restDay()->toArray()]);
    }
    expect(fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => 'REST', 2 => 'REST', 4 => 'REST', 5 => 'REST']))
        ->toThrow(RuntimeException::class, 'at least one working day in 2026-09');
    expect(fn () => app(AssignShift::class)->handle($actor, $employee->id, rdatePattern()->id))
        ->toThrow(RuntimeException::class, 'at least one working day in 2026-09');
    expect(AuditLog::query()->count())->toBe(0);
});

it('keeps a rest day when a shift is picked for it, and schedules work on it only by the explicit choice', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $pattern = rdatePattern('09:00:00', '18:00:00');
    $punch = AttendanceLog::query()->create(['employee_id' => $employee->id, 'log_type' => 'TIME_IN', 'source' => 'MANUAL', 'logged_at' => ManilaClock::instant('2026-09-26T09:05:00')->utc()]);
    $command = app(SaveRosterDate::class);
    $roster = app(RosterDates::class);

    // Saturday 2026-09-26 rests on the default week: an ordinary shift change is refused.
    expect(fn () => $command->handle($actor, $employee->id, '2026-09-26', $pattern->id, 'DEFAULT'))
        ->toThrow(RuntimeException::class, 'Sep 26, 2026 is a rest day on Date Employee\'s schedule, and a shift change keeps rest days. To schedule work on it, choose "Schedule work on this rest day".');
    expect(RosterDayOverride::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and($roster->effective($actor, $employee->id, '2026-09-26')['usually_works'] ?? null)->toBeFalse();

    // A place alone, or an explicit rest, never schedules work.
    $command->handle($actor, $employee->id, '2026-09-26', 'DEFAULT', 'WFH');
    expect($roster->effective($actor, $employee->id, '2026-09-26')['is_work_day'] ?? null)->toBeFalse();

    // The explicit choice does.
    $command->handle($actor, $employee->id, '2026-09-26', $pattern->id, 'WFH', workOnRestDay: true);
    $saved = RosterDayOverride::query()->where('status', 'ACTIVE')->sole();
    expect($saved->include_rest_days)->toBeTrue()
        ->and($roster->effective($actor, $employee->id, '2026-09-26')['is_work_day'] ?? null)->toBeTrue()
        ->and($roster->effective($actor, $employee->id, '2026-09-26')['start_time'] ?? null)->toBe('09:00:00')
        ->and($roster->effective($actor, $employee->id, '2026-09-26')['usually_works'] ?? null)->toBeFalse()
        // The punch recorded on that rest day is as it was.
        ->and(AttendanceLog::query()->sole()->is($punch))->toBeTrue();
});

it('keeps a later rest day over a one-date shift saved while that date was a working day', function () {
    $employee = rdateEmployee();
    $actor = rdateActor();
    $pattern = rdatePattern('09:00:00', '18:00:00');
    // Monday 2026-09-28 works on the default week.
    app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-09-28', $pattern->id, 'DEFAULT');
    expect(RosterDayOverride::query()->sole()->include_rest_days)->toBeFalse()
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-09-28')?->startTime)->toBe('09:00:00');

    // Mondays become rest days on the weekly schedule: the date rests too.
    // That reaches a date inside a temporary schedule, so it is shown first
    // (D6) and saved only with what was shown.
    $impact = app(SaveRosterShifts::class)->preview($actor, $employee->id, [1 => 'REST']);
    expect($impact->restDays)->toBe([['employee_id' => $employee->id, 'date' => '2026-09-28', 'works' => false]])
        ->and(fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => 'REST']))->toThrow(ScheduleImpactPending::class)
        ->and(fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => 'REST'], confirmed: 'not-what-was-shown'))->toThrow(ScheduleImpactPending::class);
    expect(RosterShift::query()->count())->toBe(0);
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [1 => 'REST'], confirmed: $impact->token());
    $facts = app(RosterDates::class)->effective($actor, $employee->id, '2026-09-28');
    expect($facts['is_work_day'] ?? null)->toBeFalse()
        ->and($facts['usually_works'] ?? null)->toBeFalse();
});
