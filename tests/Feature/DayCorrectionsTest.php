<?php

declare(strict_types=1);

use App\Domain\Attendance\DayRecord;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Contracts\Views\CorrectionPunchView;
use App\Modules\Timekeeping\Contracts\Views\DayCorrectionView;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The DTR correction dialog's read: all-data EDTR only, and detached facts —
 * the shift for THAT date through the per-date seam, the day as the engine
 * computed it, the stored remark, the prefilled slots with their "Next day"
 * ticks, and every recorded punch a save would supersede, with its site,
 * distance, accuracy and coordinates. Those are exactly the punches the DTR
 * reads as THAT date's row — never the previous night's after-midnight
 * punches on the same calendar day, which are only counted.
 * The dialog and the save read the same rows the same way.
 */

/** @param list<string> $modules */
function daycorrRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'DayCorr '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param list<string> $modules */
function daycorrActor(array $modules, bool $allData = false, ?string $employeeId = null): Access
{
    $user = User::factory()->create([
        'role_id' => daycorrRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return Access::forActor($user);
}

function daycorrSite(): WorkSite
{
    return WorkSite::query()->create([
        'name' => 'Field Office '.Str::random(4),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => false,
    ]);
}

function daycorrEmployee(?WorkSite $site = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'DC-'.random_int(10000, 99999),
        'first_name' => 'Liza',
        'last_name' => 'Domingo',
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
        'work_site_id' => $site?->getKey(),
    ])->save();

    return $employee;
}

/** @param array<string, string|int|bool|null> $overrides */
function daycorrShift(EmployeeProfile $employee, array $overrides = []): Shift
{
    return Shift::query()->create(array_merge([
        'employee_id' => (string) $employee->getKey(),
        'mode' => 'FIXED',
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '10:00:00',
        'end_time' => '17:00:00',
        'grace_minutes' => 10,
        'required_hours' => null,
        'break_start' => '12:00:00',
        'break_end' => '13:00:00',
        'break_minutes' => 60,
        'requires_break_punch' => true,
    ], $overrides));
}

/**
 * One stored punch at a Manila wall-clock instant.
 *
 * @param  array<string, mixed>  $overrides
 */
function daycorrPunch(EmployeeProfile $employee, ?WorkSite $site, string $type, string $manilaStamp, array $overrides = []): AttendanceLog
{
    $punch = new AttendanceLog;
    $punch->forceFill(array_merge([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => $site?->getKey(),
        'log_type' => $type,
        'source' => 'WEB_GPS',
        'latitude' => null,
        'longitude' => null,
        'distance_meters' => null,
        'accuracy_meters' => null,
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => LocalClock::instant($manilaStamp)->utc(),
    ], $overrides))->save();

    return $punch;
}

/* ── what the dialog is handed ───────────────────────────────────────────── */

it('returns the date\'s shift, the computed day, the stored remark, the prefilled slots and every replaceable punch with its location', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = daycorrSite();
    $employee = daycorrEmployee($site);
    daycorrShift($employee);
    $actor = daycorrActor(['EDTR'], allData: true);

    $in = daycorrPunch($employee, $site, 'TIME_IN', '2026-09-02T09:58:20', [
        'latitude' => 14.5996, 'longitude' => 120.9843, 'distance_meters' => 12.0, 'accuracy_meters' => 8.46,
    ]);
    $out = daycorrPunch($employee, $site, 'TIME_OUT', '2026-09-02T17:05:00', ['source' => 'MANUAL']);
    $remark = new AttendanceRemark;
    $remark->forceFill(['employee_id' => (string) $employee->getKey(), 'date' => '2026-09-02', 'note' => '  Client visit  '])->save();

    $view = app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-09-02');

    expect($view)->toBeInstanceOf(DayCorrectionView::class);
    assert($view !== null);
    expect($view->employee_id)->toBe((string) $employee->getKey())
        ->and($view->employee_name)->toBe('Liza Domingo')
        ->and($view->date)->toBe('2026-09-02')
        ->and($view->remark)->toBe('Client visit');

    // The shift for THAT date — a Wednesday on a Mon–Fri roster with a
    // scheduled one-hour break, so the four-slot DTR applies.
    expect($view->shift->rostered)->toBeTrue()
        ->and($view->shift->mode)->toBe('FIXED')
        ->and($view->shift->start_time)->toBe('10:00:00')
        ->and($view->shift->end_time)->toBe('17:00:00')
        ->and($view->shift->grace_minutes)->toBe(10)
        ->and($view->shift->break_start)->toBe('12:00:00')
        ->and($view->shift->break_minutes)->toBe(60)
        ->and($view->shift->uses_scheduled_break)->toBeTrue()
        ->and($view->shift->is_work_day)->toBeTrue()
        ->and($view->shift->overnight)->toBeFalse();

    // The engine's own verdict for the day, and the slots floored from it.
    expect($view->day)->toBeInstanceOf(DayRecord::class);
    assert($view->day !== null);
    expect($view->day->date)->toBe('2026-09-02')
        ->and($view->day->timeIn)->not->toBeNull()
        ->and($view->day->workedMinutes)->toBeGreaterThan(0.0);
    expect($view->slots)->toBe(['time_in' => '09:58', 'break_out' => '', 'break_in' => '', 'time_out' => '17:05']);

    // A fixed shift that ends the same day: nothing is ticked Next day and
    // nothing may be, no slot is borrowed and no punch is the previous date's.
    expect($view->next_day)->toBe(PunchSlots::SAME_DAY)
        ->and($view->next_day_allowed)->toBeFalse()
        ->and($view->borrowed)->toBe([])
        ->and($view->previous_day_punches)->toBe(0);

    // Every row a save would supersede, oldest first, with its location.
    expect($view->punches)->toHaveCount(2);
    $first = $view->punches[0];
    expect($first->id)->toBe((string) $in->getKey())
        ->and($first->log_type)->toBe('TIME_IN')
        ->and($first->source)->toBe('WEB_GPS')
        ->and($first->site_name)->toBe($site->name)
        ->and($first->slot)->toBe('09:58')
        ->and((float) $first->latitude)->toBe(14.5996)
        ->and((float) $first->longitude)->toBe(120.9843)
        ->and((float) $first->distance_meters)->toBe(12.0)
        ->and((float) $first->accuracy_meters)->toBe(8.46)
        ->and($first->is_geofenced)->toBeTrue()
        ->and($first->is_carried)->toBeFalse()
        ->and($first->in_dtr_slot)->toBeTrue()
        ->and($first->hand_entered)->toBeFalse()
        // UTC, as the column holds it: 09:58:20 Manila is 01:58:20 Z.
        ->and($first->logged_at->format('Y-m-d H:i:s'))->toBe('2026-09-02 01:58:20');
    $second = $view->punches[1];
    expect($second->id)->toBe((string) $out->getKey())
        ->and($second->source)->toBe('MANUAL')
        ->and($second->latitude)->toBeNull()
        ->and($second->accuracy_meters)->toBeNull()
        ->and($second->slot)->toBe('17:05')
        ->and($second->in_dtr_slot)->toBeTrue()
        ->and($second->hand_entered)->toBeTrue();
});

it('marks a MANUAL or CORRECTION punch, and only those, as hand entered', function (string $source, bool $handEntered) {
    // The dialog says "Manually Entered" instead of a place for exactly
    // these: an admin's MANUAL punch at the station, or a time a saved
    // correction put in place of the captured one.
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = daycorrSite();
    $employee = daycorrEmployee($site);
    daycorrShift($employee);
    $actor = daycorrActor(['EDTR'], allData: true);
    daycorrPunch($employee, $site, 'TIME_IN', '2026-09-02T09:58:00', ['source' => $source]);

    $view = app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-09-02');

    assert($view !== null);
    expect($view->punches)->toHaveCount(1)
        ->and($view->punches[0]->hand_entered)->toBe($handEntered);
})->with([
    'web gps' => ['WEB_GPS', false],
    'biometric' => ['BIOMETRIC', false],
    'an admin at the station' => ['MANUAL', true],
    'a saved correction' => [AttendanceLog::SOURCE_CORRECTION, true],
]);

it('lists the carried next-day slice of a night shift and marks it carried, stopping at the next day\'s first time in, and keeps it off the next date\'s list', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-04T12:00:00+08:00'));
    $site = daycorrSite();
    $employee = daycorrEmployee($site);
    daycorrShift($employee, ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
    $actor = daycorrActor(['EDTR'], allData: true);

    daycorrPunch($employee, $site, 'TIME_IN', '2026-09-02T22:00:15');
    $carried = daycorrPunch($employee, $site, 'TIME_OUT', '2026-09-03T06:01:40');
    $nextNight = daycorrPunch($employee, $site, 'TIME_IN', '2026-09-03T22:00:00');

    $view = app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-09-02');
    assert($view !== null);

    expect($view->shift->overnight)->toBeTrue()
        ->and($view->shift->uses_scheduled_break)->toBeFalse();
    expect(array_map(static fn (CorrectionPunchView $p): string => $p->id, $view->punches))->toHaveCount(2);
    expect($view->punches[0]->is_carried)->toBeFalse()
        ->and($view->punches[1]->id)->toBe((string) $carried->getKey())
        ->and($view->punches[1]->is_carried)->toBeTrue()
        ->and($view->punches[1]->slot)->toBe('06:01');

    // Prefilled as saved: the 06:01 Time Out is ticked Next day, which an
    // overnight shift allows.
    expect($view->slots)->toBe(['time_in' => '22:00', 'break_out' => '', 'break_in' => '', 'time_out' => '06:01'])
        ->and($view->next_day)->toBe(['time_in' => false, 'break_out' => false, 'break_in' => false, 'time_out' => true])
        ->and($view->next_day_allowed)->toBeTrue()
        ->and($view->previous_day_punches)->toBe(0);

    // The 06:01 Time Out sits on 09-03's calendar day, but it is the 09-02
    // night's. The 09-03 dialog does NOT list it — a save of 09-03 would
    // delete it and leave 09-02 INCOMPLETE — it only counts it and points at
    // the night it belongs to.
    $next = app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-09-03');
    assert($next !== null);
    expect(array_map(static fn (CorrectionPunchView $p): string => $p->id, $next->punches))->toBe([(string) $nextNight->getKey()])
        ->and($next->previous_day_punches)->toBe(1)
        ->and($next->borrowed)->toBe([])
        ->and($next->slots['time_out'])->toBe('');
});

it('falls back to the default shift and says so when the employee has no roster row', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $employee = daycorrEmployee(daycorrSite());
    $actor = daycorrActor(['EDTR'], allData: true);

    $view = app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-09-02');
    assert($view !== null);

    expect($view->shift->rostered)->toBeFalse()
        ->and($view->shift->start_time)->toBe('08:00:00')
        ->and($view->shift->end_time)->toBe('17:00:00')
        ->and($view->shift->grace_minutes)->toBe(15)
        ->and($view->shift->uses_scheduled_break)->toBeFalse()
        ->and($view->shift->is_work_day)->toBeTrue();
    // A Sunday is a rest day on the default Mon–Fri week.
    expect(app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-08-30')?->shift->is_work_day)->toBeFalse();
});

it('leaves the computed day null and the slots blank for a date after today', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $employee = daycorrEmployee(daycorrSite());
    $actor = daycorrActor(['EDTR'], allData: true);

    $view = app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-09-03');
    assert($view !== null);

    expect($view->day)->toBeNull()
        ->and($view->slots)->toBe(['time_in' => '', 'break_out' => '', 'break_in' => '', 'time_out' => ''])
        ->and($view->punches)->toBe([]);
});

/* ── who may read it ─────────────────────────────────────────────────────── */

it('refuses the dialog to an EDTR holder without all_data and to an unlinked account', function () {
    $employee = daycorrEmployee(daycorrSite());
    daycorrPunch($employee, null, 'TIME_IN', '2026-09-02T08:00:00');

    // The employee themselves, holding EDTR but not all_data: they may see
    // their DTR, but a correction is not theirs to make.
    $self = daycorrActor(['EDTR'], employeeId: (string) $employee->getKey());
    expect(fn () => app(DayCorrections::class)->view($self, (string) $employee->getKey(), '2026-09-02'))
        ->toThrow(HttpException::class);

    // An account with no employee link and no widened role.
    $unlinked = daycorrActor(['EDTR', 'PORTAL']);
    expect(fn () => app(DayCorrections::class)->view($unlinked, (string) $employee->getKey(), '2026-09-02'))
        ->toThrow(HttpException::class);

    // Nothing moved.
    expect(AttendanceLog::query()->count())->toBe(1);
});

it('returns null for an employee that does not exist and for a date that is not a calendar day', function () {
    $employee = daycorrEmployee(daycorrSite());
    $actor = daycorrActor(['EDTR'], allData: true);

    expect(app(DayCorrections::class)->view($actor, (string) Str::ulid(), '2026-09-02'))->toBeNull()
        ->and(app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-13-01'))->toBeNull()
        ->and(app(DayCorrections::class)->view($actor, (string) $employee->getKey(), 'not-a-date'))->toBeNull();
});

it('reads the view without issuing a query', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = daycorrSite();
    $employee = daycorrEmployee($site);
    $actor = daycorrActor(['EDTR'], allData: true);
    daycorrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:00:00');

    $view = app(DayCorrections::class)->view($actor, (string) $employee->getKey(), '2026-09-02');
    assert($view !== null);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $touched = [
        $view->punches[0]->site_name, $view->punches[0]->slot, $view->punches[0]->location_kind,
        $view->day?->status, $view->shift->start_time,
        // The date's place is resolved at build time, site name included.
        $view->location->kind, $view->location->site_name, $view->location->from_roster,
    ];
    DB::disableQueryLog();

    expect($touched[0])->toBe($site->name)
        ->and($touched[2])->toBe('ONSITE')
        ->and($touched[5])->toBe('ONSITE')
        ->and($touched[6])->toBe($site->name)
        ->and($touched[7])->toBeFalse()
        ->and(DB::getQueryLog())->toBe([]);
});

/* ── the day's overtime decision ─────────────────────────────────────────── */

it('hands the dialog the day\'s overtime and whether this actor may decide it', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T20:00:00+08:00'));
    $employee = daycorrEmployee();
    daycorrShift($employee, ['start_time' => '08:00:00', 'requires_break_punch' => false]);
    daycorrPunch($employee, null, 'TIME_IN', '2026-09-02T08:00:00');
    daycorrPunch($employee, null, 'TIME_OUT', '2026-09-02T18:45:00');
    $id = (string) $employee->getKey();

    $view = app(DayCorrections::class)->view(daycorrActor(['EDTR'], allData: true), $id, '2026-09-02');

    expect($view?->overtime?->status)->toBe('PENDING')
        ->and($view?->overtime?->computed_minutes)->toBe(105)
        ->and($view?->overtime?->ceiling)->toBe(105)
        ->and($view?->overtime?->decision)->toBeNull()
        ->and($view?->can_decide_overtime)->toBeTrue();

    // An all-data EDTR holder may also decide their OWN day.
    $own = app(DayCorrections::class)->view(daycorrActor(['EDTR'], allData: true, employeeId: $id), $id, '2026-09-02');
    expect($own?->overtime?->status)->toBe('PENDING')
        ->and($own?->can_decide_overtime)->toBeTrue();
});

it('hands the dialog no overtime for a day without any', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T20:00:00+08:00'));
    $employee = daycorrEmployee();
    daycorrShift($employee, ['start_time' => '08:00:00', 'requires_break_punch' => false]);
    daycorrPunch($employee, null, 'TIME_IN', '2026-09-02T08:00:00');
    daycorrPunch($employee, null, 'TIME_OUT', '2026-09-02T17:00:00');

    $view = app(DayCorrections::class)->view(daycorrActor(['EDTR'], allData: true), (string) $employee->getKey(), '2026-09-02');

    expect($view)->not->toBeNull()
        ->and($view?->overtime)->toBeNull();
});

/* ── the per-date seam ───────────────────────────────────────────────────── */

it('resolves the standing roster row for any date through the per-date seam', function () {
    $employee = daycorrEmployee(daycorrSite());
    $unrostered = daycorrEmployee(daycorrSite());
    daycorrShift($employee);

    $roster = app(ShiftRoster::class);
    $first = $roster->forDate((string) $employee->getKey(), '2026-09-01');
    $last = $roster->forDate((string) $employee->getKey(), '2026-09-30');

    expect($first)->not->toBeNull()
        ->and($first?->startTime)->toBe('10:00:00')
        ->and($first == $last)->toBeTrue();
    expect($roster->forDate((string) $unrostered->getKey(), '2026-09-01'))->toBeNull();
    expect(fn () => $roster->forDate((string) $employee->getKey(), '2026-13-01'))->toThrow(InvalidArgumentException::class);
});
