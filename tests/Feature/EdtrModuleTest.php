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
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The EDTR module: the /edtr route gates on the module grant, the scoped
 * queries return NOTHING for an employee-less actor (the ScopesToActor
 * regression the starter pack calls non-negotiable), and the two write flows —
 * a kiosk punch and a day correction — land rows, audit entries and remarks.
 */
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
        'employee_code' => 'BWF-'.random_int(10000, 99999),
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

/* ── the kiosk punch flow ────────────────────────────────────────────────── */

it('records a kiosk punch pair, audits it, and refuses a duplicate Time In', function () {
    $site = edtrSite(exempt: true);
    $employee = edtrEmployee($site);
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

/** Every statement the callback issued, as SQL. */
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
    preg_match_all('/<td class="num text-right ?([a-z-]*)">([^<]*)</', $row, $cells, PREG_SET_ORDER);

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

    // And no overflow of any kind between the card and the table, or the
    // sticky header pins to a box that never scrolls instead of to the page.
    $card = Str::before(Str::after($html, 'hris-table-flush'), '</table>');

    expect($card)->toContain('<table class="hris-table">')
        ->and($card)->not->toContain('hris-table-scroll')
        ->and($card)->not->toContain('hris-table-wrap')
        ->and($card)->not->toContain('overflow-hidden');
});
