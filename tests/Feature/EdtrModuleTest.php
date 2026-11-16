<?php

declare(strict_types=1);

use App\Livewire\Edtr\EdtrPage;
use App\Livewire\Edtr\LiveFeed;
use App\Livewire\Edtr\PunchStation;
use App\Livewire\Edtr\Support\Hris;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Application\Commands\CorrectPunches;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Application\Queries\PunchFeed;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PunchShiftFixture;

/**
 * The EDTR module: the /edtr route gates on the module grant, the scoped
 * queries return NOTHING for an employee-less actor (the ScopesToActor
 * regression the starter pack calls non-negotiable), and the two write flows —
 * a kiosk punch and a day correction — land rows, audit entries and remarks.
 */

/** @param list<string> $modules */
function edtrRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Edtr '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param list<string> $modules */
function edtrActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => edtrRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function edtrSite(bool $exempt = true): WorkSite
{
    return WorkSite::query()->create([
        'name' => 'Field Office '.Str::random(4),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => $exempt,
    ]);
}

function edtrEmployee(?WorkSite $site = null, string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
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

/** @param array<string, string|int|bool|null> $overrides */
function edtrRosterShift(EmployeeProfile $employee, array $overrides = []): Shift
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
 * One stored punch at a Manila wall-clock instant, with whatever location
 * the test needs on it.
 *
 * @param  array<string, mixed>  $overrides
 */
function edtrPunch(EmployeeProfile $employee, ?WorkSite $site, string $type, string $manilaStamp, array $overrides = []): AttendanceLog
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
        'logged_at' => ManilaClock::instant($manilaStamp)->utc(),
    ], $overrides))->save();

    return $punch;
}

/* ── the route gates on the module grant ─────────────────────────────────── */

it('sends a guest to sign in', function () {
    $this->get('/edtr')->assertRedirect(route('login'));
});

it('turns away a role without the EDTR module', function () {
    // The middleware redirects a browser to whatever it may see.
    edtrActor(['PORTAL']);

    $this->get('/edtr')->assertRedirect('/portal');
});

it('renders for a role holding EDTR', function () {
    edtrActor(['EDTR'], allData: true);

    $this->get('/edtr')
        ->assertOk()
        ->assertSee('eDTR & Attendance')
        ->assertSee('Punch Station');
});

/* ── captured locations belong to the punch and follow its row scope ────── */

it('maps captured punch coordinates and distinguishes a hand-entered location after a feed refresh', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:00:00', [
        'latitude' => 14.612345, 'longitude' => 121.023456,
    ]);
    edtrPunch($employee, null, 'TIME_OUT', '2026-09-02T12:00:00', [
        'location_kind' => 'WFH', 'latitude' => 14.654321, 'longitude' => 121.065432,
    ]);
    $manual = edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T13:00:00', [
        'source' => 'MANUAL', 'latitude' => 14.699999, 'longitude' => 121.099999,
    ]);
    edtrActor(['EDTR'], allData: true);

    $feed = Livewire::test(LiveFeed::class);

    // A site's reference pin is not the captured fix. A kiosk fix on a manual
    // entry is not evidence of the employee's location either.
    foreach ([$feed->html(), $feed->call('punchRecorded')->html()] as $html) {
        $header = Str::before(Str::after($html, '<thead>'), '</thead>');
        expect(trim((string) preg_replace('/\s+/', ' ', strip_tags($header))))
            ->toBe('Employee Timestamp Site Type Source Distance Captured Location');
        expect($html)->toContain('Captured Location')
            ->toContain($site->name)
            ->toContain('Work from home')
            ->toContain('href="https://www.google.com/maps?q=14.612345,121.023456"')
            ->toContain('href="https://www.google.com/maps?q=14.654321,121.065432"')
            ->toContain('target="_blank"')
            ->toContain('rel="noopener noreferrer"')
            ->not->toContain('google.com/maps?q=14.5995,120.9842')
            ->not->toContain('google.com/maps?q=14.699999,121.099999');
        $manualRow = Str::before(Str::after($html, 'wire:key="feed-'.$manual->getKey().'"'), '</tr>');
        expect($manualRow)->toContain($site->name)->not->toContain('google.com/maps');
        expect(Str::afterLast($manualRow, '<td '))->toContain('Manually Entered')
            ->not->toContain($site->name)
            ->not->toContain('google.com/maps');
    }

    $compact = Livewire::test(LiveFeed::class, ['compact' => true])->html();
    expect($compact)->toContain('>Site</th>')
        ->not->toContain('Captured Location')
        ->not->toContain('google.com/maps');
});

it('offers a captured-location map only when both coordinates were stored', function (?float $latitude, ?float $longitude, bool $hasMap) {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:00:00', [
        'latitude' => $latitude, 'longitude' => $longitude,
    ]);
    edtrActor(['EDTR'], allData: true);

    $html = Livewire::test(LiveFeed::class)->html();

    expect($html)->toContain($site->name);
    if ($hasMap) {
        expect($html)->toContain('href="https://www.google.com/maps?q=0,0"');
    } else {
        expect($html)->not->toContain('google.com/maps');
    }
})->with([
    'no coordinates' => [null, null, false],
    'latitude only' => [14.612345, null, false],
    'longitude only' => [null, 121.023456, false],
    'zero coordinates are valid' => [0.0, 0.0, true],
]);

it('keeps captured coordinates and map links within the feed viewers employee scope', function () {
    $site = edtrSite();
    $own = edtrEmployee($site, 'Self', 'Viewer');
    $other = edtrEmployee($site, 'Ramon', 'Bautista');
    $ownPunch = edtrPunch($own, $site, 'TIME_IN', '2026-09-02T08:00:00', [
        'latitude' => 14.612345, 'longitude' => 121.023456,
    ]);
    edtrPunch($other, $site, 'TIME_IN', '2026-09-02T08:01:00', [
        'latitude' => 14.654321, 'longitude' => 121.065432,
    ]);
    $viewer = edtrActor(['EDTR'], employeeId: (string) $own->getKey());

    $rows = app(PunchFeed::class)->list(Access::forActor($viewer), 300);
    expect($rows->pluck('id')->all())->toBe([(string) $ownPunch->getKey()])
        ->and($rows->sole()->latitude)->toBe(14.612345)
        ->and($rows->sole()->longitude)->toBe(121.023456);
    Livewire::test(LiveFeed::class)
        ->assertSee('google.com/maps?q=14.612345,121.023456', false)
        ->assertDontSee('121.065432')
        ->assertDontSee('Bautista');

    $unlinked = edtrActor(['EDTR']);
    app()->forgetInstance(Access::class);

    expect(app(PunchFeed::class)->list(Access::forActor($unlinked), 300))->toBeEmpty();
    Livewire::test(LiveFeed::class)
        ->assertSee('No punches recorded yet.')
        ->assertSee('colspan="7"', false)
        ->assertDontSee('google.com/maps')
        ->assertDontSee('121.023456')
        ->assertDontSee('121.065432');
});

/* ── scoped reads: an employee-less actor gets ZERO rows ─────────────────── */

it('shows an employee-less actor no punches at all', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
    ]);

    // Holds the module but NO all_data and NO employee link: were the scope a
    // naive where(column, null), unowned rows would leak. It must see none.
    edtrActor(['EDTR']);

    Livewire::test(LiveFeed::class)
        ->assertSee('No punches recorded yet.')
        ->assertDontSee('Domingo');
});

it('locks the DTR tab of an employee-less actor to an empty record', function () {
    edtrActor(['EDTR']);

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->assertSee('Select an employee to compute their daily time record.');
});

it('ignores a forged employee pick from a self-only viewer', function () {
    $other = edtrEmployee(edtrSite(), 'Ramon', 'Cruz');
    $own = edtrEmployee(edtrSite(), 'Self', 'Viewer');
    edtrActor(['EDTR'], allData: false, employeeId: (string) $own->getKey());

    Livewire::test(EdtrPage::class)
        ->call('employeePicked', 'dtr-employee', (string) $other->getKey())
        ->assertSet('dtrEmployee', '');
});

/* ── a feed name opens that employee's Daily Time Record ─────────────────── */

it('links a feed name to that employees Daily Time Record, not to Employee Records', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site, 'Ramon', 'Bautista');
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:00:00');
    // Employee Records too: a name opens the Daily Time Record even for a
    // role that could open the record page.
    edtrActor(['EDTR', 'EMPLOYEES'], allData: true);

    $html = Livewire::test(LiveFeed::class)->html();

    expect($html)->toContain('href="'.e(route('edtr.index', ['tab' => 'dtr', 'employee' => (string) $employee->getKey()])).'"')
        ->not->toContain('/employees/'.$employee->getKey());
});

it('opens the DTR tab on the employee a feed link names', function () {
    $employee = edtrEmployee(edtrSite(), 'Ramon', 'Bautista');
    edtrActor(['EDTR'], allData: true);

    $this->get(route('edtr.index', ['tab' => 'dtr', 'employee' => (string) $employee->getKey()]))
        ->assertOk()
        ->assertSee('Bautista')
        ->assertDontSee('Select an employee to compute their daily time record.');

    Livewire::withQueryParams(['tab' => 'dtr', 'employee' => (string) $employee->getKey()]);
    Livewire::test(EdtrPage::class)
        ->assertSet('tab', 'dtr')
        ->assertSet('dtrEmployee', (string) $employee->getKey());
});

it('keeps a self-only viewer on their own record whatever employee a feed link names', function () {
    $other = edtrEmployee(edtrSite(), 'Ramon', 'Bautista');
    $own = edtrEmployee(edtrSite(), 'Self', 'Viewer');
    edtrActor(['EDTR'], allData: false, employeeId: (string) $own->getKey());

    $this->get(route('edtr.index', ['tab' => 'dtr', 'employee' => (string) $other->getKey()]))
        ->assertOk()
        ->assertSee('Viewer')
        ->assertDontSee('Bautista');

    Livewire::withQueryParams(['tab' => 'dtr', 'employee' => (string) $other->getKey()]);
    Livewire::test(EdtrPage::class)
        ->assertSet('tab', 'dtr')
        ->assertSet('dtrEmployee', '');
});

it('ignores the part of a DTR link it cannot honour', function (array $query, string $tab) {
    $employee = edtrEmployee(edtrSite(), 'Ramon', 'Bautista');
    edtrActor(['EDTR'], allData: true);
    array_walk_recursive($query, static function (mixed &$value) use ($employee): void {
        if ($value === '{id}') {
            $value = (string) $employee->getKey();
        }
    });

    Livewire::withQueryParams($query);
    Livewire::test(EdtrPage::class)
        ->assertSet('tab', $tab)
        ->assertSet('dtrEmployee', '');
})->with([
    'an id nobody has' => [['tab' => 'dtr', 'employee' => (string) Str::ulid()], 'dtr'],
    'an employee without the DTR tab' => [['employee' => '{id}'], 'punch'],
    'an unknown tab' => [['tab' => 'nonsense', 'employee' => '{id}'], 'punch'],
    'an employee sent as a list' => [['tab' => 'dtr', 'employee' => ['{id}']], 'dtr'],
]);

/* ── the kiosk punch flow ────────────────────────────────────────────────── */

it('records a kiosk punch pair, audits it, and refuses a duplicate Time In', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
    PunchShiftFixture::anyHour((string) $employee->getKey());
    edtrActor(['EDTR'], allData: true);

    $station = Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
        ->call('punch', 'TIME_IN');

    expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_IN')->count())->toBe(1);
    $this->assertDatabaseHas('attendance_logs', [
        'employee_id' => (string) $employee->getKey(),
        'log_type' => 'TIME_IN',
        'is_geofenced' => true,
        'source' => 'WEB_GPS',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'PUNCH_TIME_IN']);

    // Duplicate TIME_IN — previous TIME_IN is still open: nothing new lands.
    $station->call('punch', 'TIME_IN');
    expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_IN')->count())->toBe(1);

    $station->call('punch', 'TIME_OUT');
    expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_OUT')->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'PUNCH_TIME_OUT']);
});

it('blocks a time in before the one-hour early shift window', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
    edtrRosterShift($employee);
    edtrActor(['EDTR'], allData: true);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 08:59:00', ManilaClock::TZ));

    try {
        Livewire::test(PunchStation::class)
            ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
            ->call('punch', 'TIME_IN');

        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->count())->toBe(0);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('allows a time in exactly one hour before the shift starts', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
    edtrRosterShift($employee);
    edtrActor(['EDTR'], allData: true);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 09:00:00', ManilaClock::TZ));

    try {
        Livewire::test(PunchStation::class)
            ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
            ->call('punch', 'TIME_IN');

        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_IN')->count())->toBe(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('uses the one-hour early window for the first time out slot', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
    edtrRosterShift($employee);
    edtrActor(['EDTR'], allData: true);

    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant('2026-09-02T10:00:00')->utc(),
    ]);

    $station = Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey());

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 10:59:00', ManilaClock::TZ));

    try {
        $station->call('punch', 'TIME_OUT');
        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_OUT')->count())->toBe(0);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 11:00:00', ManilaClock::TZ));
        $station->call('punch', 'TIME_OUT');
        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_OUT')->count())->toBe(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('opens the second time in slot at the break midpoint', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
    edtrRosterShift($employee);
    edtrActor(['EDTR'], allData: true);

    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant('2026-09-02T10:00:00')->utc(),
    ]);
    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_OUT',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant('2026-09-02T12:00:00')->utc(),
    ]);

    $station = Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey());

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 12:29:00', ManilaClock::TZ));

    try {
        $station->call('punch', 'TIME_IN');
        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_IN')->count())->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 12:30:00', ManilaClock::TZ));
        $station->call('punch', 'TIME_IN');
        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_IN')->count())->toBe(2);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('lets the final time out land when middle punches were missed', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
    edtrRosterShift($employee);
    edtrActor(['EDTR'], allData: true);

    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant('2026-09-02T10:00:00')->utc(),
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 17:00:00', ManilaClock::TZ));

    try {
        Livewire::test(PunchStation::class)
            ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
            ->call('punch', 'TIME_OUT');

        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_OUT')->count())->toBe(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('does not apply middle slot windows when the shift has no break', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
    edtrRosterShift($employee, [
        'end_time' => '14:00:00',
        'break_start' => null,
        'break_end' => null,
        'break_minutes' => 0,
        'requires_break_punch' => false,
    ]);
    edtrActor(['EDTR'], allData: true);

    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant('2026-09-02T10:00:00')->utc(),
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 10:59:00', ManilaClock::TZ));

    try {
        Livewire::test(PunchStation::class)
            ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
            ->call('punch', 'TIME_OUT');

        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_OUT')->count())->toBe(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('maps an overnight second time in window to the shift start date', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
    edtrRosterShift($employee, [
        'start_time' => '20:00:00',
        'end_time' => '01:00:00',
        'break_start' => '23:30:00',
        'break_end' => '00:30:00',
        'break_minutes' => 60,
    ]);
    edtrActor(['EDTR'], allData: true);

    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant('2026-09-02T20:00:00')->utc(),
    ]);
    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_OUT',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant('2026-09-02T23:30:00')->utc(),
    ]);

    $station = Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey());

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 23:59:00', ManilaClock::TZ));

    try {
        $station->call('punch', 'TIME_IN');
        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_IN')->count())->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 00:00:00', ManilaClock::TZ));
        $station->call('punch', 'TIME_IN');
        expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('log_type', 'TIME_IN')->count())->toBe(2);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('refuses the kiosk to a holder without all_data', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    // EDTR module, but no all_data and not the employee themselves.
    edtrActor(['EDTR']);

    Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
        ->call('punch', 'TIME_IN');

    expect(AttendanceLog::query()->count())->toBe(0);
});

/* ── the DTR correction and remark flows ─────────────────────────────────── */

it('corrects a day into CORRECTION punches with a remark, and audits the swap', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrActor(['EDTR'], allData: true);

    $today = Hris::todayKey();

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', $today)
        ->assertSet('correctionOpen', true)
        ->set('correctionForm.time_in', '08:00')
        ->set('correctionForm.time_out', '17:00')
        ->set('correctionForm.note', 'Forgot to punch')
        ->call('saveCorrection')
        ->assertSet('correctionOpen', false)
        ->assertSet('correctionError', null);

    expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(2);
    $this->assertDatabaseHas('attendance_remarks', [
        'employee_id' => (string) $employee->getKey(),
        'note' => 'Forgot to punch',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'DTR_CORRECTED']);
});

it('refuses a shapeless correction with the shared message', function () {
    $employee = edtrEmployee(edtrSite());
    edtrActor(['EDTR'], allData: true);

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', Hris::todayKey())
        ->set('correctionForm.break_out', '12:00')
        ->call('saveCorrection')
        ->assertSet('correctionOpen', true)
        ->assertSet('correctionError', 'Record both middle punches, or neither - First Time Out with no Second Time In never closes.');

    expect(AttendanceLog::query()->count())->toBe(0);
});

it('saves and clears a day remark on blur, gated to the widened role', function () {
    $employee = edtrEmployee(edtrSite());
    $today = Hris::todayKey();
    edtrActor(['EDTR'], allData: true);

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->set('remarkDrafts.'.$today, 'On official business');

    $this->assertDatabaseHas('attendance_remarks', [
        'employee_id' => (string) $employee->getKey(),
        'note' => 'On official business',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'DTR_REMARK_SET']);

    $page->set('remarkDrafts.'.$today, '');
    $this->assertDatabaseMissing('attendance_remarks', [
        'employee_id' => (string) $employee->getKey(),
        'note' => 'On official business',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'DTR_REMARK_CLEARED']);
});

it('never writes a remark for a viewer without the widened role', function () {
    $own = edtrEmployee(edtrSite());
    edtrActor(['EDTR'], allData: false, employeeId: (string) $own->getKey());

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->set('remarkDrafts.'.Hris::todayKey(), 'Trying to write my own note');

    expect(AttendanceRemark::query()->count())->toBe(0);
});

/* ── the stamped poll: a tick reads a stamp, not 300 rows ────────────────── */

/**
 * Every statement the callback issued, as SQL.
 *
 * @return Collection<int, string>
 */
function edtrQueriesDuring(Closure $work): Collection
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $work();

        return collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
    }
}

it('does not re-read the feed on a tick where no punch landed', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
    ]);
    edtrActor(['EDTR'], allData: true);

    $feed = Livewire::test(LiveFeed::class)->assertSee('Domingo');

    $queries = edtrQueriesDuring(fn () => $feed->call('pollForChanges'));

    // The 300-row window never ran; one aggregate answered the tick instead.
    expect($queries->filter(fn (string $q): bool => str_contains($q, 'limit 300')))->toBeEmpty()
        ->and($queries->filter(fn (string $q): bool => str_contains(strtolower($q), 'count(*)')))->not->toBeEmpty();
});

it('re-reads the feed on the tick after a punch lands elsewhere', function () {
    $site = edtrSite();
    edtrEmployee($site);
    edtrActor(['EDTR'], allData: true);

    $feed = Livewire::test(LiveFeed::class)->assertDontSee('Bautista');

    // Another station records a punch between two ticks.
    $walkIn = edtrEmployee($site, 'Ramon', 'Bautista');
    AttendanceLog::query()->create([
        'employee_id' => (string) $walkIn->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
    ]);

    $queries = edtrQueriesDuring(fn () => $feed->call('pollForChanges'));

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'limit 300')))->not->toBeEmpty();
    $feed->assertSee('Bautista');
});

it('keeps a self-only viewer\'s feed still while other people punch', function () {
    $site = edtrSite();
    $own = edtrEmployee($site, 'Self', 'Viewer');
    edtrActor(['EDTR'], allData: false, employeeId: (string) $own->getKey());

    $feed = Livewire::test(LiveFeed::class);

    // A punch this viewer is not scoped to see must not move their stamp.
    $other = edtrEmployee($site, 'Ramon', 'Bautista');
    AttendanceLog::query()->create([
        'employee_id' => (string) $other->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
    ]);

    $queries = edtrQueriesDuring(fn () => $feed->call('pollForChanges'));

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'limit 300')))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| DTR colour tones
|--------------------------------------------------------------------------
*/

it('colours a DTR duration only when there is something to show', function () {
    // The four columns, at their tones.
    expect(Hris::durationTone(480, 'text-success'))->toBe('text-success')
        ->and(Hris::durationTone(18, 'text-danger'))->toBe('text-danger')
        ->and(Hris::durationTone(75, 'text-warning'))->toBe('text-warning');

    // Zero is grey in every column, which is the whole point: fmtDuration
    // prints "0m" on an untroubled day and a tinted column would paint a
    // clean month red and orange.
    expect(Hris::durationTone(0, 'text-danger'))->toBe('text-muted-foreground')
        ->and(Hris::durationTone(0, 'text-success'))->toBe('text-muted-foreground');
});

it('keeps the DTR tone in step with the duration beside it', function () {
    // Anything that rounds to "0m" must be grey, or a cell reads red while
    // showing nothing. The tone and the text share one rounding.
    foreach ([0.0, 0.4, -0.4] as $barelyAnything) {
        expect(Hris::fmtDuration($barelyAnything))->toBe('0m')
            ->and(Hris::durationTone($barelyAnything, 'text-danger'))->toBe('text-muted-foreground');
    }

    // And the first minute that does print must carry the colour.
    expect(Hris::fmtDuration(1))->toBe('1m')
        ->and(Hris::durationTone(1, 'text-danger'))->toBe('text-danger');

    // A non-finite reading is zero to fmtDuration, so it is grey here too.
    expect(Hris::durationTone(NAN, 'text-danger'))->toBe('text-muted-foreground');
});

it('colours a DTR row by what the day actually contains', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrActor(['EDTR'], allData: true);

    $today = Hris::todayKey();

    // A day that arrives late and leaves early: three of the four columns have
    // something to say and the fourth, overtime, has nothing.
    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', $today)
        ->set('correctionForm.time_in', '08:47')
        ->set('correctionForm.time_out', '15:30')
        ->call('saveCorrection');

    $html = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->html();

    // That day's row, and the five right-aligned numbers in it, in the order
    // the header names them: break, worked, late, undertime, overtime.
    $row = Str::before(Str::after($html, 'wire:key="dtr-'.$today.'"'), '</tr>');
    preg_match_all('/<td class="num text-right ?([a-z-]*)">(.*?)<\/td>/s', $row, $cells, PREG_SET_ORDER);
    // Overtime also has an approval action or status. Its review button is
    // the computed duration; those other labels are not part of that number.
    $cells = array_map(static function (array $cell): array {
        preg_match('/<button\b[^>]*wire:click="openOvertime\([^"]*"[^>]*>(.*?)<\/button>/s', $cell[2], $computed);

        return [$cell[0], $cell[1], trim(strip_tags($computed[1] ?? $cell[2]))];
    }, $cells);

    expect($cells)->toHaveCount(5);
    [, $worked, $late, $undertime, $overtime] = $cells;

    // Asserted on the tone rather than the arithmetic, so a change to the
    // default shift moves this test's inputs without breaking its subject.
    expect($worked[1])->toBe('text-success')->and($worked[2])->not->toBe('0m');
    expect($late[1])->toBe('text-danger')->and($late[2])->not->toBe('0m');
    expect($undertime[1])->toBe('text-warning')->and($undertime[2])->not->toBe('0m');

    // Nobody worked past the shift, so overtime stays grey rather than green.
    expect($overtime[1])->toBe('text-muted-foreground')->and($overtime[2])->toBe('0m');

    // And nowhere in the table — including the absent days above this row —
    // does a colour sit over a zero.
    expect(preg_match('/text-right text-(success|danger|warning)">0m</', $html))->toBe(0);
});

it('keeps DTR corrections out of the live feed, but not station punches', function () {
    // Freeze the clock. The correction below targets today minus five days, and
    // openCorrection() silently ignores a date outside the displayed period, which
    // defaults to the current semi-monthly cutoff (Hris::semiMonthlyPeriod — 1–15 or
    // 16–end of month). With a live clock, five days back crosses that boundary on
    // the 1st–5th and 16th–20th of every month, the correction never opens, and this
    // test fails ten days a month without any code having changed.
    test()->travelTo(new DateTimeImmutable('2026-09-23T12:00:00+08:00'));

    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrActor(['EDTR'], allData: true);

    // A punch an admin recorded AT the station, by hand. Hand-entered, but it
    // is happening now, so the feed is exactly where it belongs.
    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'MANUAL',
        'is_geofenced' => true,
        'is_anomaly' => false,
    ]);

    // ...and a day typed into the DTR dialog, which is back-dated by
    // definition and is not news. Corrected on a day well before the station
    // punch: a correction SUPERSEDES its own day, so correcting today would
    // delete the punch above rather than sit alongside it, and prove nothing.
    $pastDay = ManilaClock::dayKey(CarbonImmutable::now()->subDays(5));

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', $pastDay)
        ->set('correctionForm.time_in', '08:00')
        ->set('correctionForm.time_out', '17:00')
        ->call('saveCorrection');

    // Three punches on the table...
    expect(AttendanceLog::query()->count())->toBe(3)
        ->and(AttendanceLog::query()->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(2);

    // ...and one of them in the feed.
    $feed = Livewire::test(LiveFeed::class);

    expect($feed->viewData('total'))->toBe(1);
    $feed->assertSee('MANUAL');
});

it('still notices a correction, even though it does not show one', function () {
    // The stamp is deliberately wider than the rows: filtering corrections out
    // of it would cost a row lookup per tick to save a redraw that happens by
    // hand a few times a month. This pins that as a choice rather than an
    // oversight — over-triggering is safe, under-triggering would be the bug.
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrActor(['EDTR'], allData: true);

    $feed = Livewire::test(LiveFeed::class);
    $before = $feed->get('changeStamp');

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', Hris::todayKey())
        ->set('correctionForm.time_in', '08:00')
        ->set('correctionForm.time_out', '17:00')
        ->call('saveCorrection');

    $feed->call('pollForChanges');

    expect($feed->get('changeStamp'))->not->toBe($before)
        ->and($feed->viewData('total'))->toBe(0);
});

it('draws every day of the chosen period, with nothing behind a scrollbar', function () {
    $employee = edtrEmployee(edtrSite());
    edtrActor(['EDTR'], allData: true);

    // A full 31-day month, which is the case a capped scrollport hid most of.
    $html = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->set('start', '2026-08-01')
        ->set('end', '2026-08-31')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->html();

    // One row per calendar day, all of them rendered.
    expect(substr_count($html, 'wire:key="dtr-2026-08-'))->toBe(31)
        ->and($html)->toContain('wire:key="dtr-2026-08-01"')
        ->and($html)->toContain('wire:key="dtr-2026-08-31"');

    // And no overflow between the card and the table while the table fits, or
    // the sticky header pins to a box that never scrolls instead of to the
    // page. The wrap goes on only from the card's own measurement, once the
    // table is wider than it — never in the markup, and never a height cap.
    $card = Str::before(Str::after($html, 'hris-table-flush'), '</table>');

    expect($html)->toContain('class="hris-card hris-table-flush"')
        ->and($card)->toContain('<table class="hris-table">')
        ->and($card)->not->toContain('hris-table-scroll')
        ->and($card)->not->toContain('overflow-hidden')
        ->and($card)->toContain("x-bind:class=\"{ 'hris-table-wrap': overflows }\"")
        ->and(substr_count($card, 'hris-table-wrap'))->toBe(1);
});

/* ── which identifier the header card shows ──────────────────────────────── */

it('pills the Employee ID beside the name, and shows no pill at all without one', function () {
    $site = edtrSite();
    $numbered = edtrEmployee($site, 'Liza', 'Domingo');
    $numbered->forceFill(['employee_code' => 'COP-00061', 'employee_id_no' => '2024-301'])->save();
    $plain = edtrEmployee($site, 'Noel', 'Reyes');
    $plain->forceFill(['employee_code' => 'COP-00062'])->save();

    edtrActor(['EDTR'], allData: true);

    // An empty pill is a grey smudge: the whole span goes, not just its text.
    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $numbered->getKey())
        ->assertSee('2024-301')
        ->assertDontSee('COP-00061')
        ->call('employeePicked', 'dtr-employee', (string) $plain->getKey())
        ->assertSee('Noel Reyes')
        ->assertDontSee('COP-00062');
});

/* ── the browser fix and its accuracy ────────────────────────────────────── */

it('stores the browser accuracy on a self-service punch, rounded to two decimals, and audits it', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    PunchShiftFixture::anyHour((string) $employee->getKey());
    $actor = Access::forActor(edtrActor(['PORTAL'], employeeId: (string) $employee->getKey()));

    app(RecordPunch::class)->handle(
        $actor,
        new PunchInput((string) $employee->getKey(), '', 'WEB_GPS', true, '', ''),
        'TIME_IN',
        14.5995,
        120.9842,
        8.456,
    );

    $log = AttendanceLog::query()->where('employee_id', $employee->getKey())->sole();
    expect($log->source)->toBe('WEB_GPS')
        ->and((float) $log->latitude)->toBe(14.5995)
        ->and((float) $log->longitude)->toBe(120.9842)
        // Exempt site: nothing to measure a distance against, but the fix
        // itself was measured.
        ->and($log->distance_meters)->toBeNull()
        ->and((float) $log->accuracy_meters)->toBe(8.46);
    expect(DB::table('audit_logs')->where('action', 'PUNCH_TIME_IN')->value('details'))->toContain('(±8 m)');
});

it('lets a typed kiosk pin override the browser fix and records no accuracy for it', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    PunchShiftFixture::anyHour((string) $employee->getKey());
    $actor = Access::forActor(edtrActor(['EDTR'], allData: true));

    app(RecordPunch::class)->handle(
        $actor,
        new PunchInput((string) $employee->getKey(), '', 'MANUAL', false, '14.5995', '120.9842'),
        'TIME_IN',
        13.0,
        121.0,
        30.0,
    );

    $log = AttendanceLog::query()->where('employee_id', $employee->getKey())->sole();
    expect((float) $log->latitude)->toBe(14.5995)
        ->and((float) $log->longitude)->toBe(120.9842)
        ->and($log->accuracy_meters)->toBeNull();
    expect((string) DB::table('audit_logs')->where('action', 'PUNCH_TIME_IN')->value('details'))->not->toContain('±');
});

it('uses the kiosk browser fix and its accuracy when both coordinate boxes are blank', function () {
    // A fenced site right at the pin, so the fix is verified and measured.
    $site = edtrSite(exempt: false);
    $employee = edtrEmployee($site);
    PunchShiftFixture::anyHour((string) $employee->getKey());
    $actor = Access::forActor(edtrActor(['EDTR'], allData: true));

    app(RecordPunch::class)->handle(
        $actor,
        new PunchInput((string) $employee->getKey(), '', 'WEB_GPS', false, '', ''),
        'TIME_IN',
        14.5995,
        120.9842,
        5.0,
    );

    $log = AttendanceLog::query()->where('employee_id', $employee->getKey())->sole();
    expect($log->is_geofenced)->toBeTrue()
        ->and((float) $log->distance_meters)->toBe(0.0)
        ->and((float) $log->accuracy_meters)->toBe(5.0);
    expect(DB::table('audit_logs')->where('action', 'PUNCH_TIME_IN')->value('details'))->toContain('(0 m ±5 m)');
});

it('records no accuracy without coordinates or for a reading outside its range', function (?float $lat, ?float $lng, float $accuracy) {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    PunchShiftFixture::anyHour((string) $employee->getKey());
    $actor = Access::forActor(edtrActor(['EDTR'], allData: true));

    app(RecordPunch::class)->handle(
        $actor,
        new PunchInput((string) $employee->getKey(), '', 'WEB_GPS', false, '', ''),
        'TIME_IN',
        $lat,
        $lng,
        $accuracy,
    );

    // The punch lands (the site is exempt); the reading does not.
    expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->sole()->accuracy_meters)->toBeNull();
})->with([
    'no fix' => [null, null, 5.0],
    'negative' => [14.5995, 120.9842, -1.0],
    'not a number' => [14.5995, 120.9842, NAN],
    'past the column' => [14.5995, 120.9842, 10_000_000_000.0],
]);

it('still refuses non-numeric typed kiosk coordinates before reading the browser fix', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    $actor = Access::forActor(edtrActor(['EDTR'], allData: true));

    expect(fn () => app(RecordPunch::class)->handle(
        $actor,
        new PunchInput((string) $employee->getKey(), '', 'WEB_GPS', false, 'x', ''),
        'TIME_IN',
        14.5995,
        120.9842,
        5.0,
    ))->toThrow(RuntimeException::class, "Those coordinates aren't numbers.");

    expect(AttendanceLog::query()->count())->toBe(0);
});

/* ── a correction keeps what it did not change ───────────────────────────── */

it('keeps an untouched punch with its source and location through a correction and replaces only the changed slot', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    $actor = Access::forActor(edtrActor(['EDTR'], allData: true));

    $in = edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:00:31', [
        'latitude' => 14.5995, 'longitude' => 120.9842, 'distance_meters' => 12.0, 'accuracy_meters' => 8.46,
    ]);
    $out = edtrPunch($employee, $site, 'TIME_OUT', '2026-09-02T16:00:05');

    CorrectPunches::apply($actor, (string) $employee->getKey(), '2026-09-02', [
        'time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00',
    ], PunchSlots::SAME_DAY, null);

    // The Time In was left as prefilled: same row, same seconds, same fix.
    $kept = AttendanceLog::query()->findOrFail((string) $in->getKey());
    expect($kept->source)->toBe('WEB_GPS')
        ->and($kept->logged_at->format('Y-m-d H:i:s'))->toBe('2026-09-02 00:00:31')
        ->and((float) $kept->latitude)->toBe(14.5995)
        ->and((float) $kept->accuracy_meters)->toBe(8.46);

    // The Time Out changed: the recorded one is gone, a CORRECTION stands.
    expect(AttendanceLog::query()->whereKey($out->getKey())->exists())->toBeFalse();
    $replacement = AttendanceLog::query()->where('employee_id', $employee->getKey())->where('source', AttendanceLog::SOURCE_CORRECTION)->sole();
    expect($replacement->log_type)->toBe('TIME_OUT')
        ->and($replacement->logged_at->format('Y-m-d H:i:s'))->toBe('2026-09-02 09:00:00')
        ->and($replacement->accuracy_meters)->toBeNull();
    expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->count())->toBe(2);

    $details = (string) DB::table('audit_logs')->where('action', 'DTR_CORRECTED')->value('details');
    // "now" prints what STANDS: the kept row with its real seconds (the
    // seconds its lateness verdict comes from), the inserted one at :00.
    expect($details)->toContain('was TIME_IN 8:00:31 AM, TIME_OUT 4:00:05 PM')
        ->toContain('now TIME_IN 8:00:31 AM, TIME_OUT 5:00:00 PM')
        ->toContain('kept 1 as recorded');
    expect(AttendanceRemark::query()->count())->toBe(0);
});

it('keeps a night shift\'s carried time out when its minute is unchanged', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrRosterShift($employee, ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
    $actor = Access::forActor(edtrActor(['EDTR'], allData: true));

    $in = edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T22:00:15');
    $out = edtrPunch($employee, $site, 'TIME_OUT', '2026-09-03T06:01:40');

    // The 06:01 is ticked Next day, as the dialog prefills it: a save never
    // infers the next morning from the order of the times, so an unticked
    // 06:01 would read as earlier than the 22:00.
    CorrectPunches::apply($actor, (string) $employee->getKey(), '2026-09-02', [
        'time_in' => '22:00', 'break_out' => null, 'break_in' => null, 'time_out' => '06:01',
    ], ['time_out' => true] + PunchSlots::SAME_DAY, null);

    expect(AttendanceLog::query()->whereKey($in->getKey())->exists())->toBeTrue()
        ->and(AttendanceLog::query()->whereKey($out->getKey())->exists())->toBeTrue()
        ->and(AttendanceLog::query()->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(0)
        ->and(AttendanceLog::query()->count())->toBe(2);
    // The audit sentence carries only the corrected date, so the carried
    // Time Out says it is on the next day — before and after.
    expect((string) DB::table('audit_logs')->where('action', 'DTR_CORRECTED')->value('details'))
        ->toContain('was TIME_IN 10:00:15 PM, TIME_OUT 6:01:40 AM next day')
        ->toContain('now TIME_IN 10:00:15 PM, TIME_OUT 6:01:40 AM next day')
        ->toContain('kept 2 as recorded');
});

it('drops the duplicate of two punches recorded in the same minute', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    $actor = Access::forActor(edtrActor(['EDTR'], allData: true));

    $first = edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:00:10');
    $double = edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:00:40');
    $out = edtrPunch($employee, $site, 'TIME_OUT', '2026-09-02T17:00:00');

    CorrectPunches::apply($actor, (string) $employee->getKey(), '2026-09-02', [
        'time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00',
    ], PunchSlots::SAME_DAY, null);

    expect(AttendanceLog::query()->whereKey($first->getKey())->exists())->toBeTrue()
        ->and(AttendanceLog::query()->whereKey($double->getKey())->exists())->toBeFalse()
        ->and(AttendanceLog::query()->whereKey($out->getKey())->exists())->toBeTrue()
        ->and(AttendanceLog::query()->count())->toBe(2);
});

it('clears a day entirely when every slot is emptied, and still audits it', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    $actor = Access::forActor(edtrActor(['EDTR'], allData: true));

    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:00:00');
    edtrPunch($employee, $site, 'TIME_OUT', '2026-09-02T17:00:00');

    CorrectPunches::apply($actor, (string) $employee->getKey(), '2026-09-02', [
        'time_in' => null, 'break_out' => null, 'break_in' => null, 'time_out' => null,
    ], PunchSlots::SAME_DAY, 'Punched by mistake');

    expect(AttendanceLog::query()->count())->toBe(0);
    $details = (string) DB::table('audit_logs')->where('action', 'DTR_CORRECTED')->value('details');
    expect($details)->toContain('→ now no punches')
        ->not->toContain('kept');
    $this->assertDatabaseHas('attendance_remarks', ['employee_id' => (string) $employee->getKey(), 'note' => 'Punched by mistake']);
});

/* ── the feed header and the DTR's time cells ────────────────────────────── */

it('shows no count pill on the live feed', function () {
    edtrActor(['EDTR'], allData: true);

    $feed = Livewire::test(LiveFeed::class);

    // The header carries no count; the total drives the empty row.
    expect($feed->html())->not->toContain('pill pill-muted num')
        ->and($feed->viewData('total'))->toBe(0);
});

it('prints DTR punch times without seconds', function () {
    expect(Hris::fmtTime('2026-09-02T14:05:31'))->toBe('2:05 PM')
        ->and(Hris::fmtTime('2026-09-02T00:05:31.000Z'))->toBe('8:05 AM');

    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T08:47:31');
    edtrPunch($employee, $site, 'TIME_OUT', '2026-09-02T15:30:00');
    edtrActor(['EDTR'], allData: true);

    $html = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->html();
    $row = Str::before(Str::after($html, 'wire:key="dtr-2026-09-02"'), '</tr>');

    expect($row)->toContain('>8:47 AM<')->toContain('>3:30 PM<')
        ->not->toContain('8:47:31')->not->toContain('8:47:00');
});

/* ── the row opens the correction ────────────────────────────────────────── */

it('opens the correction from the row itself, with no pencil column', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $employee = edtrEmployee(edtrSite());
    edtrActor(['EDTR'], allData: true);
    $today = '2026-09-02';

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey());
    $html = $page->html();

    // No pencil column, and this zero-OT row needs no approval button. The
    // eleven cells keep computed OT; pending approval stays inside its OT cell.
    expect($html)->not->toContain('sr-only">Correct</span>');
    $row = Str::before(Str::after($html, 'wire:key="dtr-'.$today.'"'), '</tr>');
    // The Remarks cell is marked, never `.stop`-ped: a stopped click would keep
    // every `.outside` panel on the page (bell, picker, calendar) open.
    expect(substr_count($row, '<td'))->toBe(11)
        ->and($row)->not->toContain('<button')
        ->and($row)->not->toContain('.stop')
        ->and($row)->toContain('<td data-remarks>');

    // The <tr> skips Remarks and the separate overtime button by target,
    // while keeping its keyboard handlers and focus.
    $opening = Str::before($row, '<td');
    expect($opening)->toContain('x-on:click="$event.target.closest(\'[data-remarks], button\') || $wire.openCorrection(\''.$today.'\')"')
        ->toContain('x-on:keydown.enter.self.prevent')
        ->toContain('x-on:keydown.space.self.prevent')
        ->toContain('tabindex="0"')
        ->toContain('cursor-pointer');

    // And the empty state spans all eleven columns.
    $page->set('start', '2026-09-30')->set('end', '2026-09-01');
    expect($page->html())->toContain('colspan="11"');
});

it('keeps the DTR row inert for a self-only viewer', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $employee = edtrEmployee(edtrSite());
    edtrActor(['EDTR'], employeeId: (string) $employee->getKey());

    $page = Livewire::test(EdtrPage::class)->set('tab', 'dtr');
    $row = Str::before(Str::after($page->html(), 'wire:key="dtr-2026-09-02"'), '</tr>');

    expect($row)->not->toContain('openCorrection')
        ->not->toContain('tabindex')
        ->not->toContain('cursor-pointer');
    $page->call('openCorrection', '2026-09-02')
        ->assertSet('correctionOpen', false)
        ->assertSet('correctingDate', null);
});

it('still ignores a correction for a date outside the shown period or that is no date', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $employee = edtrEmployee(edtrSite());
    edtrActor(['EDTR'], allData: true);

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey());

    foreach (['2020-01-01', 'not-a-date', '2026-13-01'] as $date) {
        $page->call('openCorrection', $date)
            ->assertSet('correctionOpen', false)
            ->assertSet('correctingDate', null);
        expect($page->viewData('correction'))->toBeNull();
    }
});

/* ── the correction dialog ───────────────────────────────────────────────── */

it('draws the correction dialog from the day\'s own shift and punches', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrRosterShift($employee);
    $fix = ['latitude' => 14.5995, 'longitude' => 120.9842, 'distance_meters' => 12.0, 'accuracy_meters' => 8.0];
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T10:03:00', $fix);
    $typed = edtrPunch($employee, $site, 'TIME_OUT', '2026-09-02T12:05:00', ['source' => 'MANUAL'] + $fix);
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T13:02:00', $fix);
    edtrActor(['EDTR'], allData: true);

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', '2026-09-02')
        ->assertSet('correctionOpen', true)
        ->assertSet('correctionForm.time_in', '10:03');

    $correction = $page->viewData('correction');
    expect($correction)->not->toBeNull()
        ->and($correction->punches)->toHaveCount(3);

    // A Web GPS punch shows the place it was captured at and its spot on a
    // map — no source, distance or accuracy — and an admin's MANUAL punch
    // says only "Manually Entered", with no place and no map even when it
    // carries the kiosk's fix.
    $dialog = Str::after($page->html(), 'Recorded Punches');
    expect($dialog)
        ->toContain('rel="noopener noreferrer"')
        ->toContain('>10:03 AM<')
        ->not->toContain('10:03:00')
        ->toContain($site->name)
        ->not->toContain('WEB GPS')
        ->not->toContain('MANUAL')
        ->not->toContain('>12 m<')
        ->not->toContain('±')
        ->not->toContain('no GPS fix');
    expect(substr_count($dialog, 'google.com/maps?q=14.5995,120.9842'))->toBe(2);
    $typedRow = Str::before(Str::after($dialog, 'wire:key="correct-punch-'.$typed->getKey().'"'), '</tr>');
    expect($typedRow)->toContain('Manually Entered')
        ->not->toContain($site->name)
        ->not->toContain('google.com/maps');
    expect(substr_count($dialog, 'Manually Entered'))->toBe(1);

    // The shift for THAT date, not the header card's label.
    $facts = Str::before(Str::after($page->html(), 'correction-heading'), 'Recorded Punches');
    expect($facts)->toContain('10:00 AM – 5:00 PM')
        ->toContain('12:00 PM – 1:00 PM')
        ->not->toContain('Rest day');
});

it('tells the corrector what the shift for that date looks like', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = edtrSite();
    edtrActor(['EDTR'], allData: true);

    $noBreak = edtrEmployee($site, 'Noel', 'Reyes');
    edtrRosterShift($noBreak, ['break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
    $flexible = edtrEmployee($site, 'Ana', 'Cruz');
    edtrRosterShift($flexible, ['mode' => 'FLEXIBLE', 'required_hours' => 6, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
    $unrostered = edtrEmployee($site, 'Ben', 'Santos');

    $open = fn (EmployeeProfile $e, string $date) => Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $e->getKey())
        ->call('openCorrection', $date);

    $open($noBreak, '2026-09-02')->assertSee('No scheduled break — use First Time In and Final Time Out');
    $open($flexible, '2026-09-02')->assertSee('Flexible · 6h a day, no set start');
    // A Sunday on the default Mon–Fri week, for somebody with no roster row.
    $open($unrostered, '2026-09-06')->assertSee('Rest day')->assertSee('Not rostered');
});

it('shows the auto remark only as the dialog\'s placeholder, with no status line', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrRosterShift($employee, ['break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T10:25:00');
    edtrPunch($employee, $site, 'TIME_OUT', '2026-09-02T17:00:00');
    edtrActor(['EDTR'], allData: true);

    $html = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', '2026-09-02')
        ->html();
    $dialog = Str::after($html, 'correction-heading');

    // There is no name / status / auto-remark line under the date: the
    // remark is said once, as the placeholder.
    expect($dialog)->toContain('placeholder="25 min late"')
        ->and(substr_count($dialog, '25 min late'))->toBe(1)
        ->and($dialog)->not->toContain('pill pill-caution">LATE<')
        ->not->toContain('Liza Domingo');
});

it('clears all four slots without touching the remark, and has no clear per slot', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $employee = edtrEmployee(edtrSite());
    edtrActor(['EDTR'], allData: true);

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', '2026-09-02');

    // "Clear all" is the one clear button: a single slot is emptied by
    // deleting its typed time.
    $dialog = Str::after($page->html(), 'correction-heading');
    expect($dialog)->toContain('wire:click="clearAllSlots"')
        ->not->toContain('clearSlot(')
        ->not->toContain('aria-label="Clear ');

    $page->set('correctionForm.time_in', '08:00')
        ->set('correctionForm.time_out', '17:00')
        ->set('correctionForm.note', 'Late bus')
        ->call('clearAllSlots')
        ->assertSet('correctionForm.time_in', '')
        ->assertSet('correctionForm.time_out', '')
        ->assertSet('correctionForm.break_out', '')
        ->assertSet('correctionForm.break_in', '')
        ->assertSet('correctionForm.note', 'Late bus');

    // A shape error goes with the shape that caused it.
    $page->set('correctionForm.break_out', '12:00')
        ->call('saveCorrection')
        ->assertSet('correctionError', 'Record both middle punches, or neither - First Time Out with no Second Time In never closes.')
        ->call('clearAllSlots')
        ->assertSet('correctionError', null)
        ->assertSet('correctionForm.break_out', '');

    // Closed, the dialog's Clear all does nothing.
    $page->set('correctionOpen', false)
        ->assertSet('correctingDate', null)
        ->set('correctionForm.time_out', '09:00')
        ->call('clearAllSlots')
        ->assertSet('correctionForm.time_out', '09:00');

    expect(AttendanceLog::query()->count())->toBe(0);
});

/* ── the station sends the browser fix ───────────────────────────────────── */

it('stores the browser fix and its accuracy on a kiosk punch when the boxes are empty', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    PunchShiftFixture::anyHour((string) $employee->getKey());
    edtrActor(['EDTR'], allData: true);

    Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
        ->call('punch', 'TIME_IN', 14.5995, 120.9842, 8.4);

    $log = AttendanceLog::query()->where('employee_id', $employee->getKey())->sole();
    expect((float) $log->latitude)->toBe(14.5995)
        ->and((float) $log->longitude)->toBe(120.9842)
        ->and((float) $log->accuracy_meters)->toBe(8.4);
});

it('lets typed kiosk coordinates win over the browser fix', function () {
    $site = edtrSite();
    $employee = edtrEmployee($site);
    PunchShiftFixture::anyHour((string) $employee->getKey());
    edtrActor(['EDTR'], allData: true);

    Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
        ->set('lat', '14.6')
        ->set('lng', '121.1')
        ->call('punch', 'TIME_IN', 14.5, 120.9, 8.4);

    $log = AttendanceLog::query()->where('employee_id', $employee->getKey())->sole();
    expect((float) $log->latitude)->toBe(14.6)
        ->and((float) $log->longitude)->toBe(121.1)
        ->and($log->accuracy_meters)->toBeNull();
});

/* ── the correction dialog's layout ──────────────────────────────────────── */

it('keeps one Recorded punches anchor, no tab strip on a day without overtime, and Save inside the form', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrRosterShift($employee);
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T10:03:00');
    edtrActor(['EDTR'], allData: true);

    $html = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', '2026-09-02')
        ->html();
    $dialog = Str::after($html, 'correction-heading');

    expect(substr_count($html, 'Recorded Punches'))->toBe(1)
        ->and($dialog)->not->toContain('role="tablist"')
        ->and(Str::between($html, 'wire:submit="saveCorrection"', '</form>'))->toContain('Save Punches')
        // The title is the date; "Correct" is left for screen readers only.
        ->and($dialog)->toContain('<span class="sr-only">Correct </span>Wed, Sep 02, 2026');

    // That heading is the dialog's only accessible name: Flux forwards no
    // aria-label, so x-init points aria-labelledby at the heading's id.
    expect($html)->toContain('id="correction-heading"')
        ->toMatch("/x-init=\"[^\"]*setAttribute\\((?:'|&#0?39;)aria-labelledby(?:'|&#0?39;), (?:'|&#0?39;)correction-heading(?:'|&#0?39;)\\)/");

    // The empty-slots warning is a live region that is in the markup before
    // it speaks (a region added later is not announced), slots filled or not.
    expect(Str::between($html, 'wire:submit="saveCorrection"', '</form>'))
        ->toMatch('#<p role="status"[^>]*>\s*<span[^>]*x-show="dropsDay\(\)"[^>]*>.*?Every slot is empty, so saving deletes all of this day#s');
});

it('keeps the dialog header to the date, the shift, the break and the place', function () {
    // The dialog has no name / status / auto-remark line, no grace,
    // Overnight or Assigned worksite tag, no break minutes, no × per slot
    // and no "Saving replaces" footer line. A night shift on the Assigned
    // Worksite fallback would show every one of them.
    $this->travelTo(new DateTimeImmutable('2026-09-03T18:00:00+08:00'));
    $site = edtrSite();
    $employee = edtrEmployee($site, 'Ramon', 'Aquino');
    edtrRosterShift($employee, ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'grace_minutes' => 15, 'break_start' => '02:00:00', 'break_end' => '03:00:00']);
    edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T21:55:00');
    edtrActor(['EDTR'], allData: true);

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', '2026-09-02')
        ->assertSet('correctionOpen', true);
    $dialog = Str::after($page->html(), 'correction-heading');
    $facts = Str::before($dialog, 'Recorded Punches');

    expect($page->viewData('correction')->shift->overnight)->toBeTrue()
        ->and($page->viewData('correction')->location->from_roster)->toBeFalse()
        ->and($facts)->toContain('10:00 PM – 6:00 AM')
        ->toContain('2:00 AM – 3:00 AM')
        ->toContain($site->name)
        ->not->toContain('Ramon Aquino')
        ->not->toContain('class="pill')
        ->not->toContain('grace')
        ->not->toContain('Overnight')
        ->not->toContain('Assigned worksite')
        ->not->toContain('60m')
        ->and($dialog)->not->toContain('aria-label="Clear ')
        ->not->toContain('Saving replaces');
});

it('shows a corrected time as Manually Entered and keeps the captured place of a punch it left alone', function () {
    // A correction stamps its inserted punch with the date's place, but that
    // place was never captured with the new time, so the list drops it; the
    // untouched Web GPS punch keeps its own.
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $site = edtrSite();
    $employee = edtrEmployee($site);
    edtrRosterShift($employee);
    $fix = ['latitude' => 14.5995, 'longitude' => 120.9842, 'distance_meters' => 12.0, 'accuracy_meters' => 8.0];
    $in = edtrPunch($employee, $site, 'TIME_IN', '2026-09-02T10:03:00', $fix);
    edtrPunch($employee, $site, 'TIME_OUT', '2026-09-02T16:00:00', $fix);
    edtrActor(['EDTR'], allData: true);

    $page = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', '2026-09-02')
        ->set('correctionForm.time_out', '17:00')
        ->call('saveCorrection')
        ->assertSet('correctionError', null)
        ->call('openCorrection', '2026-09-02');

    $corrected = AttendanceLog::query()->where('source', AttendanceLog::SOURCE_CORRECTION)->sole();
    expect($corrected->site_id)->toBe($site->getKey());

    $dialog = Str::after($page->html(), 'Recorded Punches');
    $keptRow = Str::before(Str::after($dialog, 'wire:key="correct-punch-'.$in->getKey().'"'), '</tr>');
    $correctedRow = Str::before(Str::after($dialog, 'wire:key="correct-punch-'.$corrected->getKey().'"'), '</tr>');
    expect($keptRow)->toContain('>10:03 AM<')
        ->toContain($site->name)
        ->toContain('google.com/maps?q=14.5995,120.9842')
        ->not->toContain('Manually Entered')
        ->and($correctedRow)->toContain('>5:00 PM<')
        ->toContain('Manually Entered')
        ->not->toContain($site->name)
        ->not->toContain('google.com/maps');
});

it('folds the empty middle slots on a shift with no scheduled break without dropping them', function (bool $scheduledBreak) {
    $this->travelTo(new DateTimeImmutable('2026-09-02T18:00:00+08:00'));
    $employee = edtrEmployee(edtrSite());
    edtrRosterShift($employee, $scheduledBreak ? [] : ['break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
    edtrActor(['EDTR'], allData: true);

    $html = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', '2026-09-01')
        ->html();

    // Folded on the client only: all four boxes are always in the server HTML.
    expect($html)->toContain('id="correct-break_out-time"')->toContain('id="correct-break_in-time"')
        ->and(substr_count($html, 'x-show="middle"'))->toBe($scheduledBreak ? 0 : 2)
        ->and(str_contains($html, 'Add Break Punches'))->toBe(! $scheduledBreak)
        ->and($html)->toContain($scheduledBreak ? 'data-middle="1"' : 'data-middle="0"');
})->with([
    'a scheduled break' => [true],
    'no scheduled break' => [false],
]);
