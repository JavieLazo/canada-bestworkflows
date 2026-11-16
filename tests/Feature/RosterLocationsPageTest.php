<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Edtr\EdtrPage;
use App\Livewire\Edtr\LiveFeed;
use App\Livewire\Edtr\PunchStation;
use App\Livewire\Portal\MyPunches;
use App\Livewire\Roster\EmployeeSchedulePage;
use App\Livewire\Roster\RosterPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterLocation;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * The SCREENS of the weekly work locations (roster_locations,
 * 2026_09_26_0001): the place each working date of an employee's month
 * shows on the Shift Roster and that month's "Default schedule" dialog,
 * the punch station's preview of today's place, the two punch feeds and
 * the eDTR correction dialog. The module operations they call are pinned
 * in RosterLocationsTest; here the invariant is that every screen shows
 * exactly what the operations resolve and never writes around them — the
 * dialog's one write goes through SaveRosterWeek behind a fresh Access, the
 * month's employee id is #[Locked], and the draft is shaped before the
 * command judges it.
 *
 * 2026-09-02 is a Wednesday (weekday 3).
 */

/** @param list<string> $modules */
function rlocpageRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'RlocPage '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * Signs in a fresh account; the container's scoped Access is forgotten so a
 * test that switches actors never reads the previous one.
 *
 * @param  list<string>  $modules
 */
function rlocpageActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => rlocpageRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function rlocpageSite(string $name, bool $exempt = false): WorkSite
{
    return WorkSite::query()->create([
        'name' => $name,
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => $exempt,
    ]);
}

function rlocpageEmployee(?WorkSite $site, string $first, string $last): EmployeeProfile
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
 * The employee's one `shifts` row — the EdtrModuleTest shape, 10:00–17:00.
 *
 * @param  array<string, string|int|bool|list<int>|null>  $overrides
 */
function rlocpageShift(EmployeeProfile $employee, array $overrides = []): Shift
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

/** One roster_locations row: WFH with no site, or ONSITE at one. */
function rlocpageRow(EmployeeProfile $employee, int $weekday, ?WorkSite $site): RosterLocation
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

/**
 * The week in effect today, weekday => [kind, site_id]: a save adds dated
 * rows and a return to the Assigned Worksite is a row with no kind, so the
 * table itself is history.
 *
 * @return array<int, array{0: string|null, 1: string|null}>
 */
function rlocpageWeek(EmployeeProfile $employee): array
{
    $days = app(RosterVersions::class)->placesAsOf([(string) $employee->getKey()], ManilaClock::todayKey())[(string) $employee->getKey()] ?? [];
    ksort($days);

    return array_map(static fn (RosterLocation $row): array => [$row->kind, $row->site_id], $days);
}

/**
 * One stored punch at a Manila wall-clock instant, its place as the kind
 * says: a WFH punch carries no site, an onsite one its site.
 */
function rlocpagePunch(EmployeeProfile $employee, ?WorkSite $site, string $type, string $manilaStamp): AttendanceLog
{
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => $site?->getKey(),
        'location_kind' => $site === null ? LocationKind::WFH : LocationKind::ONSITE,
        'log_type' => $type,
        'source' => 'WEB_GPS',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'distance_meters' => $site === null ? null : 12.0,
        'accuracy_meters' => 8.0,
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant($manilaStamp)->utc(),
    ])->save();

    return $punch;
}

/**
 * The employee's month on the Shift Roster, as the signed-in actor.
 *
 * @return Testable<EmployeeSchedulePage>
 */
function rlocpageMonth(EmployeeProfile $employee): Testable
{
    return Livewire::test(EmployeeSchedulePage::class, ['employee' => (string) $employee->getKey()]);
}

/** One date's box on the month's calendar, so a neighbouring date or a dialog cannot mask a wrong place. */
function rlocpageDayHtml(string $html, string $date): string
{
    $needle = 'data-date="'.$date.'"';
    expect($html)->toContain($needle);

    return Str::before(Str::after($html, $needle), '</button>');
}

/** The Default schedule dialog's seven rows, from their wire:key to the Save button. */
function rlocpageDialogHtml(string $html, EmployeeProfile $employee): string
{
    $needle = 'wire:key="default-schedule-'.$employee->getKey().'"';
    expect($html)->toContain($needle);

    return Str::before(Str::after($html, $needle), 'Save Default Schedule');
}

/** The correction dialog from its heading to the recorded-punches fold — the header (status, Shift, Break, Location), the tab strip, the notices, the slots and the remark — without the punch rows that follow. */
function rlocpageFactsHtml(string $html): string
{
    return Str::before(Str::after($html, 'correction-heading'), 'Recorded Punches');
}

/** Freezes the clock at a Manila wall-clock instant. */
function rlocpageFreeze(string $manila): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($manila, ManilaClock::TZ));
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/* ── each date's place on the month ──────────────────────────────────────── */

it('shows each working date of the month at that weekday’s place, Work from home, or the Assigned Worksite by name, and No worksite when none is set', function () {
    rlocpageFreeze('2026-09-02 05:00:00');
    $a = rlocpageSite('Makati Head Office');
    $b = rlocpageSite('Quezon Annex');
    $mixed = rlocpageEmployee($a, 'Ana', 'Reyes');
    $bare = rlocpageEmployee($a, 'Ben', 'Torres');
    $unplaced = rlocpageEmployee(null, 'Cara', 'Uy');
    foreach ([$mixed, $bare, $unplaced] as $employee) {
        rlocpageShift($employee);
    }
    rlocpageRow($mixed, 1, $a);
    rlocpageRow($mixed, 2, $a);
    rlocpageRow($mixed, 3, $a);
    rlocpageRow($mixed, 4, null);
    rlocpageRow($mixed, 5, $b);
    rlocpageActor([ModuleCode::ROSTER], allData: true);

    // Thursday Sep 3 at home, Friday Sep 4 at the annex, Monday Sep 7 at the
    // site the weekday names; Saturday Sep 5 rests and names no place.
    $html = rlocpageMonth($mixed)->html();
    expect(rlocpageDayHtml($html, '2026-09-03'))->toContain('Work from home')->not->toContain('Makati Head Office');
    expect(rlocpageDayHtml($html, '2026-09-04'))->toContain('Quezon Annex')->not->toContain('Makati Head Office');
    expect(rlocpageDayHtml($html, '2026-09-07'))->toContain('Makati Head Office')->not->toContain('Work from home');
    expect(rlocpageDayHtml($html, '2026-09-05'))->toContain('Off')
        ->not->toContain('Makati Head Office')
        ->not->toContain('Quezon Annex')
        ->not->toContain('Work from home');

    // No weekday rows: every working date is the Assigned Worksite, by name.
    $html = rlocpageMonth($bare)->html();
    expect(rlocpageDayHtml($html, '2026-09-03'))->toContain('Makati Head Office')->not->toContain('Work from home');
    expect(rlocpageDayHtml($html, '2026-09-04'))->toContain('Makati Head Office')->not->toContain('Quezon Annex');

    $html = rlocpageMonth($unplaced)->html();
    expect(rlocpageDayHtml($html, '2026-09-03'))->toContain('No worksite')->not->toContain('Makati Head Office');
});

/* ── the Default schedule dialog ────────────────────────────────────────── */

it('opens the Default schedule dialog seeded from the stored rows, for an all_data ROSTER holder only', function () {
    $a = rlocpageSite('Makati Head Office');
    $employee = rlocpageEmployee($a, 'Ana', 'Reyes');
    $colleague = rlocpageEmployee($a, 'Ben', 'Torres');
    rlocpageRow($employee, 1, null);
    rlocpageRow($employee, 2, $a);
    rlocpageActor([ModuleCode::ROSTER], allData: true);

    rlocpageMonth($employee)
        ->call('openDefaultSchedule')
        ->assertSet('employeeId', (string) $employee->getKey())
        ->assertSet('locationDays.1', LocationKind::WFH)
        ->assertSet('locationDays.2', (string) $a->getKey())
        ->assertSet('locationDays.4', LocationKind::DEFAULT)
        ->assertDispatched('modal-show', name: 'default-schedule')
        ->assertSeeHtml('id="default-schedule-heading"');

    // Opening writes nothing.
    expect(RosterLocation::query()->count())->toBe(2);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'ROSTER_LOCATIONS_SET']);

    // A ROSTER holder without all_data sees only their own row: a colleague's
    // month is not found, and their own opens no dialog.
    rlocpageActor([ModuleCode::ROSTER], allData: false, employeeId: (string) $colleague->getKey());

    rlocpageMonth($employee)->assertNotFound();
    rlocpageMonth($colleague)
        ->call('openDefaultSchedule')
        ->assertSet('locationDays', [])
        ->assertSet('shiftDays', [])
        ->assertNotDispatched('modal-show');
});

it('saves the dialog through the command, closes it, repaints the month and writes one audit row', function () {
    rlocpageFreeze('2026-09-02 05:00:00');
    $a = rlocpageSite('Makati Head Office');
    $b = rlocpageSite('Quezon Annex');
    $employee = rlocpageEmployee($a, 'Ana', 'Reyes');
    rlocpageShift($employee);
    // A Friday that stands before the save and is cleared by it.
    rlocpageRow($employee, 5, $a);
    rlocpageActor([ModuleCode::ROSTER], allData: true);

    $page = rlocpageMonth($employee)
        ->call('openDefaultSchedule')
        ->assertSet('locationDays.5', (string) $a->getKey())
        ->set('locationDays.1', LocationKind::WFH)
        ->set('locationDays.3', (string) $b->getKey())
        ->set('locationDays.5', LocationKind::DEFAULT)
        ->call('saveDefaultSchedule')
        ->assertSet('locationDays', [])
        ->assertSet('shiftDays', [])
        ->assertDispatched('modal-close', name: 'default-schedule');

    // The month repaints from the stored week in the same response, from
    // today (before today's 10:00 shift starts).
    $html = $page->html();
    expect(rlocpageDayHtml($html, '2026-09-07'))->toContain('Work from home');
    expect(rlocpageDayHtml($html, '2026-09-02'))->toContain('Quezon Annex')->not->toContain('Makati Head Office');
    expect(rlocpageDayHtml($html, '2026-09-04'))->toContain('Makati Head Office')->not->toContain('Quezon Annex');

    $week = rlocpageWeek($employee);
    expect($week)->toBe([
        1 => [LocationKind::WFH, null],
        3 => [LocationKind::ONSITE, (string) $b->getKey()],
    ]);

    $audits = AuditLog::query()->where('action', 'ROSTER_LOCATIONS_SET')->get();
    expect($audits)->toHaveCount(1)
        ->and($audits->first()->entity)->toBe('roster_locations')
        ->and($audits->first()->entity_id)->toBe((string) $employee->getKey())
        ->and($audits->first()->details)->toBe('Ana Reyes · Mon WFH · Wed Quezon Annex');
});

it('refuses the dialog save without all_data, writes nothing and keeps the draft', function () {
    rlocpageFreeze('2026-09-02 05:00:00');
    $a = rlocpageSite('Makati Head Office');
    $own = rlocpageEmployee($a, 'Ana', 'Reyes');
    rlocpageActor([ModuleCode::ROSTER], allData: false, employeeId: (string) $own->getKey());

    // The own month is visible but opens no dialog; a draft written straight
    // to the component is refused at the page's fresh Access check and
    // again inside the command.
    rlocpageMonth($own)
        ->call('openDefaultSchedule')
        ->assertNotDispatched('modal-show')
        ->set('locationDays.1', LocationKind::WFH)
        ->set('locationDays.2', (string) $a->getKey())
        ->call('saveDefaultSchedule')
        ->assertSet('locationDays.1', LocationKind::WFH)
        ->assertSet('locationDays.2', (string) $a->getKey())
        ->assertNotDispatched('modal-close');

    expect(RosterLocation::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'ROSTER_LOCATIONS_SET']);
});

it('does nothing on a cold saveDefaultSchedule and treats junk draft values as DEFAULT', function () {
    rlocpageFreeze('2026-09-02 05:00:00');
    $a = rlocpageSite('Makati Head Office');
    $employee = rlocpageEmployee($a, 'Ana', 'Reyes');
    // A shift assigned before, so the week's days may stay on it.
    ScheduleFixture::standard($employee);
    rlocpageRow($employee, 1, null);
    rlocpageActor([ModuleCode::ROSTER], allData: true);

    // Cold: nothing staged, nothing written, no dialog to close.
    rlocpageMonth($employee)
        ->call('saveDefaultSchedule')
        ->assertNotDispatched('modal-close');

    expect(rlocpageWeek($employee))->toBe([1 => [LocationKind::WFH, null]]);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'ROSTER_LOCATIONS_SET']);

    // Junk in the draft: a weekday outside the week is never read, and a
    // non-string entry reads DEFAULT — so Monday goes back to the Assigned
    // Worksite, not WFH, and Tuesday lands at the site.
    rlocpageMonth($employee)
        ->call('openDefaultSchedule')
        ->set('locationDays', [9 => LocationKind::WFH, 1 => ['x'], 2 => (string) $a->getKey()])
        ->call('saveDefaultSchedule')
        ->assertSet('shiftDays', [])
        ->assertDispatched('modal-close', name: 'default-schedule');

    $week = rlocpageWeek($employee);
    expect($week)->toBe([2 => [LocationKind::ONSITE, (string) $a->getKey()]]);
    expect(AuditLog::query()->where('action', 'ROSTER_LOCATIONS_SET')->count())->toBe(1);
});

it('marks rest days from the employee shift in the dialog', function () {
    $a = rlocpageSite('Makati Head Office');
    $employee = rlocpageEmployee($a, 'Ana', 'Reyes');
    rlocpageShift($employee, ['work_days' => [1, 2, 3]]);
    rlocpageActor([ModuleCode::ROSTER], allData: true);

    $page = rlocpageMonth($employee)->call('openDefaultSchedule');

    $dialog = rlocpageDialogHtml($page->html(), $employee);
    // Thu, Fri, Sat and Sun rest; Mon–Wed work. Every day carries the hint so
    // the browser can show it as days change; only the resting days are
    // drawn shown, the working days' are hidden.
    preg_match_all('~data-rest-day-hint x-show="restsOn\((\d)\)"(\s+style="display: none;?")?~', $dialog, $hints, PREG_SET_ORDER);
    $shown = array_values(array_map(static fn (array $hint): string => $hint[1], array_filter($hints, static fn (array $hint): bool => ($hint[2] ?? '') === '')));
    expect(count($hints))->toBe(7)
        ->and($shown)->toBe(['4', '5', '6', '0']);
});

it('gives a viewer without all_data a month that changes nothing, and an all_data ROSTER holder the Default schedule and the date picks', function () {
    rlocpageFreeze('2026-09-02 05:00:00');
    $a = rlocpageSite('Makati Head Office');
    $own = rlocpageEmployee($a, 'Ana', 'Reyes');
    rlocpageShift($own);
    $open = 'wire:click="openDefaultSchedule"';

    rlocpageActor([ModuleCode::ROSTER], allData: false, employeeId: (string) $own->getKey());

    // The week table never writes, for anyone.
    Livewire::test(RosterPage::class)
        ->assertSee('Ana Reyes')
        ->assertDontSeeHtml('openDefaultSchedule')
        ->assertDontSeeHtml('pick(');

    // The month reads, with every date locked and no picks admitted.
    rlocpageMonth($own)
        ->assertSee('Ana Reyes')
        ->assertDontSeeHtml($open)
        ->assertDontSee('Default Schedule')
        ->call('pick', '2026-09-03')
        ->assertSet('picked', [])
        ->assertDontSeeHtml('data-picked-card');
    // A locked date's button is disabled and has no pick handler.
    $disabled = '~<button\s+type="button"\s+disabled\s[^>]*data-date="2026-09-03"~s';
    expect(rlocpageMonth($own)->html())->toMatch($disabled);

    rlocpageActor([ModuleCode::ROSTER], allData: true);

    expect(rlocpageMonth($own)->html())->not->toMatch($disabled);
    rlocpageMonth($own)
        ->assertSeeHtml($open)
        ->assertSee('Default Schedule')
        ->call('pick', '2026-09-03')
        ->assertSet('picked', ['2026-09-03'])
        ->assertSeeHtml('data-picked-card');
});

it('keeps the month’s employeeId locked against a client write', function () {
    $a = rlocpageSite('Makati Head Office');
    $employee = rlocpageEmployee($a, 'Ana', 'Reyes');
    $colleague = rlocpageEmployee($a, 'Ben', 'Torres');
    rlocpageActor([ModuleCode::ROSTER], allData: true);

    expect(fn () => rlocpageMonth($employee)->set('employeeId', (string) $colleague->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

/* ── the punch station's preview of today ────────────────────────────────── */

it('previews the day at the punch station: work from home, that weekday site, or no worksite at all', function () {
    rlocpageFreeze('2026-09-02 10:30:00');
    $a = rlocpageSite('Makati Head Office');
    $b = rlocpageSite('Quezon Annex');
    $home = rlocpageEmployee($a, 'Ana', 'Reyes');
    $onsite = rlocpageEmployee($a, 'Ben', 'Torres');
    $none = rlocpageEmployee(null, 'Cid', 'Uy');
    foreach ([$home, $onsite, $none] as $employee) {
        rlocpageShift($employee);
    }
    // Wednesday: Ana at home, Ben at B although assigned to A; Cid has neither.
    rlocpageRow($home, 3, null);
    rlocpageRow($onsite, 3, $b);
    rlocpageActor([ModuleCode::EDTR], allData: true);

    $station = Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $home->getKey())
        ->assertSet('siteId', '')
        ->assertSeeHtml('value="Work from home"')
        ->assertSee('no geofence');
    expect($station->viewData('punchDisabled'))->toBeFalse()
        ->and($station->viewData('wfhToday'))->toBeTrue();

    $station = Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $onsite->getKey())
        ->assertSet('siteId', (string) $b->getKey())
        ->assertSee('Quezon Annex')
        ->assertDontSee('Makati Head Office')
        ->assertDontSee('Work from home');
    expect($station->viewData('punchDisabled'))->toBeFalse()
        ->and($station->viewData('wfhToday'))->toBeFalse();

    $station = Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $none->getKey())
        ->assertSee('No worksite assigned yet')
        ->assertDontSee('Work from home');
    expect($station->viewData('punchDisabled'))->toBeTrue();
});

it('keeps the portal station pinned to the actor own week even for an all_data holder', function () {
    rlocpageFreeze('2026-09-02 10:30:00');
    $a = rlocpageSite('Makati Head Office');
    $b = rlocpageSite('Quezon Annex');
    $mine = rlocpageEmployee($a, 'Ana', 'Reyes');
    $colleague = rlocpageEmployee($a, 'Ben', 'Torres');
    rlocpageShift($mine);
    rlocpageShift($colleague);
    rlocpageRow($mine, 3, null);
    rlocpageRow($colleague, 3, $b);
    rlocpageActor([ModuleCode::PORTAL, ModuleCode::EDTR], allData: true, employeeId: (string) $mine->getKey());

    $station = Livewire::test(PunchStation::class, [
        'lockedEmployeeId' => (string) $mine->getKey(),
        'selfService' => true,
        'showClock' => false,
    ])
        ->assertSet('employeeId', (string) $mine->getKey())
        ->assertSet('siteId', '')
        ->assertSeeHtml('value="Work from home"')
        ->assertDontSee('Quezon Annex')
        ->assertDontSee('Makati Head Office');
    expect($station->viewData('punchDisabled'))->toBeFalse();
});

/* ── the feeds ───────────────────────────────────────────────────────────── */

it('shows Work from home in the live feed and My punches only for WFH rows', function () {
    $a = rlocpageSite('Makati Head Office');
    $employee = rlocpageEmployee($a, 'Ana', 'Reyes');
    rlocpagePunch($employee, null, 'TIME_IN', '2026-08-31T10:02:00');
    rlocpagePunch($employee, $a, 'TIME_IN', '2026-09-01T10:01:00');

    rlocpageActor([ModuleCode::EDTR], allData: true);

    $feed = Livewire::test(LiveFeed::class)
        ->assertSee('Reyes')
        ->assertSee('Makati Head Office')
        ->html();
    expect(substr_count($feed, 'Work from home'))->toBe(1);

    rlocpageActor([ModuleCode::PORTAL], allData: false, employeeId: (string) $employee->getKey());

    $mine = Livewire::test(MyPunches::class)
        ->assertSee('Makati Head Office')
        ->html();
    expect(substr_count($mine, 'Work from home'))->toBe(1);
});

/* ── the eDTR correction dialog ──────────────────────────────────────────── */

it('shows the Location line in the eDTR correction dialog for a work-from-home date', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $a = rlocpageSite('Makati Head Office');
    $employee = rlocpageEmployee($a, 'Ana', 'Reyes');
    rlocpageShift($employee);
    // Wednesday at home; Tuesday has no row, so it is the Assigned Worksite.
    rlocpageRow($employee, 3, null);
    rlocpageActor([ModuleCode::EDTR], allData: true);

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', '2026-09-02')
        ->assertSet('correctionOpen', true);

    expect(rlocpageFactsHtml($page->html()))
        ->toContain('Assigned Location')
        ->toContain('Work from home')
        ->not->toContain('Assigned worksite')
        ->not->toContain('Makati Head Office');

    $page->call('openCorrection', '2026-09-01')
        ->assertSet('correctionOpen', true);

    // The fallback is named by its site, untagged; the view still says
    // where it came from.
    expect(rlocpageFactsHtml($page->html()))
        ->toContain('Assigned Location')
        ->toContain('Makati Head Office')
        ->not->toContain('Assigned worksite')
        ->not->toContain('Work from home')
        ->and($page->viewData('correction')->location->from_roster)->toBeFalse();
});
