<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterLocation;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Organization\Application\Commands\DeleteSite;
use App\Modules\Organization\Application\SiteRetention;
use App\Modules\Timekeeping\Application\Commands\CorrectPunches;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\Commands\SaveRosterLocations;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Application\Queries\RosterLocations;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Weekly work locations (roster_locations, 2026_09_26_0001): a weekday may
 * change WHERE an employee works — from home, or at a named site — never
 * their hours. The single `shifts` row still decides the clock, so the DTR
 * and payroll engines read exactly what they read with no location rows.
 *
 * Four things are pinned here, all through the module's operations and
 * never through a screen:
 *
 *   - SaveRosterLocations re-validates the dialog's seven values, writes
 *     the week in one transaction, audits once with the sentence an
 *     operator can read back, and writes nothing on a no-op or a refusal.
 *   - The reads scope like the sibling `shifts` table (all_data + ROSTER
 *     sees everyone, a linked actor only themselves, an unlinked account
 *     nothing) and the station preview is gated like the punch itself.
 *   - RecordPunch judges a punch at the place of its WORK DATE: a WFH row
 *     accepts a punch from anywhere and keeps the fix, an ONSITE row fences
 *     against that weekday's site (and pins the kiosk to it), no row is the
 *     Assigned Worksite byte-for-byte — and an after-midnight Time Out is
 *     judged where the night STARTED.
 *   - Corrections stamp the corrected date's place, the dialog shows it,
 *     and a site a roster still names cannot be deleted.
 *
 * 2026-09-02 is a Wednesday (weekday 3); 2026-09-03 a Thursday (4).
 */

/** Makati Head Office's pin — the fenced site A. */
const RLOC_A = [14.5995, 120.9842];

/** A second fenced site, B, about ten kilometres from A. */
const RLOC_B = [14.6760, 121.0437];

/** A fix inside neither fence — kilometres from A and from B. */
const RLOC_FAR = [14.6500, 121.0500];

/** @param list<string> $modules */
function rlocRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Rloc '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * A signed-in account, and the Access built fresh from it — the container's
 * scoped instance is forgotten first, so a test that switches actors never
 * reads the previous one.
 *
 * @param  list<string>  $modules
 */
function rlocActor(array $modules, bool $allData = false, ?string $employeeId = null): Access
{
    $user = User::factory()->create([
        'role_id' => rlocRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array{0: float, 1: float} $pin */
function rlocSite(string $name, array $pin, bool $exempt = false): WorkSite
{
    return WorkSite::query()->create([
        'name' => $name,
        'latitude' => $pin[0],
        'longitude' => $pin[1],
        'radius_meters' => 150,
        'geofence_exempt' => $exempt,
    ]);
}

function rlocEmployee(?WorkSite $site = null, string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.random_int(10000, 99999),
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
        'work_site_id' => $site?->getKey(),
    ])->save();

    return $employee;
}

/**
 * The employee's one `shifts` row — the EdtrModuleTest shape. The day
 * scenarios start it at 08:00 so a punch pressed at 08:30 is inside the
 * one-hour early window; the overnight ones override the clock.
 *
 * @param  array<string, string|int|bool|null>  $overrides
 */
function rlocShift(EmployeeProfile $employee, array $overrides = []): Shift
{
    return Shift::query()->create(array_merge([
        'employee_id' => (string) $employee->getKey(),
        'mode' => 'FIXED',
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'grace_minutes' => 10,
        'required_hours' => null,
        'break_start' => '12:00:00',
        'break_end' => '13:00:00',
        'break_minutes' => 60,
        'requires_break_punch' => true,
    ], $overrides));
}

/** A 22:00–06:00 night with no scheduled break — the shape EdtrModuleTest's overnight cases use. */
function rlocNightShift(EmployeeProfile $employee): Shift
{
    return rlocShift($employee, [
        'start_time' => '22:00:00',
        'end_time' => '06:00:00',
        'break_start' => null,
        'break_end' => null,
        'break_minutes' => 0,
        'requires_break_punch' => false,
    ]);
}

/** One roster_locations row, straight through the model: WFH with no site, or ONSITE at one. */
function rlocRow(EmployeeProfile $employee, int $weekday, ?WorkSite $site): RosterLocation
{
    $row = new RosterLocation;
    $row->forceFill([
        'employee_id' => (string) $employee->getKey(),
        'weekday' => $weekday,
        'kind' => $site === null ? LocationKind::WFH : LocationKind::ONSITE,
        'site_id' => $site?->getKey(),
    ])->save();

    return $row;
}

/** Freezes the clock at a Manila wall-clock instant. */
function rlocFreeze(string $manila): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($manila, LocalClock::zone()));
}

/**
 * A live punch through the command, with the browser's fix as the station
 * passes it: a self-service punch never types coordinates, a kiosk punch
 * leaves the typed boxes blank so the fix stands in.
 *
 * @param  array{0: float, 1: float}  $fix
 */
function rlocPunch(Access $actor, EmployeeProfile $employee, string $logType, array $fix, bool $selfService = true, string $siteId = '', ?float $accuracy = 9.0): ?string
{
    $input = new PunchInput((string) $employee->getKey(), $siteId, $selfService ? 'WEB_GPS' : 'BIOMETRIC', $selfService, '', '');

    return app(RecordPunch::class)->handle($actor, $input, $logType, $fix[0], $fix[1], $accuracy);
}

/**
 * The dialog's draft exactly as the wire delivers it — decoded JSON, the
 * one way a client can shape the map — so the command's own validation,
 * not the test's types, is what a word for a key or a list for a value
 * runs into. Returned as whatever json_decode read, because that is all a
 * wire:model property ever guarantees.
 */
function rlocWire(string $json): mixed
{
    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<int, array{kind: string, site_id: string|null}> weekday => stored pair, ascending */
function rlocWeekOf(EmployeeProfile $employee): array
{
    // The week in effect today: a change adds a dated row and a return to
    // the Assigned Worksite is a row with no kind.
    $days = app(RosterVersions::class)->placesAsOf([(string) $employee->getKey()], LocalClock::todayKey())[(string) $employee->getKey()] ?? [];
    ksort($days);

    return array_map(static fn (RosterLocation $row): array => ['kind' => $row->kind, 'site_id' => $row->site_id], $days);
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/* |---| The command, called directly |---| */

it('saves one row per weekday, replaces the week and audits the exact sentence once', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    $employee = rlocEmployee($a);
    // A Friday that stands before the save and is cleared by it.
    rlocRow($employee, 5, $a);
    $actor = rlocActor([ModuleCode::ROSTER], allData: true);

    $message = app(SaveRosterLocations::class)->handle($actor, (string) $employee->getKey(), [
        1 => LocationKind::WFH,
        2 => (string) $a->getKey(),
        3 => (string) $b->getKey(),
        5 => LocationKind::DEFAULT,
    ]);

    expect($message)->toBe('Weekly locations saved for Liza Domingo.');
    expect(rlocWeekOf($employee))->toBe([
        1 => ['kind' => 'WFH', 'site_id' => null],
        2 => ['kind' => 'ONSITE', 'site_id' => (string) $a->getKey()],
        3 => ['kind' => 'ONSITE', 'site_id' => (string) $b->getKey()],
    ]);

    $audits = AuditLog::query()->where('action', 'ROSTER_LOCATIONS_SET')->get();
    expect($audits)->toHaveCount(1)
        ->and($audits[0]->entity)->toBe('roster_locations')
        ->and($audits[0]->entity_id)->toBe((string) $employee->getKey())
        ->and($audits[0]->details)->toBe('Liza Domingo · Mon WFH · Tue Makati Head Office · Wed Quezon Annex');
    expect(AuditLog::query()->count())->toBe(1);
});

it('refuses a ROSTER holder without all_data, writing no row and no audit', function () {
    $employee = rlocEmployee(rlocSite('Makati Head Office', RLOC_A));
    $actor = rlocActor([ModuleCode::ROSTER], allData: false, employeeId: (string) $employee->getKey());

    expect(fn () => app(SaveRosterLocations::class)->handle($actor, (string) $employee->getKey(), [1 => LocationKind::WFH]))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');

    expect(RosterLocation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses an unknown employee, an unknown site, a weekday outside the week and a non-string value, writing nothing', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $employee = rlocEmployee($a);
    $actor = rlocActor([ModuleCode::ROSTER], allData: true);
    $command = app(SaveRosterLocations::class);
    $id = (string) $employee->getKey();

    expect(fn () => $command->handle($actor, (string) Str::ulid(), [1 => LocationKind::WFH]))
        ->toThrow(RuntimeException::class, 'That employee no longer exists.');
    expect(fn () => $command->handle($actor, $id, [1 => (string) Str::ulid()]))
        ->toThrow(RuntimeException::class, 'That work site no longer exists.');
    expect(fn () => $command->handle($actor, $id, [7 => LocationKind::WFH]))
        ->toThrow(RuntimeException::class, 'Weekday must be 0 (Sunday) to 6 (Saturday).');
    expect(fn () => $command->handle($actor, $id, [-1 => LocationKind::WFH]))
        ->toThrow(RuntimeException::class, 'Weekday must be 0 (Sunday) to 6 (Saturday).');
    // Shapes only the wire can deliver: a word for a key, a number and a
    // list for a value. The command, not a type, is what refuses them.
    expect(fn () => $command->handle($actor, $id, rlocWire('{"mon":"WFH"}')))
        ->toThrow(RuntimeException::class, 'Weekday must be 0 (Sunday) to 6 (Saturday).');
    expect(fn () => $command->handle($actor, $id, rlocWire('{"1":3}')))
        ->toThrow(RuntimeException::class, 'Pick a location for Monday.');
    // One bad value refuses the whole map: the good Tuesday never lands.
    expect(fn () => $command->handle($actor, $id, rlocWire('{"2":"'.$a->getKey().'","3":["WFH"]}')))
        ->toThrow(RuntimeException::class, 'Pick a location for Wednesday.');

    expect(RosterLocation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('sends every weekday back to the assigned worksite on an all-DEFAULT save, keeping the rows, and audits the assigned-worksite sentence', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $employee = rlocEmployee($a);
    rlocRow($employee, 1, null);
    rlocRow($employee, 3, $a);
    $actor = rlocActor([ModuleCode::ROSTER], allData: true);

    $message = app(SaveRosterLocations::class)->handle($actor, (string) $employee->getKey(), array_fill(0, 7, LocationKind::DEFAULT));

    expect($message)->toBe('Weekly locations saved for Liza Domingo.');
    expect(rlocWeekOf($employee))->toBe([])
        ->and(RosterLocation::query()->count())->toBe(4)
        ->and(RosterLocation::query()->whereNull('kind')->pluck('weekday')->sort()->values()->all())->toBe([1, 3]);
    expect((string) AuditLog::query()->where('action', 'ROSTER_LOCATIONS_SET')->value('details'))
        ->toBe('Liza Domingo · assigned worksite every day');
});

it('writes no audit and returns the no-changes sentence on a save that changes nothing', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $employee = rlocEmployee($a);
    $monday = rlocRow($employee, 1, null);
    $tuesday = rlocRow($employee, 2, $a);
    $actor = rlocActor([ModuleCode::ROSTER], allData: true);
    $command = app(SaveRosterLocations::class);

    // The week as it stands, every other day DEFAULT — and an empty map.
    $same = [0 => 'DEFAULT', 1 => 'WFH', 2 => (string) $a->getKey(), 3 => 'DEFAULT', 4 => '', 5 => 'DEFAULT', 6 => 'DEFAULT'];
    expect($command->handle($actor, (string) $employee->getKey(), $same))->toBe("No changes to Liza Domingo's weekly locations.");
    expect($command->handle($actor, (string) $employee->getKey(), []))->toBe("No changes to Liza Domingo's weekly locations.");

    expect(AuditLog::query()->count())->toBe(0);
    expect(RosterLocation::query()->count())->toBe(2)
        ->and(RosterLocation::query()->whereKey($monday->getKey())->exists())->toBeTrue()
        ->and(RosterLocation::query()->whereKey($tuesday->getKey())->exists())->toBeTrue();
});

it('leaves weekdays absent from the map untouched', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    $employee = rlocEmployee($a);
    $monday = rlocRow($employee, 1, null);
    $wednesday = rlocRow($employee, 3, $b);
    $actor = rlocActor([ModuleCode::ROSTER], allData: true);

    app(SaveRosterLocations::class)->handle($actor, (string) $employee->getKey(), [2 => (string) $a->getKey()]);

    expect(rlocWeekOf($employee))->toBe([
        1 => ['kind' => 'WFH', 'site_id' => null],
        2 => ['kind' => 'ONSITE', 'site_id' => (string) $a->getKey()],
        3 => ['kind' => 'ONSITE', 'site_id' => (string) $b->getKey()],
    ]);
    // The same rows, not re-inserted.
    expect(RosterLocation::query()->whereKey($monday->getKey())->exists())->toBeTrue()
        ->and(RosterLocation::query()->whereKey($wednesday->getKey())->exists())->toBeTrue();
    // The audit reads the whole week that STANDS, not just the edit.
    expect((string) AuditLog::query()->where('action', 'ROSTER_LOCATIONS_SET')->value('details'))
        ->toBe('Liza Domingo · Mon WFH · Tue Makati Head Office · Wed Quezon Annex');
});

it('keeps a later row for the same weekday as history and reads the latest one in effect', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $employee = rlocEmployee($a);
    rlocRow($employee, 1, null);
    $later = rlocRow($employee, 1, $a);
    $later->forceFill(['effective_from' => '2026-10-05'])->save();

    // Before its date the baseline Monday applies; from it, the dated row.
    expect(RosterLocation::query()->count())->toBe(2)
        ->and(app(ShiftRoster::class)->locationForDate((string) $employee->getKey(), '2026-09-28')?->kind)->toBe('WFH')
        ->and(app(ShiftRoster::class)->locationForDate((string) $employee->getKey(), '2026-10-05')?->siteId)->toBe((string) $a->getKey());
});

/* |---| Scoping |---| */

it('lets an all_data ROSTER actor read every week and a linked actor only their own', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    $ramon = rlocEmployee($a, 'Ramon', 'Cruz');
    rlocRow($liza, 1, null);
    rlocRow($liza, 2, $a);
    rlocRow($ramon, 3, $a);
    $ids = [(string) $liza->getKey(), (string) $ramon->getKey()];

    $hr = rlocActor([ModuleCode::ROSTER], allData: true);
    $weeks = app(RosterLocations::class)->weeklyForEmployees($hr, $ids);
    expect(array_keys($weeks))->toBe($ids);
    expect(array_keys($weeks[(string) $liza->getKey()]))->toBe([1, 2]);
    $tuesday = $weeks[(string) $liza->getKey()][2];
    expect($tuesday->weekday)->toBe(2)
        ->and($tuesday->kind)->toBe('ONSITE')
        ->and($tuesday->site_id)->toBe((string) $a->getKey())
        ->and($tuesday->site_name)->toBe('Makati Head Office');
    expect($weeks[(string) $liza->getKey()][1]->site_name)->toBeNull();

    // Liza herself, holding ROSTER without all_data: her own week and nobody else's.
    $self = rlocActor([ModuleCode::ROSTER], allData: false, employeeId: (string) $liza->getKey());
    expect(array_keys(app(RosterLocations::class)->weeklyForEmployees($self, $ids)))->toBe([(string) $liza->getKey()]);
    expect(app(RosterLocations::class)->weekly($self, (string) $ramon->getKey()))->toBe([]);
    expect(array_keys(app(RosterLocations::class)->weekly($self, (string) $liza->getKey())))->toBe([1, 2]);
});

it('shows an unlinked ROSTER holder no week at all, through the impossible predicate', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    rlocRow($liza, 1, null);

    $unlinked = rlocActor([ModuleCode::ROSTER], allData: false);

    expect(app(RosterLocations::class)->weeklyForEmployees($unlinked, [(string) $liza->getKey()]))->toBe([]);
    expect(app(RosterLocations::class)->weekly($unlinked, (string) $liza->getKey()))->toBe([]);
    expect(strtolower(RosterLocation::query()->toEmployee($unlinked, 'employee_id', ModuleCode::ROSTER)->toRawSql()))
        ->toContain('1 = 0')
        ->not->toContain('is null');
});

it('lets a PORTAL-only linked employee read their own day at the station and nobody else\'s', function () {
    rlocFreeze('2026-09-02 10:00:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    $ramon = rlocEmployee($a, 'Ramon', 'Cruz');
    rlocShift($liza);
    rlocRow($liza, 3, null);
    rlocRow($ramon, 3, null);
    $self = rlocActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $liza->getKey());

    $today = app(RosterLocations::class)->forStation($self, (string) $liza->getKey());
    expect($today)->not->toBeNull()
        ->and($today?->date)->toBe('2026-09-02')
        ->and($today?->kind)->toBe('WFH')
        ->and($today?->site_id)->toBeNull()
        ->and($today?->site_name)->toBeNull()
        ->and($today?->from_roster)->toBeTrue();

    expect(app(RosterLocations::class)->forStation($self, (string) $ramon->getKey()))->toBeNull();
});

it('falls back to the Assigned Worksite at the station, with from_roster false, on a day with no row', function () {
    rlocFreeze('2026-09-03 10:00:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    // A Wednesday row only; today is Thursday.
    rlocRow($liza, 3, null);
    $self = rlocActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $liza->getKey());

    $today = app(RosterLocations::class)->forStation($self, (string) $liza->getKey());
    expect($today)->not->toBeNull()
        ->and($today?->date)->toBe('2026-09-03')
        ->and($today?->kind)->toBe('ONSITE')
        ->and($today?->site_id)->toBe((string) $a->getKey())
        ->and($today?->site_name)->toBe('Makati Head Office')
        ->and($today?->from_roster)->toBeFalse();
});

/* |---| The per-date seam |---| */

it('resolves the weekday row for a date through the per-date seam, null with no row, and throws on a bad date', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    $liza = rlocEmployee($a);
    rlocRow($liza, 3, $b);
    rlocRow($liza, 5, null);
    $roster = app(ShiftRoster::class);
    $id = (string) $liza->getKey();

    $wednesday = $roster->locationForDate($id, '2026-09-02');
    expect($wednesday)->not->toBeNull()
        ->and($wednesday?->kind)->toBe('ONSITE')
        ->and($wednesday?->siteId)->toBe((string) $b->getKey())
        ->and($wednesday?->fromRoster)->toBeTrue()
        ->and($wednesday?->isHome())->toBeFalse();

    // The following Wednesday reads the same row: it is keyed on the weekday.
    expect($roster->locationForDate($id, '2026-09-09')?->siteId)->toBe((string) $b->getKey());

    $friday = $roster->locationForDate($id, '2026-09-04');
    expect($friday?->isHome())->toBeTrue()
        ->and($friday?->siteId)->toBeNull();

    expect($roster->locationForDate($id, '2026-09-03'))->toBeNull();
    expect(fn () => $roster->locationForDate($id, '2026-13-01'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $roster->locationForDate($id, 'not-a-date'))->toThrow(InvalidArgumentException::class);
});

/* |---| Live punches |---| */

it('accepts a self-service punch from anywhere on a work-from-home day and keeps the fix', function () {
    rlocFreeze('2026-09-02 08:30:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    rlocRow($liza, 3, null);
    $self = rlocActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $liza->getKey());

    $message = rlocPunch($self, $liza, 'TIME_IN', RLOC_FAR);

    expect($message)->toBe('TIME IN recorded — working from home.');
    $row = AttendanceLog::query()->where('employee_id', (string) $liza->getKey())->firstOrFail();
    expect($row->log_type)->toBe('TIME_IN')
        ->and($row->source)->toBe('WEB_GPS')
        ->and($row->site_id)->toBeNull()
        ->and($row->location_kind)->toBe('WFH')
        ->and($row->latitude)->toBe(14.65)
        ->and($row->longitude)->toBe(121.05)
        ->and($row->distance_meters)->toBeNull()
        ->and($row->accuracy_meters)->toBe(9.0)
        ->and($row->is_geofenced)->toBeTrue();

    $audit = AuditLog::query()->where('action', 'PUNCH_TIME_IN')->firstOrFail();
    expect($audit->entity)->toBe('attendance_logs')
        ->and($audit->entity_id)->toBe((string) $row->getKey())
        ->and((string) $audit->details)->toContain('Liza Domingo @ Work from home · WEB_GPS')
        ->and(str_ends_with((string) $audit->details, '(±9 m)'))->toBeTrue();
});

it('refuses the same far-away fix on a fallback day against the assigned site', function () {
    rlocFreeze('2026-09-03 08:30:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    // Wednesday is from home; Thursday has no row.
    rlocRow($liza, 3, null);
    $self = rlocActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $liza->getKey());

    expect(fn () => rlocPunch($self, $liza, 'TIME_IN', RLOC_FAR))
        ->toThrow(RuntimeException::class, 'Time In is blocked: punch location is');
    try {
        rlocPunch($self, $liza, 'TIME_IN', RLOC_FAR);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('from Makati Head Office (radius 150 m).');
    }

    expect(AttendanceLog::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('judges an onsite roster day against that weekday\'s site, not the assigned one', function () {
    rlocFreeze('2026-09-02 08:30:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    rlocRow($liza, 3, $b);
    $self = rlocActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $liza->getKey());

    // Standing at the assigned site A — which is not where Wednesday is.
    try {
        rlocPunch($self, $liza, 'TIME_IN', RLOC_A);
        test()->fail('A fix at site A must be refused on a Wednesday rostered at site B.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toStartWith('Time In is blocked: punch location is')
            ->toContain('from Quezon Annex (radius 150 m).')
            ->not->toContain('Makati');
    }
    expect(AttendanceLog::query()->count())->toBe(0);

    // A few metres from B's pin: measured against B, stored against B.
    $message = rlocPunch($self, $liza, 'TIME_IN', [14.6761, 121.0437]);

    expect($message)->toBe('TIME IN recorded — inside Quezon Annex.');
    $row = AttendanceLog::query()->where('employee_id', (string) $liza->getKey())->firstOrFail();
    expect($row->site_id)->toBe((string) $b->getKey())
        ->and($row->location_kind)->toBe('ONSITE')
        ->and($row->distance_meters)->not->toBeNull()
        ->and($row->distance_meters)->toBeGreaterThan(0.0)
        ->and($row->distance_meters)->toBeLessThan(150.0)
        ->and($row->is_geofenced)->toBeTrue();
    expect((string) AuditLog::query()->where('action', 'PUNCH_TIME_IN')->value('details'))
        ->toContain('Liza Domingo @ Quezon Annex · WEB_GPS · in-bounds');
});

it('refuses a kiosk punch at a site that is not the weekday\'s site', function () {
    rlocFreeze('2026-09-02 08:30:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    rlocRow($liza, 3, $b);
    $kiosk = rlocActor([ModuleCode::EDTR], allData: true);

    expect(fn () => rlocPunch($kiosk, $liza, 'TIME_IN', RLOC_B, selfService: false, siteId: (string) $a->getKey()))
        ->toThrow(RuntimeException::class, 'Liza Domingo works at Quezon Annex on Wednesdays.');

    expect(AttendanceLog::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    // The weekday's own site is accepted, as a BIOMETRIC row against B.
    rlocPunch($kiosk, $liza, 'TIME_IN', RLOC_B, selfService: false, siteId: (string) $b->getKey());
    $row = AttendanceLog::query()->firstOrFail();
    expect($row->site_id)->toBe((string) $b->getKey())
        ->and($row->location_kind)->toBe('ONSITE')
        ->and($row->source)->toBe('BIOMETRIC');
});

it('ignores the kiosk\'s site pick on a work-from-home day', function () {
    rlocFreeze('2026-09-02 08:30:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    rlocRow($liza, 3, null);
    $kiosk = rlocActor([ModuleCode::EDTR], allData: true);

    $message = rlocPunch($kiosk, $liza, 'TIME_IN', RLOC_FAR, selfService: false, siteId: (string) $a->getKey(), accuracy: null);

    expect($message)->toBe('TIME IN recorded — working from home.');
    $row = AttendanceLog::query()->where('employee_id', (string) $liza->getKey())->firstOrFail();
    expect($row->site_id)->toBeNull()
        ->and($row->location_kind)->toBe('WFH')
        ->and($row->source)->toBe('BIOMETRIC')
        ->and($row->distance_meters)->toBeNull()
        ->and($row->accuracy_meters)->toBeNull()
        ->and($row->is_geofenced)->toBeTrue();
    // No reading to print: the sentence ends at the source.
    expect((string) AuditLog::query()->where('action', 'PUNCH_TIME_IN')->value('details'))
        ->toBe('Liza Domingo @ Work from home · BIOMETRIC');
});

it('keeps the assigned-site kiosk sentence byte-identical on a fallback day', function () {
    rlocFreeze('2026-09-03 08:30:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    // Wednesday at B; Thursday has no row, so it is the Assigned Worksite A.
    rlocRow($liza, 3, $b);
    $kiosk = rlocActor([ModuleCode::EDTR], allData: true);

    expect(fn () => rlocPunch($kiosk, $liza, 'TIME_IN', RLOC_A, selfService: false, siteId: (string) $b->getKey()))
        ->toThrow(RuntimeException::class, 'Liza Domingo is assigned to Makati Head Office.');
    expect(AttendanceLog::query()->count())->toBe(0);

    $message = rlocPunch($kiosk, $liza, 'TIME_IN', RLOC_A, selfService: false, siteId: (string) $a->getKey(), accuracy: null);
    expect($message)->toBe('TIME IN recorded — inside Makati Head Office.');
    $row = AttendanceLog::query()->firstOrFail();
    expect($row->site_id)->toBe((string) $a->getKey())
        ->and($row->location_kind)->toBe('ONSITE')
        ->and($row->distance_meters)->toBe(0.0);
    expect((string) AuditLog::query()->where('action', 'PUNCH_TIME_IN')->value('details'))
        ->toBe('Liza Domingo @ Makati Head Office · BIOMETRIC · in-bounds (0 m)');
});

it('lets an employee with no worksite punch on a work-from-home day and refuses them on a fallback day', function () {
    $liza = rlocEmployee(null);
    rlocShift($liza);
    rlocRow($liza, 3, null);
    $self = rlocActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $liza->getKey());

    rlocFreeze('2026-09-02 08:30:00');
    expect(rlocPunch($self, $liza, 'TIME_IN', RLOC_FAR))->toBe('TIME IN recorded — working from home.');
    expect(AttendanceLog::query()->where('location_kind', 'WFH')->count())->toBe(1);

    rlocFreeze('2026-09-03 08:30:00');
    expect(fn () => rlocPunch($self, $liza, 'TIME_IN', RLOC_FAR))
        ->toThrow(RuntimeException::class, 'Liza Domingo has no assigned worksite. Assign one in Employee Records before punching.');
    expect(AttendanceLog::query()->count())->toBe(1);
});

it('judges an after-midnight Time Out at the place the night started — from home', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    rlocNightShift($liza);
    // Wednesday from home, Thursday on site: the 05:30 Time Out is Wednesday's.
    rlocRow($liza, 3, null);
    rlocRow($liza, 4, $a);
    $self = rlocActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $liza->getKey());

    rlocFreeze('2026-09-02 22:00:00');
    expect(rlocPunch($self, $liza, 'TIME_IN', RLOC_FAR))->toBe('TIME IN recorded — working from home.');

    rlocFreeze('2026-09-03 05:30:00');
    expect(rlocPunch($self, $liza, 'TIME_OUT', RLOC_FAR))->toBe('TIME OUT recorded — working from home.');

    $rows = AttendanceLog::query()->orderBy('logged_at')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->log_type)->toBe('TIME_IN')
        ->and($rows[0]->location_kind)->toBe('WFH')
        ->and($rows[1]->log_type)->toBe('TIME_OUT')
        ->and($rows[1]->location_kind)->toBe('WFH')
        ->and($rows[1]->site_id)->toBeNull()
        ->and($rows[1]->distance_meters)->toBeNull()
        ->and($rows[1]->is_geofenced)->toBeTrue();
});

it('keeps an after-midnight Time Out inside the fence of the site the night started at', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $liza = rlocEmployee($a);
    rlocNightShift($liza);
    // Wednesday on site, Thursday from home: the 05:30 Time Out is still fenced.
    rlocRow($liza, 3, $a);
    rlocRow($liza, 4, null);
    $self = rlocActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $liza->getKey());

    rlocFreeze('2026-09-02 22:00:00');
    expect(rlocPunch($self, $liza, 'TIME_IN', RLOC_A))->toBe('TIME IN recorded — inside Makati Head Office.');
    expect(AttendanceLog::query()->firstOrFail()->location_kind)->toBe('ONSITE');

    rlocFreeze('2026-09-03 05:30:00');
    expect(fn () => rlocPunch($self, $liza, 'TIME_OUT', RLOC_FAR))
        ->toThrow(RuntimeException::class, 'Time Out is blocked: punch location is');
    try {
        rlocPunch($self, $liza, 'TIME_OUT', RLOC_FAR);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('from Makati Head Office (radius 150 m).');
    }

    expect(AttendanceLog::query()->count())->toBe(1)
        ->and(AttendanceLog::query()->where('log_type', 'TIME_OUT')->count())->toBe(0);
});

/* |---| Corrections |---| */

it('stamps corrected punches with the corrected date\'s place', function () {
    rlocFreeze('2026-09-05 12:00:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    rlocRow($liza, 3, null);
    rlocRow($liza, 4, $b);
    $actor = rlocActor([ModuleCode::EDTR], allData: true);
    $id = (string) $liza->getKey();

    CorrectPunches::apply($actor, $id, '2026-09-02', ['time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00'], PunchSlots::SAME_DAY, null);
    CorrectPunches::apply($actor, $id, '2026-09-03', ['time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00'], PunchSlots::SAME_DAY, null);
    // Friday has no row: the correction stamps the Assigned Worksite.
    CorrectPunches::apply($actor, $id, '2026-09-04', ['time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00'], PunchSlots::SAME_DAY, null);

    $byDay = AttendanceLog::query()->orderBy('logged_at')->get()
        ->groupBy(static fn (AttendanceLog $row): string => LocalClock::dayKey($row->logged_at));
    expect($byDay->keys()->all())->toBe(['2026-09-02', '2026-09-03', '2026-09-04']);

    foreach ($byDay['2026-09-02'] as $row) {
        expect($row->source)->toBe(AttendanceLog::SOURCE_CORRECTION)
            ->and($row->site_id)->toBeNull()
            ->and($row->location_kind)->toBe('WFH')
            ->and($row->is_geofenced)->toBeTrue();
    }
    foreach ($byDay['2026-09-03'] as $row) {
        expect($row->site_id)->toBe((string) $b->getKey())
            ->and($row->location_kind)->toBe('ONSITE');
    }
    foreach ($byDay['2026-09-04'] as $row) {
        expect($row->site_id)->toBe((string) $a->getKey())
            ->and($row->location_kind)->toBe('ONSITE');
    }
    expect(AttendanceLog::query()->count())->toBe(6);
});

it('exposes the corrected date\'s location to the dialog', function () {
    rlocFreeze('2026-09-05 12:00:00');
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    $liza = rlocEmployee($a);
    rlocShift($liza);
    rlocRow($liza, 3, null);
    rlocRow($liza, 4, $b);
    $actor = rlocActor([ModuleCode::EDTR], allData: true);
    $id = (string) $liza->getKey();

    $wednesday = app(DayCorrections::class)->view($actor, $id, '2026-09-02');
    expect($wednesday?->location->date)->toBe('2026-09-02')
        ->and($wednesday?->location->kind)->toBe('WFH')
        ->and($wednesday?->location->site_id)->toBeNull()
        ->and($wednesday?->location->site_name)->toBeNull()
        ->and($wednesday?->location->from_roster)->toBeTrue();

    $thursday = app(DayCorrections::class)->view($actor, $id, '2026-09-03');
    expect($thursday?->location->kind)->toBe('ONSITE')
        ->and($thursday?->location->site_id)->toBe((string) $b->getKey())
        ->and($thursday?->location->site_name)->toBe('Quezon Annex')
        ->and($thursday?->location->from_roster)->toBeTrue();

    $friday = app(DayCorrections::class)->view($actor, $id, '2026-09-04');
    expect($friday?->location->kind)->toBe('ONSITE')
        ->and($friday?->location->site_id)->toBe((string) $a->getKey())
        ->and($friday?->location->site_name)->toBe('Makati Head Office')
        ->and($friday?->location->from_roster)->toBeFalse();
});

/* |---| Retention |---| */

it('keeps a work site any weekly schedule named, as schedule history', function () {
    $a = rlocSite('Makati Head Office', RLOC_A);
    $b = rlocSite('Quezon Annex', RLOC_B);
    // Assigned to A, rostered at B on Wednesdays: only the roster names B.
    $liza = rlocEmployee($a);
    $row = rlocRow($liza, 3, $b);
    $actor = rlocActor([ModuleCode::RECORDS], allData: true);
    $sentence = 'This work site is in employee schedule history and must remain. Make it inactive instead.';

    expect(app(SiteRetention::class)->refusal((string) $b->getKey()))->toBe($sentence);
    expect(fn () => app(DeleteSite::class)->handle($actor, (string) $b->getKey()))
        ->toThrow(RuntimeException::class, $sentence);
    expect(WorkSite::query()->whereKey($b->getKey())->exists())->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);

    // Sending the week back to the Assigned Worksite keeps the row that
    // named B as history, so B still stays.
    $ramon = rlocEmployee($a, 'Ramon', 'Cruz');
    rlocRow($ramon, 1, $b);
    app(SaveRosterLocations::class)->handle(rlocActor([ModuleCode::ROSTER], allData: true), (string) $ramon->getKey(), [1 => LocationKind::DEFAULT]);
    expect(rlocWeekOf($ramon))->toBe([])
        ->and(app(SiteRetention::class)->refusal((string) $b->getKey()))->toBe($sentence);

    // A recorded punch there is history, and history is checked first.
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $liza->getKey(),
        'site_id' => (string) $b->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => LocalClock::instant('2026-09-02T08:00:00')->utc(),
    ])->save();
    expect(app(SiteRetention::class)->refusal((string) $b->getKey()))
        ->toBe('This work site has recorded punches and must remain in attendance history. Make it inactive instead.');
    $punch->delete();

    // Only a site no schedule row ever named can go.
    $row->delete();
    RosterLocation::query()->where('employee_id', (string) $ramon->getKey())->delete();
    expect(app(SiteRetention::class)->refusal((string) $b->getKey()))->toBeNull();
    expect(app(DeleteSite::class)->handle($actor, (string) $b->getKey()))->toBe('Quezon Annex');
    expect(WorkSite::query()->whereKey($b->getKey())->exists())->toBeFalse();
});
