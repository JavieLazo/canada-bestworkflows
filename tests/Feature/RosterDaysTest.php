<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDays;
use App\Modules\Timekeeping\Application\Commands\SaveRosterShifts;
use App\Modules\Timekeeping\Application\Queries\RosterCalendar;
use App\Modules\Timekeeping\Application\Queries\RosterLocations;
use App\Modules\Timekeeping\Application\ScheduleChangeRefused;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Contracts\Views\RosterDayView;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;

/**
 * The Shift Roster's month changes picked dates, not periods
 * (`SaveRosterDays`): dates that need not follow one another become one
 * temporary schedule per run of consecutive dates, a shift picked for a
 * rest date works it (the date was picked by name), only the picked dates
 * of a saved temporary schedule are replaced — the rest of it continues —
 * "back to the usual schedule" takes them off again, an unchanged date is
 * left alone, and all of it runs under the roster's gate and safeguards.
 *
 * Its calendar (`RosterCalendar`) names each date's shift from the same
 * composition the DTR uses, flags a shift that starts less than eight hours
 * after the one before, says what the date's own temporary schedule set,
 * and reads the roster's scope for the days and Leave Management's scope
 * for approved leave on them.
 */

/** @param list<string> $modules */
function rdaysActor(array $modules = ['ROSTER'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Days '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function rdaysPattern(string $name, string $start, string $end): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => $name, 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

/** An employee on a baseline Monday–Friday default shift. */
function rdaysEmployee(?ShiftType $default, string $first = 'Rina'): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'RD-'.Str::random(8), 'first_name' => $first, 'last_name' => 'Days',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
    if ($default !== null) {
        Shift::query()->create(ShiftPolicy::attributesFromPattern($default) + ['employee_id' => $employee->id]);
    }

    return $employee;
}

/**
 * Each date as the calendar reads it: "Day 8:00 AM–5:00 PM", "rest", "none".
 *
 * @return array<string, string>
 */
function rdaysRead(Access $actor, EmployeeProfile $employee, string $from, string $to): array
{
    return array_map(
        static fn (RosterDayView $day): string => ! $day->rostered ? 'none' : ($day->works ? $day->label.' '.$day->hours : 'rest'),
        app(RosterCalendar::class)->forEmployees($actor, [$employee->id], $from, $to)[$employee->id] ?? [],
    );
}

beforeEach(function () {
    // Monday 2026-11-16, before any shift here starts.
    $this->travelTo(new DateTimeImmutable('2026-11-16T05:00:00+08:00'));
});

/*
|--------------------------------------------------------------------------
| SaveRosterDays
|--------------------------------------------------------------------------
*/

it('saves dates that do not follow one another as one temporary schedule per run, working the rest days picked', function () {
    $actor = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $am = rdaysPattern('AM', '06:00:00', '14:00:00');

    $message = app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-22', '2026-11-18', '2026-11-20', '2026-11-21', '2026-11-18'], $am->id, 'DEFAULT');

    expect($message)->toBe('Saved: 4 days for Rina Days. The eDTR and payroll use it on those dates.');
    $rows = RosterDayOverride::query()->where('employee_id', $employee->id)->orderBy('date')->get();
    expect($rows)->toHaveCount(2)
        ->and([$rows[0]->date->format('Y-m-d'), $rows[0]->end_date])->toBe(['2026-11-18', null])
        ->and([$rows[1]->date->format('Y-m-d'), $rows[1]->end_date?->format('Y-m-d')])->toBe(['2026-11-20', '2026-11-22'])
        ->and($rows[0]->include_rest_days)->toBeFalse()
        // Saturday and Sunday rest on the weekly schedule; picking them works them.
        ->and($rows[1]->include_rest_days)->toBeTrue();
    expect(rdaysRead($actor, $employee, '2026-11-18', '2026-11-23'))->toBe([
        '2026-11-18' => 'AM 6:00 AM–2:00 PM',
        '2026-11-19' => 'Day 8:00 AM–5:00 PM',
        '2026-11-20' => 'AM 6:00 AM–2:00 PM',
        '2026-11-21' => 'AM 6:00 AM–2:00 PM',
        '2026-11-22' => 'AM 6:00 AM–2:00 PM',
        '2026-11-23' => 'Day 8:00 AM–5:00 PM',
    ]);
    $audit = AuditLog::query()->where('entity', 'roster_day_overrides')->sole();
    expect($audit->action)->toBe('ROSTER_PERIOD_SET')
        ->and($audit->details)->toBe('Rina Days · 2026-11-18, 2026-11-20 to 2026-11-22 · AM · weekly location');
});

it('replaces only the picked dates of a saved temporary schedule, which keeps its others', function () {
    $actor = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $night = rdaysPattern('Night', '22:00:00', '06:00:00');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-11-17', '2026-11-20', $night->id, 'DEFAULT');

    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18'], 'REST', 'DEFAULT');

    expect(rdaysRead($actor, $employee, '2026-11-17', '2026-11-20'))->toBe([
        '2026-11-17' => 'Night 10:00 PM–6:00 AM',
        '2026-11-18' => 'rest',
        '2026-11-19' => 'Night 10:00 PM–6:00 AM',
        '2026-11-20' => 'Night 10:00 PM–6:00 AM',
    ]);
    // Cancelled with who and when, never deleted; its tail continues as a revision.
    $original = RosterDayOverride::query()->where('employee_id', $employee->id)->where('date', '2026-11-17')->sole();
    expect($original->status)->toBe(RosterDayOverride::STATUS_CANCELLED)
        ->and($original->cut_after?->format('Y-m-d'))->toBe('2026-11-17')
        ->and($original->cancelled_by)->toBe($actor->userId === null ? null : (int) $actor->userId)
        ->and(RosterDayOverride::query()->where('revision_of', $original->id)->where('date', '2026-11-19')->exists())->toBeTrue();
});

it('takes the picked dates back to the usual schedule and leaves the other dates as they were', function () {
    $actor = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $am = rdaysPattern('AM', '06:00:00', '14:00:00');
    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-20', '2026-11-21', '2026-11-22'], $am->id, 'WFH');

    $message = app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-21'], 'DEFAULT', 'DEFAULT');

    expect($message)->toBe('Saved: 1 day for Rina Days. The eDTR and payroll use it on that date.');
    expect(rdaysRead($actor, $employee, '2026-11-20', '2026-11-22'))->toBe([
        '2026-11-20' => 'AM 6:00 AM–2:00 PM',
        '2026-11-21' => 'rest',
        '2026-11-22' => 'AM 6:00 AM–2:00 PM',
    ]);
    expect(AuditLog::query()->where('entity', 'roster_day_overrides')->latest('id')->first()?->details)
        ->toBe('Rina Days · 2026-11-21 · follows weekly schedule');
    // Nothing to take off: nothing written, nothing audited.
    $audits = AuditLog::query()->count();
    expect(app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-19'], 'DEFAULT', 'DEFAULT'))->toBe('No changes to the schedule for that date.')
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('leaves a date whose temporary schedule is already exactly this as it is', function () {
    $actor = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $am = rdaysPattern('AM', '06:00:00', '14:00:00');
    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18'], $am->id, 'WFH');
    $rows = RosterDayOverride::query()->count();
    $audits = AuditLog::query()->count();

    expect(app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18'], $am->id, 'WFH'))->toBe('No changes to the schedule for that date.')
        ->and(RosterDayOverride::query()->count())->toBe($rows)
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('stores custom hours on picked dates with no pattern behind them', function () {
    $actor = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));

    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-19'], ['start' => '07:00', 'end' => '15:00', 'break_start' => '', 'break_end' => '', 'grace' => '15'], 'DEFAULT');

    $row = RosterDayOverride::query()->where('employee_id', $employee->id)->sole();
    expect($row->shift_type_id)->toBeNull()->and($row->shift_name)->toBe('Custom hours');
    $day = app(RosterCalendar::class)->forEmployees($actor, [$employee->id], '2026-11-19', '2026-11-19')[$employee->id]['2026-11-19'];
    expect($day->label)->toBe('Custom hours')
        ->and($day->custom)->toBeTrue()
        ->and($day->hours)->toBe('7:00 AM–3:00 PM')
        ->and($day->shift_choice)->toBe('CUSTOM')
        ->and($day->custom_hours)->toMatchArray(['start' => '07:00', 'end' => '15:00']);
});

it('refuses without all-data ROSTER, for a past date, and with nothing picked, writing and auditing nothing', function () {
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $am = rdaysPattern('AM', '06:00:00', '14:00:00');
    $days = app(SaveRosterDays::class);

    $viewer = rdaysActor(['ROSTER'], allData: false, employeeId: $employee->id);
    expect(fn () => $days->handle($viewer, $employee->id, ['2026-11-18'], $am->id, 'DEFAULT'))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');

    $actor = rdaysActor();
    expect(fn () => $days->handle($actor, $employee->id, ['2026-11-15', '2026-11-18'], $am->id, 'DEFAULT'))->toThrow(ScheduleChangeRefused::class)
        ->and(fn () => $days->handle($actor, $employee->id, [], $am->id, 'DEFAULT'))->toThrow(RuntimeException::class, 'Pick at least one date.')
        ->and(fn () => $days->handle($actor, $employee->id, ['18 Nov'], $am->id, 'DEFAULT'))->toThrow(RuntimeException::class, 'Choose a valid calendar date.')
        // KEEP repeats a saved copy, and these dates have none.
        ->and(fn () => $days->handle($actor, $employee->id, ['2026-11-18'], 'KEEP', 'DEFAULT'))->toThrow(RuntimeException::class, 'That saved shift is no longer available. Pick a shift again.');

    expect(RosterDayOverride::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('entity', 'roster_day_overrides')->count())->toBe(0);
});

it('refuses today once today’s shift has started, naming the earliest date', function () {
    $actor = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $am = rdaysPattern('AM', '06:00:00', '14:00:00');
    $this->travelTo(new DateTimeImmutable('2026-11-16T10:00:00+08:00'));

    expect(fn () => app(SaveRosterDays::class)->handle($actor, $employee->id, [ManilaClock::todayKey(), '2026-11-18'], $am->id, 'DEFAULT'))
        ->toThrow(ScheduleChangeRefused::class, 'The earliest it can start is Nov 17, 2026.');
    expect(RosterDayOverride::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| RosterCalendar
|--------------------------------------------------------------------------
*/

it('names each date by the shift that applies, what decided it and its weekly schedule beneath', function () {
    $actor = rdaysActor();
    $day = rdaysPattern('Day', '08:00:00', '17:00:00');
    $employee = rdaysEmployee($day);
    $nobody = rdaysEmployee(null, 'Noel');
    $am = rdaysPattern('AM', '06:00:00', '14:00:00');
    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18'], $am->id, 'WFH');

    $calendar = app(RosterCalendar::class)->forEmployees($actor, [$employee->id, $nobody->id], '2026-11-17', '2026-11-22');
    $tuesday = $calendar[$employee->id]['2026-11-17'];
    $wednesday = $calendar[$employee->id]['2026-11-18'];
    $saturday = $calendar[$employee->id]['2026-11-21'];

    expect([$tuesday->label, $tuesday->source, $tuesday->shift_choice, $tuesday->location_choice, $tuesday->place_kind])->toBe(['Day', 'DEFAULT', 'DEFAULT', 'DEFAULT', null])
        ->and([$wednesday->label, $wednesday->source, $wednesday->shift_choice, $wednesday->location_choice, $wednesday->place_kind])->toBe(['AM', 'TEMPORARY', $am->id, 'WFH', 'WFH'])
        ->and($wednesday->usual)->toBe('Day 8:00 AM–5:00 PM')
        ->and([$wednesday->starts, $wednesday->ends])->toBe(['6:00 AM', '2:00 PM'])
        ->and([$saturday->label, $saturday->works, $saturday->rostered, $saturday->hours, $saturday->usual])->toBe(['Rest day', false, true, null, 'Rest day']);
    expect(array_unique(array_map(static fn (RosterDayView $d): string => $d->label, $calendar[$nobody->id])))->toBe(['2026-11-17' => 'No shift'])
        ->and($calendar[$nobody->id]['2026-11-17']->rostered)->toBeFalse();
});

it('flags a shift that starts less than eight hours after the one before, and nothing across a rest day or leave', function () {
    $actor = rdaysActor(['ROSTER', 'LEAVES']);
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $night = rdaysPattern('Night', '22:00:00', '06:00:00');
    $am = rdaysPattern('AM', '06:00:00', '14:00:00');
    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-17', '2026-11-19'], $night->id, 'DEFAULT');
    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18', '2026-11-20'], $am->id, 'DEFAULT');
    LeaveTravelRequest::query()->create(['code' => 'RD-LV', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'status' => 'APPROVED', 'is_paid' => true, 'reason' => 'Private']);

    $days = app(RosterCalendar::class)->forEmployees($actor, [$employee->id], '2026-11-16', '2026-11-21')[$employee->id];

    // Night Tue ends 06:00 Wed, AM Wed starts 06:00: no rest at all.
    expect($days['2026-11-18']->short_rest_minutes)->toBe(0)
        // AM Wed ends 14:00; the Thursday night starts 22:00: eight hours.
        ->and($days['2026-11-19']->short_rest_minutes)->toBeNull()
        // Thursday is on leave, so Friday's AM follows no shift.
        ->and($days['2026-11-20']->short_rest_minutes)->toBeNull()
        ->and($days['2026-11-17']->short_rest_minutes)->toBeNull();
});

it('reads the roster’s scope for the days: everyone for all-data, your own row otherwise, nobody unlinked', function () {
    $own = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'), 'Own');
    $other = rdaysEmployee(rdaysPattern('Late', '10:00:00', '19:00:00'), 'Other');
    $calendar = app(RosterCalendar::class);

    expect(array_keys($calendar->forEmployees(rdaysActor(), [$own->id, $other->id], '2026-11-16', '2026-11-16')))->toBe([$own->id, $other->id])
        ->and(array_keys($calendar->forEmployees(rdaysActor(['ROSTER'], false, $own->id), [$own->id, $other->id], '2026-11-16', '2026-11-16')))->toBe([$own->id])
        ->and($calendar->forEmployees(rdaysActor(['ROSTER'], false), [$own->id, $other->id], '2026-11-16', '2026-11-16'))->toBe([])
        ->and($calendar->forEmployees(rdaysActor(['LEAVES'], true), [$own->id, $other->id], '2026-11-16', '2026-11-16'))->toBe([]);
});

it('shows approved leave by type only to a viewer of that employee’s leave, less its cancelled dates', function () {
    LeaveType::query()->create(['code' => 'RD-VL', 'name' => 'Vacation Leave']);
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $approved = LeaveTravelRequest::query()->create(['code' => 'RD-LV1', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-17', 'end_date' => '2026-11-19', 'status' => 'APPROVED', 'is_paid' => true, 'reason' => 'Private reason']);
    LeaveTravelRequest::query()->create(['code' => 'RD-LV2', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'status' => 'PENDING', 'is_paid' => null, 'reason' => 'Private reason']);
    LeaveTravelRequest::query()->create(['code' => 'RD-OB', 'employee_id' => $employee->id, 'request_type' => 'BUSINESS_TRAVEL', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-23', 'end_date' => '2026-11-23', 'status' => 'APPROVED', 'is_paid' => null, 'reason' => 'Private reason']);
    $cancel = new LeaveRequestCancellation;
    $cancel->forceFill(['leave_travel_request_id' => $approved->id, 'date' => '2026-11-18', 'cancelled_by' => User::factory()->create()->id, 'cancelled_at' => ManilaClock::instant('2026-11-16T05:00:00+08:00')->utc(), 'reason' => 'Back early'])->save();

    $leaveOf = static fn (Access $actor): array => array_map(
        static fn (RosterDayView $day): ?string => $day->leave,
        app(RosterCalendar::class)->forEmployees($actor, [$employee->id], '2026-11-17', '2026-11-23')[$employee->id],
    );

    expect($leaveOf(rdaysActor(['ROSTER', 'LEAVES'])))->toBe([
        '2026-11-17' => 'Vacation Leave', '2026-11-18' => null, '2026-11-19' => 'Vacation Leave',
        '2026-11-20' => null, '2026-11-21' => null, '2026-11-22' => null, '2026-11-23' => 'Business Travel / OB',
    ]);
    expect(app(RosterCalendar::class)->forEmployees(rdaysActor(['ROSTER', 'LEAVES']), [$employee->id], '2026-11-23', '2026-11-23')[$employee->id]['2026-11-23']->official_business)->toBeTrue();
    // A ROSTER manager who may not see leave sees the schedule of those dates and no leave.
    expect(array_filter($leaveOf(rdaysActor(['ROSTER']))))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Review findings pinned
|--------------------------------------------------------------------------
*/

it('keeps an inactive site only on picked dates that already have it', function () {
    $actor = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $site = WorkSite::query()->create(['name' => 'Old Warehouse', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 150, 'geofence_exempt' => false]);
    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18'], 'DEFAULT', $site->id);
    $site->is_active = false;
    $site->save();
    $rows = RosterDayOverride::query()->count();

    expect(fn () => app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18', '2026-11-19', '2026-11-20'], 'REST', $site->id))
        ->toThrow(RuntimeException::class, 'That work site is inactive');
    expect(RosterDayOverride::query()->count())->toBe($rows);

    // The date that already has it may keep it while its shift changes.
    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18'], 'REST', $site->id);
    $day = app(RosterCalendar::class)->forEmployees($actor, [$employee->id], '2026-11-18', '2026-11-18')[$employee->id]['2026-11-18'];
    expect([$day->works, $day->place_site_id])->toBe([false, $site->id]);
});

it('keeps a deleted pattern’s copy exactly as it is, flexible hours included, and only where one saved schedule decides every date', function () {
    $actor = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $flexi = ShiftType::query()->create(['code' => Str::random(10), 'name' => 'Old Flexi', 'mode' => 'FLEXIBLE', 'required_hours' => 6, 'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18'], $flexi->id, 'DEFAULT');
    // The pattern deleted: the copy stays with no pattern behind it.
    RosterDayOverride::query()->where('employee_id', $employee->id)->update(['shift_type_id' => null]);

    $day = app(RosterCalendar::class)->forEmployees($actor, [$employee->id], '2026-11-18', '2026-11-18')[$employee->id]['2026-11-18'];
    expect([$day->shift_choice, $day->hours, $day->custom_hours])->toBe(['KEEP', 'Flexible · 6 h per day', null]);

    app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18'], 'KEEP', 'WFH');
    $row = RosterDayOverride::query()->where('employee_id', $employee->id)->where('status', '!=', RosterDayOverride::STATUS_CANCELLED)->sole();
    expect($row->shift_data['mode'] ?? null)->toBe('FLEXIBLE')
        ->and($row->shift_name)->toBe('Old Flexi')
        ->and($row->location_kind)->toBe('WFH');

    expect(fn () => app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-18', '2026-11-19'], 'KEEP', 'DEFAULT'))
        ->toThrow(RuntimeException::class, 'That saved shift is no longer available. Pick a shift again.');
});

it('has no default shift: a weekday with no hours of its own has no shift and no Time In, even beside weekdays that have hours', function () {
    $actor = rdaysActor(['ROSTER', 'EDTR']);
    $weekdaysOnly = rdaysEmployee(null, 'Wendy');
    app(SaveRosterShifts::class)->handle($actor, $weekdaysOnly->id, [1 => rdaysPattern('AM', '06:00:00', '14:00:00')->id, 6 => 'REST']);

    $days = app(RosterCalendar::class)->forEmployees($actor, [$weekdaysOnly->id], '2026-11-16', '2026-11-22')[$weekdaysOnly->id];
    $read = static fn (RosterDayView $d): string => ! $d->rostered ? 'none' : ($d->works ? (string) $d->hours : 'rest');

    // Monday has its own AM and Saturday its own Rest day; the other days
    // have nothing, never the 8:00–5:00 the engine stands in with.
    expect(array_map($read, $days))->toBe([
        '2026-11-16' => '6:00 AM–2:00 PM', '2026-11-17' => 'none', '2026-11-18' => 'none', '2026-11-19' => 'none',
        '2026-11-20' => 'none', '2026-11-21' => 'rest', '2026-11-22' => 'none',
    ]);
    // The punch station reads the same rule: no Time In on Tuesday.
    $roster = app(ShiftRoster::class);
    $schedule = $roster->scheduleForEmployee($weekdaysOnly->id, '2026-11-15', '2026-11-18');
    expect($roster->rosteredShift($weekdaysOnly->id, '2026-11-16', $schedule))->not->toBeNull()
        ->and($roster->rosteredShift($weekdaysOnly->id, '2026-11-17', $schedule))->toBeNull()
        ->and(app(RosterLocations::class)->forStation($actor, $weekdaysOnly->id)?->has_shift)->toBeTrue();
});

it('starts a newly hired employee on no shift', function () {
    Position::query()->create(['code' => 'POS-RD', 'name' => 'Staff Nurse']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-RD', 'name' => 'Probationary']);
    $site = WorkSite::query()->create(['name' => 'Main Hospital', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => false]);
    $actor = rdaysActor(['EMPLOYEES', 'ROSTER', 'EDTR']);

    $hire = app(CreateEmployee::class)->handle($actor, [
        'employee_id_no' => '2026-RD', 'first_name' => 'Nina', 'last_name' => 'Newhire', 'phone' => '0917 123 4567',
        'email' => 'nina.newhire@company.com', 'birth_date' => '1995-05-01', 'res_street' => '1 Rizal St.', 'sex' => 'Female',
        'civil_status' => 'Single', 'emergency_name' => 'Nora Newhire', 'emergency_relation' => 'Mother', 'emergency_phone' => '0917 765 4321',
        'position_id' => (string) Position::query()->where('name', 'Staff Nurse')->value('id'), 'employment_status' => 'Probationary',
        'work_site_id' => $site->id, 'date_hired' => '2026-11-16', 'monthly_rate' => '25000',
    ] + EmployeeFields::blankForm());
    $id = (string) $hire->id;

    expect(Shift::query()->where('employee_id', $id)->count())->toBe(0);
    $week = app(RosterCalendar::class)->forEmployees($actor, [$id], '2026-11-16', '2026-11-22')[$id];
    expect(array_values(array_unique(array_map(static fn (RosterDayView $d): string => $d->label, $week))))->toBe(['No shift'])
        ->and(app(RosterLocations::class)->forStation($actor, $id)?->has_shift)->toBeFalse();
});

it('cuts a cancelled temporary schedule shorter without rewriting who cancelled it and when', function () {
    $first = rdaysActor();
    $employee = rdaysEmployee(rdaysPattern('Day', '08:00:00', '17:00:00'));
    $night = rdaysPattern('Night', '22:00:00', '06:00:00');
    app(SaveAssignment::class)->handle($first, $employee->id, '2026-11-17', '2026-11-28', $night->id, 'DEFAULT');
    app(SaveRosterDays::class)->handle($first, $employee->id, ['2026-11-25'], 'REST', 'DEFAULT');
    $original = RosterDayOverride::query()->where('employee_id', $employee->id)->where('date', '2026-11-17')->sole();
    $stamp = [$original->cancelled_by, $original->cancelled_at?->format('Y-m-d H:i:s.v')];
    expect($original->cut_after?->format('Y-m-d'))->toBe('2026-11-24');

    $this->travelTo(new DateTimeImmutable('2026-11-16T05:30:00+08:00'));
    $second = rdaysActor();
    app(SaveRosterDays::class)->handle($second, $employee->id, ['2026-11-20'], 'REST', 'DEFAULT');

    $original->refresh();
    expect($original->cut_after?->format('Y-m-d'))->toBe('2026-11-19')
        ->and([$original->cancelled_by, $original->cancelled_at?->format('Y-m-d H:i:s.v')])->toBe($stamp)
        ->and($original->cancelled_by)->not->toBe($second->userId === null ? null : (int) $second->userId);
    expect(rdaysRead($second, $employee, '2026-11-19', '2026-11-26'))->toBe([
        // The weekend rests: the From–To schedule keeps the weekly rest days.
        '2026-11-19' => 'Night 10:00 PM–6:00 AM', '2026-11-20' => 'rest', '2026-11-21' => 'rest', '2026-11-22' => 'rest',
        '2026-11-23' => 'Night 10:00 PM–6:00 AM', '2026-11-24' => 'Night 10:00 PM–6:00 AM', '2026-11-25' => 'rest', '2026-11-26' => 'Night 10:00 PM–6:00 AM',
    ]);
});

it('leaves a picked date that already reads exactly so alone, inside a longer schedule or on the weekly one', function () {
    $actor = rdaysActor();
    $day = rdaysPattern('Day', '08:00:00', '17:00:00');
    $employee = rdaysEmployee($day);
    $night = rdaysPattern('Night', '22:00:00', '06:00:00');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-11-17', '2026-11-28', $night->id, 'DEFAULT');
    $rows = RosterDayOverride::query()->count();
    $audits = AuditLog::query()->count();

    expect(app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-20'], $night->id, 'DEFAULT'))->toBe('No changes to the schedule for that date.')
        // The weekly schedule already gives Nov 30 the Day shift.
        ->and(app(SaveRosterDays::class)->handle($actor, $employee->id, ['2026-11-30'], $day->id, 'DEFAULT'))->toBe('No changes to the schedule for that date.')
        ->and(RosterDayOverride::query()->count())->toBe($rows)
        ->and(RosterDayOverride::query()->where('status', RosterDayOverride::STATUS_CANCELLED)->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audits);
});
