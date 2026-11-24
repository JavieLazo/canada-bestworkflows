<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Edtr\PunchStation;
use App\Livewire\Records\SitesPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterLocation;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Organization\Application\Commands\SaveSite;
use App\Modules\Organization\Application\SiteDraft;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Application\Queries\RosterLocations;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\PunchShiftFixture;

/**
 * ATTENDANCE WITHOUT GPS IS A PER-SITE CHOICE. Site Settings asks it as
 * "Check Location When Punching": on, a punch at the site must come from
 * inside its radius; off (stored as `work_sites.geofence_exempt`), anyone
 * assigned to the site punches from anywhere. Where the place a punch would
 * be judged at does not check location — such a site, or a work-from-home
 * day — RecordPunch takes a Time In and a Time Out that carry no location at
 * all, and the punch station neither asks the browser for one nor warns
 * about its absence (`checks_location` from `RosterLocations::forStation`,
 * still gated by `PunchEmployees::find`). A site that checks location keeps
 * refusing BOTH punches it cannot place, and the station says so.
 */

/** @param list<string> $modules */
function nogpsActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'NoGps '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function nogpsSite(string $name, bool $checksLocation): WorkSite
{
    return WorkSite::query()->create(['name' => $name, 'latitude' => 51.0447, 'longitude' => -114.0719,
        'radius_meters' => 100, 'geofence_exempt' => ! $checksLocation]);
}

/** An active employee assigned to the site, on a shift that takes a punch at any hour. */
function nogpsEmployee(?WorkSite $site, string $first = 'Maya'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'NG-'.Str::random(8), 'first_name' => $first, 'last_name' => 'Ortiz', 'position' => 'Cashier',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'work_site_id' => $site?->id])->save();
    PunchShiftFixture::anyHour($employee->id);

    return $employee;
}

/**
 * The employee's own portal station: locked to them, self-service.
 *
 * @return Testable<PunchStation>
 */
function nogpsOwnStation(EmployeeProfile $employee): Testable
{
    return Livewire::test(PunchStation::class, ['lockedEmployeeId' => $employee->id, 'selfService' => true, 'showClock' => false]);
}

/** A self-service punch through the command, with no location unless one is given. */
function nogpsPunch(User $user, EmployeeProfile $employee, string $type, ?float $lat = null, ?float $lng = null): ?string
{
    return app(RecordPunch::class)->handle(Access::forActor($user), new PunchInput($employee->id, '', 'WEB_GPS', true, '', ''), $type, $lat, $lng, $lat === null ? null : 10.0);
}

function nogpsClock(string $local): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($local, LocalClock::zone()));
}

beforeEach(function () {
    // Wednesday 2 September 2026.
    nogpsClock('2026-09-02 09:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Punching where location is not checked
|--------------------------------------------------------------------------
*/

it('records a Time In and a Time Out with no location at a site that does not check it', function () {
    $site = nogpsSite('Home Care Route', checksLocation: false);
    $employee = nogpsEmployee($site);
    $user = nogpsActor([ModuleCode::PORTAL], employeeId: $employee->id);

    expect(nogpsPunch($user, $employee, 'TIME_IN'))->toContain('TIME IN recorded at Home Care Route');
    nogpsClock('2026-09-02 17:00:00');
    expect(nogpsPunch($user, $employee, 'TIME_OUT'))->toContain('TIME OUT recorded at Home Care Route');

    $rows = AttendanceLog::query()->where('employee_id', $employee->id)->orderBy('logged_at')->get();
    expect($rows->pluck('log_type')->all())->toBe(['TIME_IN', 'TIME_OUT']);
    foreach ($rows as $row) {
        expect($row->site_id)->toBe($site->id)
            ->and($row->latitude)->toBeNull()
            ->and($row->longitude)->toBeNull()
            ->and($row->distance_meters)->toBeNull()
            ->and($row->accuracy_meters)->toBeNull()
            ->and($row->is_geofenced)->toBeTrue();
    }
});

it('records a punch with no location on a work-from-home day, whatever the assigned site checks', function () {
    $site = nogpsSite('Head Office', checksLocation: true);
    $employee = nogpsEmployee($site);
    $row = new RosterLocation;
    $row->forceFill(['employee_id' => $employee->id, 'weekday' => 3, 'kind' => LocationKind::WFH, 'site_id' => null])->save();
    $user = nogpsActor([ModuleCode::PORTAL], employeeId: $employee->id);

    expect(nogpsPunch($user, $employee, 'TIME_IN'))->toBe('TIME IN recorded — working from home.');

    $punch = AttendanceLog::query()->where('employee_id', $employee->id)->sole();
    expect($punch->location_kind)->toBe(LocationKind::WFH)
        ->and($punch->site_id)->toBeNull()
        ->and($punch->latitude)->toBeNull();
});

it('still refuses both a Time In and a Time Out with no location at a site that checks it', function () {
    $site = nogpsSite('Head Office', checksLocation: true);
    $employee = nogpsEmployee($site);
    $user = nogpsActor([ModuleCode::PORTAL], employeeId: $employee->id);

    expect(fn () => nogpsPunch($user, $employee, 'TIME_IN'))
        ->toThrow(RuntimeException::class, 'Time In is blocked: location could not be verified at Head Office.');
    expect(AttendanceLog::query()->count())->toBe(0);

    // In at the site, then out with no location: the Time Out is refused too.
    nogpsPunch($user, $employee, 'TIME_IN', 51.0447, -114.0719);
    nogpsClock('2026-09-02 17:00:00');
    expect(fn () => nogpsPunch($user, $employee, 'TIME_OUT'))
        ->toThrow(RuntimeException::class, 'Time Out is blocked: location could not be verified at Head Office.');
    expect(AttendanceLog::query()->pluck('log_type')->all())->toBe(['TIME_IN']);
});

/*
|--------------------------------------------------------------------------
| The punch station
|--------------------------------------------------------------------------
*/

it('does not ask the browser for a location at a site that does not check it, and punches without one', function () {
    $site = nogpsSite('Home Care Route', checksLocation: false);
    $employee = nogpsEmployee($site);
    nogpsActor([ModuleCode::PORTAL], employeeId: $employee->id);

    $station = nogpsOwnStation($employee);

    expect($station->viewData('checksLocation'))->toBeFalse()
        ->and($station->viewData('punchDisabled'))->toBeFalse()
        ->and($station->html())->toContain('data-checks-location="0"')
        ->toContain('Location is not checked for this punch.')
        ->not->toContain('Your location is read as you punch');

    // The browser sends no location, as it does when it was never asked.
    $station->call('punch', 'TIME_IN')->assertDispatched('edtr-punch-recorded');
    expect(AttendanceLog::query()->where('employee_id', $employee->id)->where('log_type', 'TIME_IN')->whereNull('latitude')->count())->toBe(1);
});

it('does not ask for a location on a work-from-home day', function () {
    $employee = nogpsEmployee(nogpsSite('Head Office', checksLocation: true));
    $row = new RosterLocation;
    $row->forceFill(['employee_id' => $employee->id, 'weekday' => 3, 'kind' => LocationKind::WFH, 'site_id' => null])->save();
    nogpsActor([ModuleCode::PORTAL], employeeId: $employee->id);

    $station = nogpsOwnStation($employee);

    expect($station->viewData('checksLocation'))->toBeFalse()
        ->and($station->html())->toContain('data-checks-location="0"')
        ->toContain('Location is not checked for this punch.');
});

it('asks for a location at a site that checks it, and says Time Out is blocked outside it as well as Time In', function () {
    $employee = nogpsEmployee(nogpsSite('Head Office', checksLocation: true));
    nogpsActor([ModuleCode::PORTAL], employeeId: $employee->id);

    $station = nogpsOwnStation($employee);

    expect($station->viewData('checksLocation'))->toBeTrue()
        ->and($station->html())->toContain('data-checks-location="1"')
        ->toContain('Time In and Time Out are both blocked')
        ->not->toContain('Location is not checked for this punch.');

    // No location reached the server: refused, nothing stored.
    $station->call('punch', 'TIME_IN')->assertNotDispatched('edtr-punch-recorded');
    expect(AttendanceLog::query()->count())->toBe(0);
});

it('skips the location only when the place does not check it: the request is one branch of the punch', function () {
    $employee = nogpsEmployee(nogpsSite('Home Care Route', checksLocation: false));
    nogpsActor([ModuleCode::PORTAL], employeeId: $employee->id);

    $html = nogpsOwnStation($employee)->html();
    $go = Str::before(Str::after($html, 'async go(type)'), 'finally');

    // The browser's location is asked for inside the check on the flag, and
    // so is the "punching without a location" warning; the punch itself is
    // sent either way.
    expect($go)->toContain("dataset.checksLocation !== '0'")
        ->and(strpos($go, 'this.capture()'))->toBeGreaterThan((int) strpos($go, "dataset.checksLocation !== '0'"))
        ->and(strpos($go, 'this.refusal('))->toBeGreaterThan((int) strpos($go, 'this.capture()'))
        ->and(strpos($go, '$wire.punch('))->toBeGreaterThan((int) strpos($go, 'this.refusal('));
});

it('keeps asking while a Time In is open at a site that checks location, though today is worked from home', function () {
    $site = nogpsSite('Head Office', checksLocation: true);
    $employee = nogpsEmployee($site);
    Shift::query()->where('employee_id', $employee->id)->update(['mode' => 'FIXED', 'start_time' => '08:00:00', 'end_time' => '17:00:00']);
    $row = new RosterLocation;
    $row->forceFill(['employee_id' => $employee->id, 'weekday' => 3, 'kind' => LocationKind::WFH, 'site_id' => null])->save();
    // Tuesday's Time In at the office, never closed.
    $open = new AttendanceLog;
    $open->forceFill(['employee_id' => $employee->id, 'site_id' => $site->id, 'location_kind' => LocationKind::ONSITE, 'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS', 'latitude' => 51.0447, 'longitude' => -114.0719, 'distance_meters' => 0.0, 'accuracy_meters' => 8.0,
        'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => LocalClock::instant('2026-09-01 08:00:00')->utc()])->save();
    $user = nogpsActor([ModuleCode::PORTAL], employeeId: $employee->id);
    nogpsClock('2026-09-02 07:30:00');

    $view = app(RosterLocations::class)->forStation(Access::forActor($user), $employee->id);

    // Wednesday is a work-from-home day, but a Time Out pressed now closes
    // Tuesday's Time In and is judged at Tuesday's place.
    expect($view?->kind)->toBe(LocationKind::WFH)
        ->and($view?->open_time_in)->toBeTrue()
        ->and($view?->checks_location)->toBeTrue();
});

it('tells nobody but the employee, or a kiosk operator, whether their place checks location', function () {
    $employee = nogpsEmployee(nogpsSite('Home Care Route', checksLocation: false));
    $stranger = nogpsEmployee(nogpsSite('Head Office', checksLocation: true), 'Noel');

    $other = nogpsActor([ModuleCode::PORTAL, ModuleCode::ROSTER], allData: true, employeeId: $stranger->id);
    expect(app(RosterLocations::class)->forStation(Access::forActor($other), $employee->id))->toBeNull();

    $kiosk = nogpsActor([ModuleCode::EDTR], allData: true);
    expect(app(RosterLocations::class)->forStation(Access::forActor($kiosk), $employee->id)?->checks_location)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Site Settings
|--------------------------------------------------------------------------
*/

it('asks Check Location When Punching per site, on for a new site, with the location fields only while it is on', function () {
    nogpsActor([ModuleCode::RECORDS], allData: true);

    $page = Livewire::test(SitesPage::class)->call('openAdd')
        ->assertSet('formChecksLocation', true)
        ->assertSee('Check Location When Punching')
        ->assertSee('people must be at this site to punch')
        ->assertSeeHtml('wire:model.live.debounce.250ms="formLatitude"')
        ->assertSeeHtml('wire:model.live.debounce.250ms="formLongitude"')
        ->assertSee('Radius (metres)');

    $page->set('formChecksLocation', false)
        ->assertSee('anyone assigned to this site can punch from anywhere')
        ->assertDontSeeHtml('wire:model.live.debounce.250ms="formLatitude"')
        ->assertDontSeeHtml('wire:model.live.debounce.250ms="formLongitude"')
        ->assertDontSee('Radius (metres)');

    // Off needs a name and nothing else.
    $page->set('formName', 'Home Care Route')->call('save')->assertSet('dialogOpen', false);
    expect(WorkSite::query()->where('name', 'Home Care Route')->sole()->geofence_exempt)->toBeTrue();
});

it('opens a site on the choice it was saved with, and needs coordinates to turn the check on', function () {
    nogpsActor([ModuleCode::RECORDS], allData: true);
    $site = nogpsSite('Home Care Route', checksLocation: false);
    $site->forceFill(['latitude' => 0.0, 'longitude' => 0.0])->save();

    $page = Livewire::test(SitesPage::class)->call('startEdit', $site->id)->assertSet('formChecksLocation', false);

    // Turned on with no coordinates typed: refused, the site still does not check.
    $page->set('formChecksLocation', true)->set('formLatitude', '')->set('formLongitude', '')->call('save')->assertSet('dialogOpen', true);
    expect($site->refresh()->geofence_exempt)->toBeTrue();

    $page->set('formLatitude', '51.0447')->set('formLongitude', '-114.0719')->set('formRadius', '150')->call('save')->assertSet('dialogOpen', false);
    expect($site->refresh()->geofence_exempt)->toBeFalse()
        ->and($site->radius_meters)->toBe(150);
});

it('says in the table which sites check location, and filters by it', function () {
    nogpsActor([ModuleCode::RECORDS], allData: true);
    nogpsSite('Calgary Depot', checksLocation: true);
    nogpsSite('Home Care Route', checksLocation: false);

    $page = Livewire::test(SitesPage::class);
    preg_match_all('~data-location-check\s*>\s*([A-Za-z ]+?)\s*</span>~', $page->html(), $pills);
    expect($pills[1])->toBe(['Checked', 'Not Checked'])
        ->and($page->html())->toContain('Location Check')
        ->not->toContain('Geofence');

    $page->set('geofence', 'EXEMPT')->assertSee('Home Care Route')->assertDontSee('Calgary Depot');
    $page->set('geofence', 'ENFORCED')->assertSee('Calgary Depot')->assertDontSee('Home Care Route');
});

it('keeps the stored meaning in the command: exempt saves without coordinates, and only a Records manager saves', function () {
    $manager = nogpsActor([ModuleCode::RECORDS], allData: true);

    app(SaveSite::class)->handle(Access::forActor($manager), null, new SiteDraft('Field Crew', '', '', '100', true));
    expect(WorkSite::query()->where('name', 'Field Crew')->sole()->geofence_exempt)->toBeTrue()
        ->and(fn () => app(SaveSite::class)->handle(Access::forActor($manager), null, new SiteDraft('Depot', '', '', '100', false)))
        ->toThrow(RuntimeException::class, 'A site that checks location needs both coordinates.');

    $reader = nogpsActor([ModuleCode::RECORDS]);
    $audits = AuditLog::query()->count();
    expect(fn () => app(SaveSite::class)->handle(Access::forActor($reader), null, new SiteDraft('Annex', '', '', '100', true)))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(WorkSite::query()->where('name', 'Annex')->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe($audits);
});
