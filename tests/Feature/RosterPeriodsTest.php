<?php

declare(strict_types=1);

use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\ShiftResolver;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\AssignmentConflict;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\CancelAssignment;
use App\Modules\Timekeeping\Application\Commands\CancelScheduleChange;
use App\Modules\Timekeeping\Application\Commands\EditAssignment;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\Commands\SaveRosterShifts;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\ScheduleChangeRefused;
use App\Modules\Timekeeping\Application\ScheduleImpactPending;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Str;

/**
 * A temporary schedule covers one date or a From–To period: its shift keeps
 * the weekly rest days, the dates before it keep their schedule, the weekly
 * schedule in effect then resumes after it, its last night finishes normally,
 * an overlap is resolved explicitly, and changing or cancelling it keeps the
 * part already worked and the record of who did what.
 */
/** @param list<string> $modules */
function rperActor(array $modules = ['ROSTER', 'RECORDS', 'EDTR'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Period '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param  array<string, mixed>  $overrides */
function rperPattern(string $start, string $end, array $overrides = []): ShiftType
{
    return ShiftType::query()->create(array_replace(['code' => Str::random(10), 'name' => 'Period '.Str::random(6), 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false], $overrides));
}

/** An employee on a baseline default shift ("since before history was kept"). */
function rperEmployee(ShiftType $default, string $first = 'Pat'): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'PE-'.Str::random(8), 'first_name' => $first, 'last_name' => 'Period',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
    Shift::query()->create(ShiftPolicy::attributesFromPattern($default) + ['employee_id' => $employee->id]);

    return $employee;
}

function rperAt(string $manila): void
{
    test()->travelTo(new DateTimeImmutable($manila.'+08:00'));
}

function rperPunch(EmployeeProfile $employee, string $type, string $manila): AttendanceLog
{
    return AttendanceLog::query()->create(['employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL', 'logged_at' => LocalClock::instant($manila)->utc()]);
}

/**
 * What each date resolves to: "08:00-17:00", "rest", with "@WFH" or "@site".
 *
 * @param  list<string>  $dates
 * @return array<string, string>
 */
function rperDays(EmployeeProfile $employee, array $dates): array
{
    $roster = app(ShiftRoster::class);
    $schedule = $roster->scheduleForEmployee($employee->id, $dates[0], $dates[count($dates) - 1]);
    $out = [];
    foreach ($dates as $date) {
        $shift = $schedule?->forDate($date);
        $place = $roster->locationForDate($employee->id, $date);
        $out[$date] = (ShiftResolver::worksOnDate($date, $schedule) ? substr((string) $shift?->startTime, 0, 5).'-'.substr((string) $shift?->endTime, 0, 5) : 'rest')
            .($place === null ? '' : '@'.($place->kind === 'WFH' ? 'WFH' : 'site'));
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function rperDtr(EmployeeProfile $employee, string $start, string $end): array
{
    $inputs = app(AttendanceSource::class)->forDtr(app(Access::class), $employee->id, $start, $end);

    return json_decode((string) json_encode(DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, $start, $end)), true);
}

beforeEach(function () {
    // Wednesday 2026-10-07, before any shift here starts.
    rperAt('2026-10-07T05:00:00');
});

/* |---| A period |---| */

it('gives a period its shift on working days and its place on every date, and resumes the weekly schedule after it', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $night = rperPattern('22:00:00', '06:00:00');

    expect(app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-12', '2026-10-23', $night->id, 'WFH'))
        ->toBe('Temporary schedule saved for Pat Period, Oct 12, 2026 to Oct 23, 2026.');

    expect(rperDays($employee, ['2026-10-09', '2026-10-11', '2026-10-12', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-23', '2026-10-24', '2026-10-26']))->toBe([
        '2026-10-09' => '08:00-17:00',      // before it: the default shift
        '2026-10-11' => 'rest',             // Sunday before it
        '2026-10-12' => '22:00-06:00@WFH',
        '2026-10-16' => '22:00-06:00@WFH',
        '2026-10-17' => 'rest@WFH',         // Saturday and Sunday stay rest days
        '2026-10-18' => 'rest@WFH',
        '2026-10-19' => '22:00-06:00@WFH',
        '2026-10-23' => '22:00-06:00@WFH',  // its last night, which ends on the 24th
        '2026-10-24' => 'rest',
        '2026-10-26' => '08:00-17:00',      // after it: the default shift again
    ]);
    $row = RosterDayOverride::query()->sole();
    expect($row->end_date?->format('Y-m-d'))->toBe('2026-10-23')
        ->and($row->include_rest_days)->toBeFalse()
        ->and($row->created_by)->toBe((int) $actor->userId)
        ->and(AuditLog::query()->where('action', 'ROSTER_PERIOD_SET')->sole()->details)->toBe("Pat Period · 2026-10-12 to 2026-10-23 · {$night->name} · Work from home");
});

it('resumes the weekly schedule in effect THEN, not the one in effect when the period was saved', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-12', '2026-10-16', rperPattern('13:00:00', '22:00:00')->id, 'DEFAULT');
    // The default shift itself changes from the 14th, inside the period.
    app(AssignShift::class)->handle($actor, $employee->id, rperPattern('09:00:00', '18:00:00')->id, '2026-10-14');

    expect(rperDays($employee, ['2026-10-09', '2026-10-13', '2026-10-15', '2026-10-19']))->toBe([
        '2026-10-09' => '08:00-17:00',
        '2026-10-13' => '13:00-22:00',
        '2026-10-15' => '13:00-22:00',
        '2026-10-19' => '09:00-18:00',
    ]);
});

it('works rest days only by the explicit choice, and refuses a shift for dates that all rest', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $short = rperPattern('09:00:00', '13:00:00');

    expect(fn () => app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-10', '2026-10-11', $short->id, 'DEFAULT'))
        ->toThrow(RuntimeException::class, 'Every date from Oct 10, 2026 to Oct 11, 2026 is a rest day on Pat Period\'s schedule, and a shift change keeps rest days.');
    expect(RosterDayOverride::query()->count())->toBe(0);

    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-09', '2026-10-12', $short->id, 'DEFAULT', workOnRestDays: true);
    expect(RosterDayOverride::query()->sole()->include_rest_days)->toBeTrue()
        ->and(rperDays($employee, ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13']))->toBe([
            '2026-10-09' => '09:00-13:00', '2026-10-10' => '09:00-13:00', '2026-10-11' => '09:00-13:00', '2026-10-12' => '09:00-13:00', '2026-10-13' => '08:00-17:00',
        ])
        ->and(AuditLog::query()->where('action', 'ROSTER_PERIOD_SET')->sole()->details)->toContain('rest days worked');
});

it('refuses a period that is empty, backwards, too long, in the past, or without all-data roster access', function () {
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $night = rperPattern('22:00:00', '06:00:00');
    $actor = rperActor();
    $save = app(SaveAssignment::class);

    expect(fn () => $save->handle($actor, $employee->id, '2026-10-12', '2026-10-09', $night->id, 'WFH'))->toThrow(RuntimeException::class, 'The last date cannot be before the first date.')
        ->and(fn () => $save->handle($actor, $employee->id, '2026-10-12', '2027-10-13', $night->id, 'WFH'))->toThrow(RuntimeException::class, 'A temporary schedule covers at most one year.')
        ->and(fn () => $save->handle($actor, $employee->id, '2026-10-12', '2026-10-16', 'DEFAULT', 'DEFAULT'))->toThrow(RuntimeException::class, 'Choose a shift or a location that differs from the weekly schedule.')
        ->and(fn () => $save->handle($actor, $employee->id, '2026-10-06', '2026-10-16', $night->id, 'WFH'))->toThrow(ScheduleChangeRefused::class, 'A schedule change cannot start in the past.')
        ->and(fn () => $save->handle($actor, $employee->id, '2026-10-12', '2026-10-16', 'missing', 'WFH'))->toThrow(RuntimeException::class, 'That shift pattern no longer exists.')
        ->and(fn () => $save->handle($actor, 'missing', '2026-10-12', '2026-10-16', $night->id, 'WFH'))->toThrow(RuntimeException::class, 'That employee no longer exists.');
    foreach ([rperActor(['ROSTER'], allData: false), rperActor(['EDTR', 'RECORDS'])] as $denied) {
        expect(fn () => $save->handle($denied, $employee->id, '2026-10-12', '2026-10-16', $night->id, 'WFH'))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    }
    expect(RosterDayOverride::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

/* |---| The last night (D5) |---| */

it('lets a period’s last night finish the next morning and refuses a schedule that would start over it', function () {
    $actor = rperActor();
    // Monday to Saturday, 05:00 starts: Saturday 05:00 is before Friday's night ends.
    $employee = rperEmployee(rperPattern('05:00:00', '13:00:00', ['work_days' => [1, 2, 3, 4, 5, 6]]));
    $night = rperPattern('22:00:00', '06:00:00');

    expect(fn () => app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-12', '2026-10-16', $night->id, 'DEFAULT'))
        ->toThrow(ScheduleChangeRefused::class, 'The shift that starts on Oct 16, 2026 ends at 06:00 the next day, after the shift on Oct 17, 2026 starts at 05:00. Adjust one of them so they do not overlap.');
    expect(RosterDayOverride::query()->count())->toBe(0);

    // Through Saturday, the last night ends on a Sunday: no shift to run into.
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-12', '2026-10-17', $night->id, 'DEFAULT');
    expect(rperDays($employee, ['2026-10-17', '2026-10-18', '2026-10-19']))->toBe(['2026-10-17' => '22:00-06:00', '2026-10-18' => 'rest', '2026-10-19' => '05:00-13:00']);

    // The punch-out of that last night belongs to the night, not to Sunday.
    $in = rperPunch($employee, 'TIME_IN', '2026-10-17T22:00:00');
    $out = rperPunch($employee, 'TIME_OUT', '2026-10-18T06:03:00');
    rperAt('2026-10-19T04:00:00');
    $inputs = app(AttendanceSource::class)->forDtr($actor, $employee->id, '2026-10-17', '2026-10-18');
    expect(array_map(static fn ($log): ?string => $log->id, DtrCalculator::rowPunches($inputs->logs, $inputs->shift, '2026-10-17')))->toEqualCanonicalizing([$in->id, $out->id])
        ->and(rperDtr($employee, '2026-10-17', '2026-10-17')[0]['status'])->toBe('PRESENT');
});

/* |---| Overlaps (D1) |---| */

it('never replaces an overlapping assignment silently: it lists them, and replaces only the ones named', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $late = rperPattern('13:00:00', '22:00:00');
    $night = rperPattern('22:00:00', '06:00:00');
    $save = app(SaveAssignment::class);
    $save->handle($actor, $employee->id, '2026-10-08', '2026-10-13', $late->id, 'DEFAULT');
    $save->handle($actor, $employee->id, '2026-10-15', null, 'REST', 'DEFAULT');
    $save->handle($actor, $employee->id, '2026-10-20', '2026-10-27', $late->id, 'WFH');
    [$head, $day, $tail] = RosterDayOverride::query()->orderBy('date')->get()->all();
    $audits = AuditLog::query()->count();

    try {
        $save->handle($actor, $employee->id, '2026-10-12', '2026-10-21', $night->id, 'DEFAULT');
        $this->fail('The overlap was not refused.');
    } catch (AssignmentConflict $conflict) {
        expect($conflict->conflicts)->toBe([
            ['id' => $head->id, 'from' => '2026-10-08', 'to' => '2026-10-13', 'shift' => $late->name, 'place' => 'Weekly location', 'overlap_from' => '2026-10-12', 'overlap_to' => '2026-10-13'],
            ['id' => $day->id, 'from' => '2026-10-15', 'to' => '2026-10-15', 'shift' => 'Rest day', 'place' => 'Weekly location', 'overlap_from' => '2026-10-15', 'overlap_to' => '2026-10-15'],
            ['id' => $tail->id, 'from' => '2026-10-20', 'to' => '2026-10-27', 'shift' => $late->name, 'place' => 'Work from home', 'overlap_from' => '2026-10-20', 'overlap_to' => '2026-10-21'],
        ]);
    }
    // Naming some is not naming all.
    expect(fn () => $save->handle($actor, $employee->id, '2026-10-12', '2026-10-21', $night->id, 'DEFAULT', replace: [$head->id, $day->id]))->toThrow(AssignmentConflict::class)
        ->and(fn () => $save->preview($actor, $employee->id, '2026-10-12', '2026-10-21', $night->id, 'DEFAULT'))->toThrow(AssignmentConflict::class);
    expect(RosterDayOverride::query()->count())->toBe(3)->and(AuditLog::query()->count())->toBe($audits);

    $save->handle($actor, $employee->id, '2026-10-12', '2026-10-21', $night->id, 'DEFAULT', replace: [$head->id, $day->id, $tail->id]);

    // Each keeps its own dates before and after; only the shared dates moved.
    expect(rperDays($employee, ['2026-10-08', '2026-10-09', '2026-10-12', '2026-10-15', '2026-10-21', '2026-10-22', '2026-10-27', '2026-10-28']))->toBe([
        '2026-10-08' => '13:00-22:00', '2026-10-09' => '13:00-22:00',
        '2026-10-12' => '22:00-06:00', '2026-10-15' => '22:00-06:00', '2026-10-21' => '22:00-06:00',
        '2026-10-22' => '13:00-22:00@WFH', '2026-10-27' => '13:00-22:00@WFH',
        '2026-10-28' => '08:00-17:00',
    ]);
    // Nothing was deleted: the three are kept, cancelled with who and when.
    foreach ([[$head, '2026-10-11'], [$day, '2026-10-11'], [$tail, '2026-10-11']] as [$row, $cut]) {
        $row->refresh();
        expect($row->status)->toBe('CANCELLED')
            ->and($row->cut_after?->format('Y-m-d'))->toBe($cut)
            ->and($row->cancelled_by)->toBe((int) $actor->userId)
            ->and($row->cancelled_at)->not->toBeNull();
    }
    $continued = RosterDayOverride::query()->where('revision_of', $tail->id)->sole();
    expect($continued->date->format('Y-m-d'))->toBe('2026-10-22')
        ->and($continued->end_date?->format('Y-m-d'))->toBe('2026-10-27')
        ->and($continued->location_kind)->toBe('WFH')
        ->and(RosterDayOverride::query()->count())->toBe(5)
        ->and(AuditLog::query()->where('action', 'ROSTER_PERIOD_SET')->latest('id')->first()?->details)->toContain('replaced 3 on the shared dates');
});

/* |---| Changing a period (D2) |---| */

it('changes every part of a period that has not started, keeping the original in the history', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $late = rperPattern('13:00:00', '22:00:00');
    $night = rperPattern('22:00:00', '06:00:00');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-12', '2026-10-16', $late->id, 'DEFAULT');
    $original = RosterDayOverride::query()->sole();

    expect(app(EditAssignment::class)->handle($actor, $original->id, '2026-10-14', '2026-10-23', $night->id, 'WFH'))
        ->toBe('Temporary schedule changed, Oct 14, 2026 to Oct 23, 2026.');

    $original->refresh();
    $changed = RosterDayOverride::query()->where('revision_of', $original->id)->sole();
    expect($original->status)->toBe('CANCELLED')
        ->and($original->date->format('Y-m-d'))->toBe('2026-10-12')
        ->and($original->shift_name)->toBe($late->name)
        ->and($changed->date->format('Y-m-d'))->toBe('2026-10-14')
        ->and(rperDays($employee, ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-23', '2026-10-26']))->toBe([
            '2026-10-12' => '08:00-17:00', '2026-10-13' => '08:00-17:00', '2026-10-14' => '22:00-06:00@WFH', '2026-10-23' => '22:00-06:00@WFH', '2026-10-26' => '08:00-17:00',
        ])
        // The same again changes nothing and audits nothing.
        ->and(app(EditAssignment::class)->handle($actor, $changed->id, '2026-10-14', '2026-10-23', $night->id, 'WFH'))->toBe('No changes to that temporary schedule.')
        ->and(AuditLog::query()->where('action', 'ROSTER_PERIOD_SET')->count())->toBe(2);
});

it('keeps the part already worked when a running period changes, from the next eligible date', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $late = rperPattern('13:00:00', '22:00:00');
    $night = rperPattern('22:00:00', '06:00:00');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-07', '2026-10-16', $late->id, 'WFH');
    $original = RosterDayOverride::query()->sole();
    foreach (['2026-10-07', '2026-10-08'] as $date) {
        rperPunch($employee, 'TIME_IN', $date.'T13:10:00');
        rperPunch($employee, 'TIME_OUT', $date.'T22:00:00');
    }
    // Friday the 9th, mid-shift: today's shift has started and is punched.
    rperPunch($employee, 'TIME_IN', '2026-10-09T13:00:00');
    rperAt('2026-10-09T15:00:00');
    $worked = rperDtr($employee, '2026-10-07', '2026-10-09');

    // The first date asked for is not read: the start is history.
    $impact = app(EditAssignment::class)->preview($actor, $original->id, '2026-10-01', '2026-10-20', $night->id, 'DEFAULT');
    expect($impact->effective)->toBe([$employee->id => '2026-10-10'])
        ->and($impact->deferred)->toBe([$employee->id => 'a punch is already recorded for today’s shift'])
        ->and(fn () => app(EditAssignment::class)->handle($actor, $original->id, '2026-10-01', '2026-10-20', $night->id, 'DEFAULT'))->toThrow(ScheduleImpactPending::class);
    app(EditAssignment::class)->handle($actor, $original->id, '2026-10-01', '2026-10-20', $night->id, 'DEFAULT', confirmed: $impact->token());

    $original->refresh();
    expect($original->date->format('Y-m-d'))->toBe('2026-10-07')
        ->and($original->shift_name)->toBe($late->name)
        ->and($original->location_kind)->toBe('WFH')
        ->and($original->cut_after?->format('Y-m-d'))->toBe('2026-10-09')
        ->and($original->cancelled_by)->toBe((int) $actor->userId)
        ->and(rperDays($employee, ['2026-10-07', '2026-10-09', '2026-10-12', '2026-10-20', '2026-10-21']))->toBe([
            '2026-10-07' => '13:00-22:00@WFH', '2026-10-09' => '13:00-22:00@WFH', '2026-10-12' => '22:00-06:00', '2026-10-20' => '22:00-06:00', '2026-10-21' => '08:00-17:00',
        ])
        ->and(rperDtr($employee, '2026-10-07', '2026-10-09'))->toBe($worked);
});

it('refuses to change or cancel a period that has ended or was cancelled', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $late = rperPattern('13:00:00', '22:00:00');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-07', '2026-10-09', $late->id, 'DEFAULT');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-19', '2026-10-23', $late->id, 'DEFAULT');
    [$ended, $future] = RosterDayOverride::query()->orderBy('date')->get()->all();
    app(CancelAssignment::class)->handle($actor, $future->id);

    rperAt('2026-10-12T05:00:00');
    expect(fn () => app(EditAssignment::class)->handle($actor, $ended->id, '2026-10-07', '2026-10-16', $late->id, 'WFH'))->toThrow(RuntimeException::class, 'That temporary schedule has ended.')
        ->and(fn () => app(CancelAssignment::class)->handle($actor, $ended->id))->toThrow(RuntimeException::class, 'That temporary schedule has ended.')
        ->and(fn () => app(EditAssignment::class)->handle($actor, $future->id, '2026-10-19', '2026-10-23', $late->id, 'WFH'))->toThrow(RuntimeException::class, 'was cancelled and can no longer change')
        ->and(fn () => app(CancelAssignment::class)->handle($actor, $future->id))->toThrow(RuntimeException::class, 'already cancelled')
        ->and(fn () => app(CancelAssignment::class)->handle($actor, 'missing'))->toThrow(RuntimeException::class, 'no longer exists')
        ->and(fn () => app(CancelAssignment::class)->handle(rperActor(['ROSTER'], allData: false), $ended->id))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect($ended->fresh()?->status)->toBe('ACTIVE')->and($ended->fresh()?->cut_after)->toBeNull();
});

/* |---| Cancelling (D3) |---| */

it('cancels a period that has not started whole, and ends a running one from the next eligible date', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $late = rperPattern('13:00:00', '22:00:00');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-07', '2026-10-16', $late->id, 'WFH');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-26', '2026-10-30', $late->id, 'DEFAULT');
    [$running, $future] = RosterDayOverride::query()->orderBy('date')->get()->all();

    expect(app(CancelAssignment::class)->handle($actor, $future->id))->toBe('Temporary schedule cancelled.');
    $future->refresh();
    expect($future->status)->toBe('CANCELLED')
        ->and($future->cut_after?->format('Y-m-d'))->toBe('2026-10-25')
        ->and($future->cancelled_by)->toBe((int) $actor->userId)
        ->and(rperDays($employee, ['2026-10-26'])['2026-10-26'])->toBe('08:00-17:00')
        ->and(AuditLog::query()->where('action', 'ROSTER_PERIOD_CANCELLED')->sole()->details)->toContain('cancelled before it began');

    // Thursday the 8th, 14:00: today's 13:00 shift has started, so it keeps its hours.
    rperPunch($employee, 'TIME_IN', '2026-10-07T13:00:00');
    rperPunch($employee, 'TIME_OUT', '2026-10-07T22:00:00');
    rperAt('2026-10-08T14:00:00');
    $worked = rperDtr($employee, '2026-10-07', '2026-10-08');
    $impact = app(CancelAssignment::class)->preview($actor, $running->id);
    expect($impact->effective)->toBe([$employee->id => '2026-10-09']);
    expect(app(CancelAssignment::class)->handle($actor, $running->id, $impact->token()))->toBe('Temporary schedule ended. The weekly schedule resumes on Oct 09, 2026.');

    $running->refresh();
    expect($running->status)->toBe('CANCELLED')
        ->and($running->cut_after?->format('Y-m-d'))->toBe('2026-10-08')
        ->and(rperDays($employee, ['2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12']))->toBe([
            '2026-10-07' => '13:00-22:00@WFH', '2026-10-08' => '13:00-22:00@WFH', '2026-10-09' => '08:00-17:00', '2026-10-12' => '08:00-17:00',
        ])
        ->and(rperDtr($employee, '2026-10-07', '2026-10-08'))->toBe($worked)
        ->and(RosterDayOverride::query()->count())->toBe(2);
});

it('refuses a cancellation when the schedule that resumes would start over the last night worked', function () {
    $actor = rperActor();
    // The default starts at 06:00; the period's nights end at 07:00 the next day.
    $employee = rperEmployee(rperPattern('06:00:00', '15:00:00'));
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-12', '2026-10-16', rperPattern('22:00:00', '07:00:00')->id, 'DEFAULT');
    $period = RosterDayOverride::query()->sole();
    rperPunch($employee, 'TIME_IN', '2026-10-13T22:00:00');
    rperPunch($employee, 'TIME_OUT', '2026-10-14T07:00:00');

    // Wednesday the 14th, noon: the default's 06:00 has passed, so the
    // period would end from the 15th — whose 06:00 start is before tonight's
    // shift ends.
    rperAt('2026-10-14T12:00:00');
    expect(fn () => app(CancelAssignment::class)->handle($actor, $period->id, 'anything'))
        ->toThrow(ScheduleChangeRefused::class, 'The shift that starts on Oct 14, 2026 ends at 07:00 the next day, after the shift on Oct 15, 2026 starts at 06:00.');
    expect($period->fresh()?->status)->toBe('ACTIVE')->and($period->fresh()?->cut_after)->toBeNull()
        ->and(AuditLog::query()->where('action', 'ROSTER_PERIOD_CANCELLED')->count())->toBe(0);
});

it('cancels a default or weekly change before it takes effect, and no other', function () {
    $actor = rperActor();
    $pattern = rperPattern('08:00:00', '17:00:00');
    $employee = rperEmployee($pattern);
    app(AssignShift::class)->handle($actor, $employee->id, rperPattern('09:00:00', '18:00:00')->id, '2026-10-12');
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [5 => 'REST'], '2026-10-07');
    app(SaveShiftType::class)->handle($actor, $pattern->id, new ShiftTypeDraft($pattern->name, 'FIXED', [1, 2, 3, 4, 5], '08:30', '17:30', '0', '', false, '00:00', '01:00', '0', false), '2026-10-08');
    $changes = RosterScheduleChange::query()->orderBy('id')->get();
    [$default, $weekly, $edit] = [$changes[0], $changes[1], $changes[2]];
    expect(rperDays($employee, ['2026-10-09', '2026-10-12']))->toBe(['2026-10-09' => 'rest', '2026-10-12' => '09:00-18:00']);

    expect(app(CancelScheduleChange::class)->handle($actor, $default->id))->toBe('Scheduled change cancelled. The current schedule continues.');
    $default->refresh();
    expect($default->status)->toBe('CANCELLED')
        ->and($default->cancelled_by)->toBe((int) $actor->userId)
        ->and($default->cancelled_at)->not->toBeNull()
        // Its row is kept, and simply never applies.
        ->and(Shift::query()->where('change_id', $default->id)->count())->toBe(1)
        ->and(rperDays($employee, ['2026-10-12'])['2026-10-12'])->toBe('08:30-17:30')
        ->and(app(CancelScheduleChange::class)->handle($actor, $default->id))->toBe('That scheduled change was already cancelled.')
        ->and(AuditLog::query()->where('action', 'ROSTER_CHANGE_CANCELLED')->sole()->details)->toBe('Pat Period · schedule change from Oct 12, 2026 cancelled before it took effect');

    // A pattern edit's copy, and a change already in effect, are not cancelled.
    rperAt('2026-10-09T05:00:00');
    expect(fn () => app(CancelScheduleChange::class)->handle($actor, $edit->id))->toThrow(RuntimeException::class, 'That change came from editing a shift pattern.')
        ->and(fn () => app(CancelScheduleChange::class)->handle($actor, $weekly->id))->toThrow(RuntimeException::class, 'That change is already in effect.')
        ->and(fn () => app(CancelScheduleChange::class)->handle($actor, 'missing'))->toThrow(RuntimeException::class, 'no longer exists')
        ->and(fn () => app(CancelScheduleChange::class)->handle(rperActor(['ROSTER'], allData: false), $weekly->id))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect($weekly->fresh()?->status)->toBe('ACTIVE')->and(RosterShift::query()->count())->toBe(1);
});

/* |---| The weekly schedule under a period (D6) |---| */

it('shows the dates inside a period that a weekly change turns into rest or working days, before saving it', function () {
    $actor = rperActor();
    $employee = rperEmployee(rperPattern('08:00:00', '17:00:00'));
    $late = rperPattern('13:00:00', '22:00:00');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-12', '2026-10-18', $late->id, 'DEFAULT');

    // Fridays rest and Saturdays work, from the 14th.
    $impact = app(SaveRosterShifts::class)->preview($actor, $employee->id, [5 => 'REST', 6 => $late->id], '2026-10-14');
    expect($impact->restDays)->toBe([
        ['employee_id' => $employee->id, 'date' => '2026-10-16', 'works' => false],
        ['employee_id' => $employee->id, 'date' => '2026-10-17', 'works' => true],
    ]);
    expect(fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, [5 => 'REST', 6 => $late->id], '2026-10-14'))->toThrow(ScheduleImpactPending::class);
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [5 => 'REST', 6 => $late->id], '2026-10-14', $impact->token());

    expect(rperDays($employee, ['2026-10-13', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18']))->toBe([
        '2026-10-13' => '13:00-22:00', '2026-10-15' => '13:00-22:00', '2026-10-16' => 'rest', '2026-10-17' => '13:00-22:00', '2026-10-18' => 'rest',
    ]);
});
