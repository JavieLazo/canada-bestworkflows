<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift as DomainShift;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Application\Commands\CorrectPunches;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Application\Queries\RosterLocations;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Weekday hours and places agree through live punching, DTR inputs and correction ownership. */

/** @return array{Access, EmployeeProfile, WorkSite} */
function wscSetup(string $dayStart = '08:00:00'): array
{
    $site = WorkSite::query()->create([
        'name' => 'Weekly office', 'latitude' => 14.6, 'longitude' => 121.0,
        'radius_meters' => 150, 'geofence_exempt' => true,
    ]);
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'WSC-'.Str::random(8), 'first_name' => 'Weekly', 'last_name' => 'Operator',
        'position' => 'Operator', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01',
        'is_active' => true, 'work_site_id' => $site->id,
    ])->save();
    $role = new Role;
    $role->forceFill(['name' => 'Wsc '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    foreach (['EDTR', 'PAYROLL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    Shift::query()->create([
        'employee_id' => $employee->id, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => $dayStart, 'end_time' => '17:00:00', 'grace_minutes' => 15,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60,
        'requires_break_punch' => true,
    ]);
    RosterShift::query()->create([
        'employee_id' => $employee->id, 'weekday' => 1, 'shift_name' => 'Night', 'is_rest_day' => false,
        'shift_data' => (new DomainShift(
            startTime: '22:00:00', endTime: '06:00:00', workDays: [1],
            breakStart: '02:00:00', breakEnd: '03:00:00', breakMinutes: 60, requiresBreakPunch: true,
        ))->toArray(),
    ]);
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 1, 'kind' => 'WFH', 'site_id' => null]);

    return [Access::forActor($user), $employee, $site];
}

function wscPunch(Access $actor, EmployeeProfile $employee, string $type, string $at): void
{
    CarbonImmutable::setTestNow(ManilaClock::instant($at));
    app(RecordPunch::class)->handle($actor, new PunchInput($employee->id, '', 'WEB_GPS', false, '', ''), $type);
}

function wscStoredPunch(EmployeeProfile $employee, string $type, string $at): AttendanceLog
{
    return AttendanceLog::query()->create([
        'employee_id' => $employee->id, 'log_type' => $type, 'source' => 'WEB_GPS',
        'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant($at)->utc(),
    ]);
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('uses Monday home night windows through Tuesday morning and then opens Tuesday onsite hours', function () {
    [$actor, $employee, $site] = wscSetup('07:00:00');
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-21T22:00:00');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T02:00:00');
    CarbonImmutable::setTestNow(ManilaClock::instant('2026-09-22T02:29:00'));
    expect(fn () => app(RecordPunch::class)->handle($actor, new PunchInput($employee->id, '', 'WEB_GPS', false, '', ''), 'TIME_IN'))
        ->toThrow(RuntimeException::class, 'Earliest allowed is 2:30 AM next day');
    expect(AttendanceLog::query()->count())->toBe(2);

    wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T02:30:00');
    $preview = app(RosterLocations::class)->forStation($actor, $employee->id);
    expect($preview?->date)->toBe('2026-09-21')->and($preview?->kind)->toBe('WFH');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T06:00:00');
    expect(AttendanceLog::query()->where('location_kind', 'WFH')->count())->toBe(4);

    // Yesterday's 06:00 Final Out falls inside today's one-hour early
    // window. It must not count as a completed slot on today's shift.
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T07:00:00');
    $latest = AttendanceLog::query()->orderByDesc('logged_at')->firstOrFail();
    expect($latest->location_kind)->toBe('ONSITE')->and($latest->site_id)->toBe($site->id);
    $preview = app(RosterLocations::class)->forStation($actor, $employee->id);
    expect($preview?->date)->toBe('2026-09-22')->and($preview?->site_id)->toBe($site->id);
});

it('keeps a late final out on the home night of its after-midnight second in', function () {
    [$actor, $employee] = wscSetup();
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-21T22:00:00');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T02:00:00');
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T03:00:00');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T07:30:00');

    expect(AttendanceLog::query()->where('location_kind', 'WFH')->count())->toBe(4)
        ->and(AttendanceLog::query()->whereNotNull('site_id')->count())->toBe(0);
});

it('closes a flexible home night before opening the next fixed onsite shift', function () {
    [$actor, $employee, $site] = wscSetup();
    RosterShift::query()->where('employee_id', $employee->id)->firstOrFail()->update([
        'shift_name' => 'Flexible',
        'shift_data' => (new DomainShift(mode: 'FLEXIBLE', requiredHours: 8.0, workDays: [1]))->toArray(),
    ]);
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-21T22:00:00');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T03:00:00');
    expect(AttendanceLog::query()->where('location_kind', 'WFH')->count())->toBe(2);

    wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T08:00:00');
    expect(AttendanceLog::query()->orderByDesc('logged_at')->firstOrFail()->site_id)->toBe($site->id);
    $query = app(DayCorrections::class);
    expect($query->computedDay($actor, $employee->id, '2026-09-21')?->workedMinutes)->toBe(300.0)
        ->and($query->view($actor, $employee->id, '2026-09-22')?->previous_day_punches)->toBe(1)
        ->and($query->view($actor, $employee->id, '2026-09-22')?->slots['time_in'])->toBe('08:00');
});

it('uses the new night window after a regular day without inventing an earlier night', function () {
    [$actor, $employee] = wscSetup();
    RosterShift::query()->where('employee_id', $employee->id)->firstOrFail()->update(['weekday' => 2]);
    CarbonImmutable::setTestNow(ManilaClock::instant('2026-09-22T05:30:00'));
    expect(fn () => app(RecordPunch::class)->handle($actor, new PunchInput($employee->id, '', 'WEB_GPS', false, '', ''), 'TIME_IN'))
        ->toThrow(RuntimeException::class, 'Earliest allowed is 9:00 PM');
    expect(AttendanceLog::query()->count())->toBe(0);
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T22:00:00');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-23T02:00:00');
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-23T03:00:00');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-23T06:00:00');
    expect(app(DayCorrections::class)->computedDay($actor, $employee->id, '2026-09-22')?->workedMinutes)->toBe(420.0);
});

it('opens a different weekday shift after the previous night was fully punched out', function (bool $scheduledBreak) {
    [$actor, $employee, $site] = wscSetup();
    RosterShift::query()->where('employee_id', $employee->id)->firstOrFail()->update([
        'shift_data' => (new DomainShift(
            startTime: '22:00:00', endTime: '08:00:00', workDays: [1],
            breakStart: $scheduledBreak ? '02:00:00' : null,
            breakEnd: $scheduledBreak ? '03:00:00' : null,
            breakMinutes: $scheduledBreak ? 60 : 0,
            requiresBreakPunch: $scheduledBreak,
        ))->toArray(),
    ]);
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-21T22:00:00');
    if ($scheduledBreak) {
        wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T02:00:00');
        wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T03:00:00');
    }
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T07:00:00');
    CarbonImmutable::setTestNow(ManilaClock::instant('2026-09-22T07:30:00'));
    $preview = app(RosterLocations::class)->forStation($actor, $employee->id);
    expect($preview?->date)->toBe('2026-09-22')->and($preview?->site_id)->toBe($site->id);
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T07:30:00');
    expect(AttendanceLog::query()->orderByDesc('logged_at')->firstOrFail()->site_id)->toBe($site->id);
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T12:00:00');
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T13:00:00');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-22T17:00:00');

    $query = app(DayCorrections::class);
    expect($query->computedDay($actor, $employee->id, '2026-09-21')?->workedMinutes)->toBe($scheduledBreak ? 480.0 : 540.0)
        ->and($query->computedDay($actor, $employee->id, '2026-09-22')?->workedMinutes)->toBe(480.0)
        ->and($query->view($actor, $employee->id, '2026-09-22')?->slots['time_in'])->toBe('07:30')
        ->and(AttendanceLog::query()->where('location_kind', 'ONSITE')->count())->toBe(4);
})->with([true, false]);

it('keeps Monday night tail punches when Tuesday regular hours are corrected', function () {
    [$actor, $employee] = wscSetup();
    CarbonImmutable::setTestNow(ManilaClock::instant('2026-09-28T12:00:00'));
    $night = [];
    foreach ([['TIME_IN', '2026-09-21T22:00:00'], ['TIME_OUT', '2026-09-22T02:00:00'], ['TIME_IN', '2026-09-22T03:00:00'], ['TIME_OUT', '2026-09-22T06:00:00']] as [$type, $at]) {
        $night[] = wscStoredPunch($employee, $type, $at)->id;
    }
    foreach ([['TIME_IN', '08:00:00'], ['TIME_OUT', '12:00:00'], ['TIME_IN', '13:00:00'], ['TIME_OUT', '17:00:00']] as [$type, $time]) {
        wscStoredPunch($employee, $type, '2026-09-22T'.$time);
    }

    $query = app(DayCorrections::class);
    $monday = $query->view($actor, $employee->id, '2026-09-21');
    $tuesday = $query->view($actor, $employee->id, '2026-09-22');
    expect($monday?->shift->start_time)->toBe('22:00:00')
        ->and($monday?->next_day_allowed)->toBeTrue()
        ->and($monday?->day?->workedMinutes)->toBe(420.0)
        ->and($tuesday?->shift->start_time)->toBe('08:00:00')
        ->and($tuesday?->next_day_allowed)->toBeFalse()
        ->and($tuesday?->previous_day_punches)->toBe(3)
        ->and($tuesday?->punches)->toHaveCount(4)
        ->and($tuesday?->day?->workedMinutes)->toBe(480.0);

    CorrectPunches::apply($actor, $employee->id, '2026-09-22', [
        'time_in' => '08:00', 'break_out' => '12:00', 'break_in' => '13:00', 'time_out' => '17:10',
    ], PunchSlots::SAME_DAY, null);
    expect(AttendanceLog::query()->whereKey($night)->count())->toBe(4)
        ->and(AttendanceLog::query()->count())->toBe(8)
        ->and($query->computedDay($actor, $employee->id, '2026-09-21')?->workedMinutes)->toBe(420.0)
        ->and($query->computedDay($actor, $employee->id, '2026-09-22')?->workedMinutes)->toBe(490.0);

    CorrectPunches::apply($actor, $employee->id, '2026-09-21', [
        'time_in' => '22:00', 'break_out' => '02:00', 'break_in' => '03:00', 'time_out' => '06:00',
    ], ['time_in' => false, 'break_out' => true, 'break_in' => true, 'time_out' => true], null);
    expect(AttendanceLog::query()->whereKey($night)->count())->toBe(4)
        ->and(AttendanceLog::query()->count())->toBe(8);

    $inputs = app(AttendanceSource::class)->forPayroll($actor, [$employee->id], '2026-09-21', '2026-09-22');
    expect($inputs[$employee->id]->shift?->forDate('2026-09-21')->startTime)->toBe('22:00:00')
        ->and($inputs[$employee->id]->shift?->forDate('2026-09-22')->startTime)->toBe('08:00:00');
});

it('uses one specified date night and home location without changing the following week', function () {
    [$actor, $employee, $site] = wscSetup();
    RosterDayOverride::query()->create([
        'employee_id' => $employee->id, 'date' => '2026-09-22', 'shift_name' => 'Tomorrow night',
        'is_rest_day' => false, 'location_kind' => 'WFH', 'site_id' => null,
        'shift_data' => (new DomainShift(
            startTime: '22:00:00', endTime: '06:00:00', workDays: [2],
            breakStart: '02:00:00', breakEnd: '03:00:00', breakMinutes: 60, requiresBreakPunch: true,
        ))->toArray(),
    ]);
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-22T22:00:00');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-23T02:00:00');
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-23T03:00:00');
    expect(app(RosterLocations::class)->forStation($actor, $employee->id)?->kind)->toBe('WFH');
    wscPunch($actor, $employee, 'TIME_OUT', '2026-09-23T06:00:00');
    wscPunch($actor, $employee, 'TIME_IN', '2026-09-23T08:00:00');

    expect(AttendanceLog::query()->where('location_kind', 'WFH')->count())->toBe(4)
        ->and(AttendanceLog::query()->orderByDesc('logged_at')->firstOrFail()->site_id)->toBe($site->id);
    $query = app(DayCorrections::class);
    $night = $query->view($actor, $employee->id, '2026-09-22');
    expect($night?->next_day_allowed)->toBeTrue()
        ->and($night?->location->kind)->toBe('WFH')
        ->and($night?->day?->workedMinutes)->toBe(420.0)
        ->and($query->view($actor, $employee->id, '2026-09-23')?->previous_day_punches)->toBe(3)
        ->and($query->view($actor, $employee->id, '2026-09-23')?->next_day_allowed)->toBeFalse();

    CorrectPunches::apply($actor, $employee->id, '2026-09-22', [
        'time_in' => '22:00', 'break_out' => '02:00', 'break_in' => '03:00', 'time_out' => '06:10',
    ], ['time_in' => false, 'break_out' => true, 'break_in' => true, 'time_out' => true], null);
    expect(AttendanceLog::query()->where('source', 'CORRECTION')->firstOrFail()->location_kind)->toBe('WFH')
        ->and($query->view($actor, $employee->id, '2026-09-23')?->slots['time_in'])->toBe('08:00');

    $nextWeek = app(AttendanceSource::class)->forDtr($actor, $employee->id, '2026-09-29', '2026-09-29');
    expect($nextWeek->shift?->forDate('2026-09-29')->startTime)->toBe('08:00:00');
    CarbonImmutable::setTestNow(ManilaClock::instant('2026-09-29T08:00:00'));
    expect(app(RosterLocations::class)->forStation($actor, $employee->id)?->kind)->toBe('ONSITE');
});

it('loads full-month date overrides for payroll rates while DTR loads its adjacent dates only', function () {
    [$actor, $employee] = wscSetup();
    foreach (['2026-08-30', '2026-09-04', '2026-10-01', '2026-10-02'] as $date) {
        RosterDayOverride::query()->create([
            'employee_id' => $employee->id, 'date' => $date, 'shift_name' => 'Rest day',
            'is_rest_day' => true, 'shift_data' => DomainShift::restDay()->toArray(),
        ]);
    }
    $source = app(AttendanceSource::class);
    $payroll = $source->forPayroll($actor, [$employee->id], '2026-09-21', '2026-09-22')[$employee->id];
    $dtr = $source->forDtr($actor, $employee->id, '2026-09-21', '2026-09-22');

    expect($payroll->shift?->forDate('2026-08-30')->workDays)->toBe([-1])
        ->and($payroll->shift?->forDate('2026-09-04')->workDays)->toBe([-1])
        ->and($payroll->shift?->forDate('2026-10-01')->workDays)->toBe([-1])
        ->and($payroll->shift?->forDate('2026-10-02')->workDays)->toBe([1, 2, 3, 4, 5])
        ->and($dtr->shift?->forDate('2026-09-04')->workDays)->toBe([1, 2, 3, 4, 5])
        ->and($dtr->shift?->forDate('2026-10-01')->workDays)->toBe([1, 2, 3, 4, 5]);
});

it('names an exact date for a one-date site mismatch and keeps recurring weekday wording', function () {
    [$actor, $employee, $assigned] = wscSetup();
    $other = WorkSite::query()->create([
        'name' => 'Special office', 'latitude' => 14.7, 'longitude' => 121.1,
        'radius_meters' => 150, 'geofence_exempt' => true,
    ]);
    $dated = RosterDayOverride::query()->create([
        'employee_id' => $employee->id, 'date' => '2026-09-22', 'location_kind' => 'ONSITE',
        'site_id' => $other->id, 'is_rest_day' => false,
    ]);
    CarbonImmutable::setTestNow(ManilaClock::instant('2026-09-22T08:00:00'));
    $input = new PunchInput($employee->id, $assigned->id, 'WEB_GPS', false, '', '');
    expect(fn () => app(RecordPunch::class)->handle($actor, $input, 'TIME_IN'))
        ->toThrow(RuntimeException::class, 'works at Special office on '.HrisFormat::date('2026-09-22').'.');

    $dated->delete();
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 2, 'kind' => 'ONSITE', 'site_id' => $other->id]);
    expect(fn () => app(RecordPunch::class)->handle($actor, $input, 'TIME_IN'))
        ->toThrow(RuntimeException::class, 'works at Special office on Tuesdays.');
    expect(AttendanceLog::query()->count())->toBe(0);
});

it('loads two previous dates so an unfinished night does not make a flexible day consume the following correction', function () {
    [$actor, $employee] = wscSetup();
    $night = RosterShift::query()->where('employee_id', $employee->id)->firstOrFail();
    RosterDayOverride::query()->create([
        'employee_id' => $employee->id, 'date' => '2026-09-21', 'shift_name' => 'One night',
        'is_rest_day' => false, 'shift_data' => $night->shift_data,
    ]);
    $night->delete();
    RosterDayOverride::query()->create([
        'employee_id' => $employee->id, 'date' => '2026-09-22', 'shift_name' => 'Flexible',
        'is_rest_day' => false, 'shift_data' => (new DomainShift(mode: 'FLEXIBLE', requiredHours: 8.0))->toArray(),
    ]);
    $nightPunches = [];
    foreach ([['TIME_IN', '2026-09-21T22:00:00'], ['TIME_OUT', '2026-09-22T02:00:00'], ['TIME_IN', '2026-09-22T03:00:00']] as [$type, $at]) {
        $nightPunches[] = wscStoredPunch($employee, $type, $at)->id;
    }
    $stray = wscStoredPunch($employee, 'TIME_OUT', '2026-09-23T09:00:00');
    CarbonImmutable::setTestNow(ManilaClock::instant('2026-09-28T12:00:00'));

    $inputs = app(AttendanceSource::class)->forDtr($actor, $employee->id, '2026-09-23', '2026-09-23');
    expect($inputs->logs)->toHaveCount(4)
        ->and($inputs->shift?->forDate('2026-09-21')->startTime)->toBe('22:00:00')
        ->and($inputs->shift?->forDate('2026-09-22')->isFlexible())->toBeTrue();
    $correction = app(DayCorrections::class)->view($actor, $employee->id, '2026-09-23');
    expect($correction?->punches)->toHaveCount(1)
        ->and($correction?->punches[0]->id)->toBe($stray->id)
        ->and($correction?->previous_day_punches)->toBe(0);

    CorrectPunches::apply($actor, $employee->id, '2026-09-23', [
        'time_in' => null, 'break_out' => null, 'break_in' => null, 'time_out' => null,
    ], PunchSlots::SAME_DAY, null);
    expect($stray->fresh())->toBeNull()
        ->and(AttendanceLog::query()->whereKey($nightPunches)->count())->toBe(3);
});

it('does not extend live pairing to a two-days-old open punch when reading extra schedule context', function () {
    [$actor, $employee] = wscSetup();
    wscStoredPunch($employee, 'TIME_IN', '2026-09-21T22:00:00');
    CarbonImmutable::setTestNow(ManilaClock::instant('2026-09-23T09:00:00'));

    expect(fn () => app(RecordPunch::class)->handle($actor, new PunchInput($employee->id, '', 'WEB_GPS', false, '', ''), 'TIME_OUT'))
        ->toThrow(RuntimeException::class, 'TIME_OUT with no open TIME_IN.');
    expect(AttendanceLog::query()->count())->toBe(1);
});
