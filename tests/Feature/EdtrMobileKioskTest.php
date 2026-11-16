<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EdtrMobileToken;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\IdentityAccess\Application\MobileEdtrSession;
use App\Modules\Organization\Application\Queries\KioskSites;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow;
use App\Modules\Workforce\Application\Queries\KioskEmployees;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\PunchShiftFixture;

/**
 * The biometric kiosk API (`/api/mobile/edtr/*`): the one door every
 * biometric device uses. A device has no browser session, so each request is
 * authorised by a bearer secret and the account behind it is read again every
 * time. ONLY A SYSTEM ADMIN'S SIGN-IN LETS A DEVICE SEND PUNCHES; anyone else
 * reaches only their own record. A punch is recorded through the eDTR
 * station's own rules at the instant the device captured it, once per
 * device-side id, and never ahead of the server clock. A punch that arrives
 * on a later day than it was captured is refused when a posted payslip
 * already covers its day. Biometric data never reaches the server.
 */
function edtrMobileRole(string $name, bool $allData = true, bool $edtr = true): Role
{
    $role = new Role;
    $role->forceFill([
        'name' => $name,
        'all_data' => $allData,
        'is_system' => $name === 'System Admin',
    ])->save();

    if ($edtr) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => ModuleCode::EDTR])->save();
    }

    return $role;
}

function edtrMobileUser(Role $role, string $email, bool $active = true, ?string $employeeId = null): User
{
    return User::factory()->create([
        'email' => $email,
        'role_id' => $role->getKey(),
        'is_active' => $active,
        'employee_id' => $employeeId,
    ]);
}

function edtrMobileEmployee(string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
{
    $site = WorkSite::query()->create([
        'name' => 'Kiosk Site '.Str::random(4),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => true,
    ]);

    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.random_int(10000, 99999),
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
        'work_site_id' => $site->getKey(),
    ])->save();
    // A Time In is refused on a date with no shift, on a device as at the station.
    PunchShiftFixture::anyHour((string) $employee->getKey());

    return $employee;
}

/**
 * One punch row as a device sends it.
 *
 * @return array<string, mixed>
 */
function edtrMobilePunch(EmployeeProfile $employee, string $logType, CarbonImmutable $at, ?string $clientId = null): array
{
    return [
        'client_id' => $clientId ?? (string) Str::uuid(),
        'employee_id' => (string) $employee->getKey(),
        'log_type' => $logType,
        'logged_at' => $at->toIso8601String(),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'accuracy' => 12,
    ];
}

/** A regular payroll posted for the employee over the given dates. */
function edtrMobilePayslip(EmployeeProfile $employee, string $start, string $end): Payslip
{
    $run = PayrollRun::create(['code' => 'PR-'.Str::random(5), 'name' => 'Posted '.Str::random(5), 'status' => 'FINALIZED',
        'period_start' => $start, 'period_end' => $end, 'kind' => PayrollRunKind::REGULAR]);
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => (string) $employee->getKey(),
        'period_start' => $start, 'period_end' => $end, 'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();

    return $payslip;
}

/*
|--------------------------------------------------------------------------
| Sign-in
|--------------------------------------------------------------------------
*/

it('rejects a bad password, a disabled account, and a staff account with no employee', function () {
    $admins = edtrMobileRole('System Admin');
    $admin = edtrMobileUser($admins, 'kiosk-admin@ems.test');
    $hr = edtrMobileUser(edtrMobileRole('HR Officer'), 'kiosk-hr@ems.test');
    $disabled = edtrMobileUser($admins, 'kiosk-off@ems.test', active: false);
    $device = (string) Str::uuid();

    $this->postJson('/api/mobile/edtr/login', [
        'email' => 'missing@ems.test',
        'password' => 'password',
        'device_id' => $device,
    ])->assertUnauthorized();

    $this->postJson('/api/mobile/edtr/login', [
        'email' => $hr->email,
        'password' => 'password',
        'device_id' => $device,
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'This account is not linked to an employee record.');

    $this->postJson('/api/mobile/edtr/login', [
        'email' => $disabled->email,
        'password' => 'password',
        'device_id' => $device,
    ])->assertForbidden();

    $this->postJson('/api/mobile/edtr/login', [
        'email' => $admin->email,
        'password' => 'wrong-password',
        'device_id' => $device,
    ])->assertUnauthorized();

    expect(EdtrMobileToken::query()->count())->toBe(0);
});

it('asks for the authenticator code of an account that confirmed a second factor', function () {
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $admin = edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-2fa@ems.test');
    $admin->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $login = fn (?string $code) => $this->postJson('/api/mobile/edtr/login', array_filter([
        'email' => $admin->email,
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
        'code' => $code,
    ], static fn ($value): bool => $value !== null));

    $login(null)->assertUnprocessable()
        ->assertJsonPath('message', 'Enter the current authenticator code for this account.');
    $login('000 000')->assertUnprocessable();
    expect(EdtrMobileToken::query()->count())->toBe(0);

    $login((new Google2FA)->getCurrentOtp($secret))->assertOk();

    // A recovery code signs in once and is replaced.
    $login('recovery-code-1')->assertOk();
    $login('recovery-code-1')->assertUnprocessable();
});

/*
|--------------------------------------------------------------------------
| Registering staff and sending punches
|--------------------------------------------------------------------------
*/

it('searches staff and syncs an offline biometric punch after the admin signs out', function () {
    edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $employee = edtrMobileEmployee();
    $device = (string) Str::uuid();

    $login = $this->postJson('/api/mobile/edtr/login', [
        'email' => 'KIOSK-ADMIN@ems.test',
        'password' => 'password',
        'device_id' => $device,
    ])->assertOk();

    $adminToken = $login->json('admin_token');
    $kioskToken = $login->json('kiosk_token');
    expect($adminToken)->toBeString()->not->toBe($kioskToken)
        ->and($login->json('account.role'))->toBe('System Admin')
        ->and($login->json('account.system_admin'))->toBeTrue();

    // Only a hash of each secret is kept.
    expect(EdtrMobileToken::query()->where('token_hash', $adminToken)->exists())->toBeFalse()
        ->and(EdtrMobileToken::query()->where('token_hash', hash('sha256', $adminToken))->exists())->toBeTrue();

    $this->withToken($adminToken)
        ->getJson('/api/mobile/edtr/employees?q=Domingo')
        ->assertOk()
        ->assertJsonPath('employees.0.id', (string) $employee->getKey())
        ->assertJsonPath('employees.0.name', 'Liza Domingo');

    // The punch secret does not open the staff list, and a request with no secret opens nothing.
    $this->withToken($kioskToken)->getJson('/api/mobile/edtr/employees?q=Domingo')->assertUnauthorized();
    $this->flushHeaders()->getJson('/api/mobile/edtr/employees?q=Domingo')->assertUnauthorized();
    $this->flushHeaders()->postJson('/api/mobile/edtr/punches', ['punches' => [edtrMobilePunch($employee, 'TIME_IN', CarbonImmutable::now('UTC'))]])
        ->assertUnauthorized();
    expect(AttendanceLog::query()->count())->toBe(0);

    $captured = CarbonImmutable::now('UTC')->subMinute()->milliseconds(0);
    $clientId = (string) Str::uuid();

    $synced = $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [
            edtrMobilePunch($employee, 'TIME_IN', CarbonImmutable::now('UTC')->addHour()),
            edtrMobilePunch($employee, 'TIME_IN', $captured, $clientId),
        ],
    ])->assertOk();

    // Rows are judged in the order they were captured, and one ahead of the server clock is refused.
    expect($synced->json('results.0.status'))->toBe('synced')
        ->and($synced->json('results.0.client_id'))->toBe($clientId)
        ->and($synced->json('results.1.status'))->toBe('rejected')
        ->and($synced->json('results.1.message'))->toBe('That punch time is ahead of the server clock.');

    $log = AttendanceLog::query()->sole();
    expect($log->source)->toBe('BIOMETRIC')
        ->and($log->log_type)->toBe('TIME_IN')
        ->and($log->employee_id)->toBe((string) $employee->getKey())
        ->and(abs($log->logged_at->getTimestamp() - $captured->getTimestamp()))->toBeLessThan(2)
        ->and($synced->json('results.0.log_id'))->toBe((string) $log->getKey());
    $this->assertDatabaseHas('audit_logs', ['action' => 'PUNCH_TIME_IN', 'entity_id' => (string) $log->getKey()]);

    // The same punch sent again is answered from its receipt, not recorded twice.
    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [edtrMobilePunch($employee, 'TIME_IN', $captured, $clientId)],
    ])->assertOk()
        ->assertJsonPath('results.0.status', 'synced')
        ->assertJsonPath('results.0.log_id', (string) $log->getKey());

    expect(AttendanceLog::query()->count())->toBe(1);

    $this->withToken($adminToken)->postJson('/api/mobile/edtr/logout')->assertOk();
    $this->withToken($adminToken)->getJson('/api/mobile/edtr/employees?q=Domingo')->assertUnauthorized();

    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [edtrMobilePunch($employee, 'TIME_OUT', CarbonImmutable::now('UTC'))],
    ])->assertOk()->assertJsonPath('results.0.status', 'synced');

    expect(AttendanceLog::query()->where('log_type', 'TIME_OUT')->count())->toBe(1);
});

it('answers each refused punch with the station\'s own reason and records nothing for it', function () {
    $admin = edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $employee = edtrMobileEmployee();
    $inactive = edtrMobileEmployee('Ramon', 'Cruz');
    $inactive->forceFill(['is_active' => false])->save();
    $kioskToken = $this->postJson('/api/mobile/edtr/login', [
        'email' => $admin->email,
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk()->json('kiosk_token');
    $now = CarbonImmutable::now('UTC')->subMinute();

    $results = $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [
            edtrMobilePunch($inactive, 'TIME_IN', $now),
            // A Time Out with no Time In open.
            edtrMobilePunch($employee, 'TIME_OUT', $now->addSecond()),
            ['client_id' => (string) Str::uuid(), 'employee_id' => 'no-such-employee', 'log_type' => 'TIME_IN', 'logged_at' => $now->addSeconds(2)->toIso8601String()],
            ['client_id' => (string) Str::uuid(), 'employee_id' => (string) $employee->getKey(), 'log_type' => 'TIME_IN', 'logged_at' => 'not a time'],
        ],
    ])->assertOk()->json('results');

    expect(array_column($results, 'status'))->toBe(['rejected', 'rejected', 'rejected', 'rejected'])
        ->and(array_column($results, 'log_id'))->toBe([null, null, null, null])
        ->and(array_column($results, 'message'))->toContain('That employee is inactive and cannot punch.')
        ->and(array_column($results, 'message'))->toContain('That employee was not found.')
        ->and(array_column($results, 'message'))->toContain('That punch time could not be read.')
        ->and(AttendanceLog::query()->count())->toBe(0);
    $this->assertDatabaseCount('edtr_mobile_punch_receipts', 0);

    // A body that is not a list of punches is refused before anything is read.
    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', ['punches' => []])->assertUnprocessable();
    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [['client_id' => 'not-a-uuid', 'employee_id' => (string) $employee->getKey(), 'log_type' => 'BREAK_OUT', 'logged_at' => $now->toIso8601String()]],
    ])->assertUnprocessable();
});

it('lets a staff member register only their own biometrics and leaves punch sync in place', function () {
    $device = (string) Str::uuid();
    $admin = edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $connected = $this->postJson('/api/mobile/edtr/login', [
        'email' => $admin->email,
        'password' => 'password',
        'device_id' => $device,
    ])->assertOk();
    $kioskToken = $connected->json('kiosk_token');

    $mine = edtrMobileEmployee('Ana', 'Reyes');
    $other = edtrMobileEmployee('Liza', 'Domingo');
    $staff = edtrMobileUser(edtrMobileRole('Employee'), 'kiosk-staff@ems.test', employeeId: (string) $mine->getKey());

    $login = $this->postJson('/api/mobile/edtr/login', [
        'email' => $staff->email,
        'password' => 'password',
        'device_id' => $device,
    ])->assertOk();

    expect($login->json('account.system_admin'))->toBeFalse()
        ->and($login->json('kiosk_token'))->toBeNull()
        ->and($login->json('account.employee.id'))->toBe((string) $mine->getKey())
        ->and($login->json('account.employee.name'))->toBe('Ana Reyes');

    $this->withToken($login->json('admin_token'))
        ->getJson('/api/mobile/edtr/employees?q=Domingo')
        ->assertOk()
        ->assertJsonCount(1, 'employees')
        ->assertJsonPath('employees.0.id', (string) $mine->getKey())
        ->assertJsonMissing(['id' => (string) $other->getKey()]);

    // The staff sign-in replaced the administrator's registration secret only.
    $this->withToken($connected->json('admin_token'))->getJson('/api/mobile/edtr/employees')->assertUnauthorized();
    // A registration secret never sends punches.
    $this->withToken($login->json('admin_token'))->postJson('/api/mobile/edtr/punches', [
        'punches' => [edtrMobilePunch($mine, 'TIME_IN', CarbonImmutable::now('UTC')->subMinute())],
    ])->assertUnauthorized();
    expect(AttendanceLog::query()->count())->toBe(0);

    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [edtrMobilePunch($mine, 'TIME_IN', CarbonImmutable::now('UTC')->subMinute())],
    ])->assertOk()->assertJsonPath('results.0.status', 'synced');
});

it('searches all staff only for eDTR all-data and otherwise reaches only the own record', function () {
    $mine = edtrMobileEmployee('Ana', 'Reyes');
    $other = edtrMobileEmployee('Liza', 'Domingo');
    $kiosk = Access::forActor(edtrMobileUser(edtrMobileRole('Kiosk Manager'), 'kiosk-manager@ems.test'));
    $noEdtr = Access::forActor(edtrMobileUser(edtrMobileRole('System Admin', edtr: false), 'kiosk-noedtr@ems.test'));
    $staff = Access::forActor(edtrMobileUser(edtrMobileRole('Employee', allData: false), 'kiosk-staff@ems.test', employeeId: (string) $mine->getKey()));
    $query = app(KioskEmployees::class);

    expect(array_column($query->search($kiosk, 'Domingo'), 'id'))->toBe([(string) $other->getKey()])
        ->and(count($query->search($kiosk, '')))->toBe(2)
        ->and($query->search($noEdtr, 'Domingo'))->toBe([])
        ->and($query->search($staff, 'Domingo'))->toBe([])
        ->and($query->search(Access::forGuest(), ''))->toBe([])
        ->and($query->own($staff)['id'] ?? null)->toBe((string) $mine->getKey())
        ->and($query->own($kiosk))->toBeNull()
        ->and($query->own(Access::forGuest()))->toBeNull();

    // A System Admin whose role lacks eDTR all-data still signs in, and is
    // answered with no staff: punches for others would be refused anyway.
    $login = $this->postJson('/api/mobile/edtr/login', [
        'email' => 'kiosk-noedtr@ems.test',
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk();

    $this->withToken($login->json('admin_token'))
        ->getJson('/api/mobile/edtr/employees?q=Domingo')
        ->assertOk()
        ->assertJsonCount(0, 'employees');
});

it('lists the whole roster to a device, not a first page of it', function () {
    $kiosk = Access::forActor(edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test'));
    $site = edtrMobileSite('Main Office', 14.5995, 120.9842);
    // More people than a device once got in one answer. It has no next page to ask for.
    for ($n = 1; $n <= 71; $n++) {
        $employee = new EmployeeProfile;
        $employee->forceFill([
            'employee_code' => 'COP-'.(50000 + $n),
            'first_name' => 'Staff',
            'last_name' => sprintf('Member %03d', $n),
            'position' => 'Clerk',
            'employment_status' => 'Regular',
            'date_hired' => '2024-01-01',
            'is_active' => true,
            'work_site_id' => (string) $site->getKey(),
        ])->save();
    }

    $all = app(KioskEmployees::class)->search($kiosk, '');

    expect($all)->toHaveCount(71)
        ->and($all[0]['name'])->toBe('Staff Member 001')
        ->and($all[70]['name'])->toBe('Staff Member 071');
});

/*
|--------------------------------------------------------------------------
| The site a device stands at
|--------------------------------------------------------------------------
*/

/** A site with a pin and a radius: its punches are measured. */
function edtrMobileSite(string $name, float $latitude, float $longitude, bool $active = true): WorkSite
{
    $site = WorkSite::query()->create([
        'name' => $name,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'radius_meters' => 150,
        'geofence_exempt' => false,
    ]);
    if (! $active) {
        $site->forceFill(['is_active' => false])->save();
    }

    return $site;
}

it('lists the sites in use to a System Admin setting up a device, and to nobody else', function () {
    edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $main = edtrMobileSite('Main Office', 14.5995, 120.9842);
    $branch = edtrMobileSite('Branch Office', 16.6159, 120.3209);
    edtrMobileSite('Closed Annex', 15.1, 120.6, active: false);
    $employee = edtrMobileEmployee();
    $employee->forceFill(['work_site_id' => (string) $main->getKey()])->save();
    $staff = edtrMobileUser(edtrMobileRole('Employee', allData: false), 'kiosk-staff@ems.test', employeeId: (string) $employee->getKey());

    $login = $this->postJson('/api/mobile/edtr/login', [
        'email' => 'kiosk-admin@ems.test',
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk();

    // By name, each with its pin and radius; a retired site takes no new device.
    $sites = $this->withToken($login->json('admin_token'))->getJson('/api/mobile/edtr/sites')->assertOk()->json('sites');
    $listed = array_values(array_filter($sites, static fn (array $site): bool => in_array($site['name'], ['Main Office', 'Branch Office', 'Closed Annex'], true)));
    expect($listed)->toBe([
        ['id' => (string) $branch->getKey(), 'name' => 'Branch Office', 'latitude' => 16.6159, 'longitude' => 120.3209, 'radius_meters' => 150, 'geofence_exempt' => false],
        ['id' => (string) $main->getKey(), 'name' => 'Main Office', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 150, 'geofence_exempt' => false],
    ]);

    // The punch secret does not open the list, and a request with no secret opens nothing.
    $this->withToken($login->json('kiosk_token'))->getJson('/api/mobile/edtr/sites')->assertUnauthorized();
    $this->flushHeaders()->getJson('/api/mobile/edtr/sites')->assertUnauthorized();

    // A staff member registering their own face does not choose where the device stands.
    $own = $this->postJson('/api/mobile/edtr/login', [
        'email' => $staff->email,
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk();
    $this->withToken($own->json('admin_token'))->getJson('/api/mobile/edtr/sites')->assertOk()->assertJsonCount(0, 'sites');

    // Nor does a role without eDTR all-data, System Admin by name or not.
    $query = app(KioskSites::class);
    expect($query->all(Access::forActor(edtrMobileUser(edtrMobileRole('Site Admin', edtr: false), 'kiosk-noedtr@ems.test'))))->toBe([])
        ->and($query->all(Access::forActor($staff)))->toBe([])
        ->and($query->all(Access::forGuest()))->toBe([]);
});

it('keeps a device set up at a site to the people who work there that day', function () {
    edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $main = edtrMobileSite('Main Office', 14.5995, 120.9842);
    $branch = edtrMobileSite('Branch Office', 16.6159, 120.3209);
    $employee = edtrMobileEmployee();
    $employee->forceFill(['work_site_id' => (string) $main->getKey()])->save();

    $kioskToken = $this->postJson('/api/mobile/edtr/login', [
        'email' => 'kiosk-admin@ems.test',
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk()->json('kiosk_token');
    $at = CarbonImmutable::now('UTC')->subMinute();
    // Each device sends the site it was set up at, with that site's own pin.
    $from = static fn (WorkSite $site, string $logType, CarbonImmutable $when): array => array_merge(edtrMobilePunch($employee, $logType, $when), [
        'site_id' => (string) $site->getKey(), 'latitude' => $site->latitude, 'longitude' => $site->longitude, 'accuracy' => null,
    ]);

    // At the other office's device: refused by name, although the device stands inside its own site.
    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', ['punches' => [$from($branch, 'TIME_IN', $at)]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.message', 'Liza Domingo is assigned to Main Office.');
    expect(AttendanceLog::query()->count())->toBe(0);

    // At their own office's device: recorded at that site.
    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', ['punches' => [$from($main, 'TIME_IN', $at)]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'synced')
        ->assertJsonPath('results.0.message', 'TIME IN recorded — inside Main Office.');
    $log = AttendanceLog::query()->sole();
    expect($log->site_id)->toBe((string) $main->getKey())
        ->and($log->is_geofenced)->toBeTrue()
        ->and($log->source)->toBe('BIOMETRIC');

    // A site the system does not know is nobody's site.
    $unknown = array_merge(edtrMobilePunch($employee, 'TIME_OUT', CarbonImmutable::now('UTC')), ['site_id' => '01HZX000000000000000NOSITE']);
    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', ['punches' => [$unknown]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.message', 'Liza Domingo is assigned to Main Office.');

    // A device that names no site is still judged by its coordinates alone.
    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', ['punches' => [edtrMobilePunch($employee, 'TIME_OUT', CarbonImmutable::now('UTC'))]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'synced');
    expect(AttendanceLog::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Days already paid
|--------------------------------------------------------------------------
*/

it('refuses a punch a device sends late for a day a posted payslip already covers', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-20T12:00:00+08:00'));
    $admin = edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $employee = edtrMobileEmployee();
    edtrMobilePayslip($employee, '2026-09-01', '2026-09-15');
    $kioskToken = $this->postJson('/api/mobile/edtr/login', [
        'email' => $admin->email,
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk()->json('kiosk_token');
    $paid = CarbonImmutable::parse('2026-09-10 08:00:00', ManilaClock::TZ);
    $open = CarbonImmutable::parse('2026-09-17 08:00:00', ManilaClock::TZ);

    $results = $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [
            edtrMobilePunch($employee, 'TIME_IN', $paid),
            edtrMobilePunch($employee, 'TIME_IN', $open),
        ],
    ])->assertOk()->json('results');

    expect($results[0]['status'])->toBe('rejected')
        ->and($results[0]['message'])->toBe(CorrectionWindow::PAID_DAY)
        ->and($results[1]['status'])->toBe('synced');

    // The day after the posted period is still open, and keeps the time the device captured.
    $log = AttendanceLog::query()->sole();
    expect(ManilaClock::dayKey($log->logged_at))->toBe('2026-09-17')
        ->and($log->logged_at->getTimestamp())->toBe($open->getTimestamp());
    $this->assertDatabaseCount('edtr_mobile_punch_receipts', 1);
});

it('judges a device punch captured today like the live station, whatever payroll has posted', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-20T12:00:00+08:00'));
    $admin = edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $employee = edtrMobileEmployee();
    // A payroll posted through today.
    edtrMobilePayslip($employee, '2026-09-16', '2026-09-20');
    $kioskToken = $this->postJson('/api/mobile/edtr/login', [
        'email' => $admin->email,
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk()->json('kiosk_token');
    $send = fn (CarbonImmutable $at) => $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [edtrMobilePunch($employee, 'TIME_IN', $at)],
    ])->assertOk();

    // Yesterday's capture arrives late and its day is paid.
    $send(CarbonImmutable::parse('2026-09-19 08:00:00', ManilaClock::TZ))
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.message', CorrectionWindow::PAID_DAY);
    expect(AttendanceLog::query()->count())->toBe(0);

    // Today's capture is taken, as the station's own Time In would be.
    $send(CarbonImmutable::now('UTC')->subMinute())->assertJsonPath('results.0.status', 'synced');
    app(RecordPunch::class)->handle(
        Access::forActor($admin),
        new PunchInput((string) edtrMobileEmployee('Ana', 'Reyes')->getKey(), '', 'MANUAL', false, '', ''),
        'TIME_IN',
    );
    expect(AttendanceLog::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Losing the device
|--------------------------------------------------------------------------
*/

it('signs out only a registration secret the actor holds, and audits only a real sign-out', function () {
    $admin = edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $other = edtrMobileUser(edtrMobileRole('Kiosk Manager'), 'kiosk-other@ems.test');
    $login = $this->postJson('/api/mobile/edtr/login', [
        'email' => $admin->email,
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk();
    $tokenId = (string) EdtrMobileToken::query()
        ->where('user_id', $admin->getKey())
        ->where('ability', EdtrMobileToken::ABILITY_ADMIN)
        ->value('id');
    $logouts = static fn (): int => AuditLog::query()->where('action', AuditEvent::LOGOUT->value)->count();
    $sessions = app(MobileEdtrSession::class);

    $sessions->signOut(Access::forActor($other), $tokenId);
    $sessions->signOut(Access::forGuest(), $tokenId);
    expect($logouts())->toBe(0);
    $this->withToken($login->json('admin_token'))->getJson('/api/mobile/edtr/employees')->assertOk();

    $sessions->signOut(Access::forActor($admin), $tokenId);
    expect($logouts())->toBe(1);
    $this->withToken($login->json('admin_token'))->getJson('/api/mobile/edtr/employees')->assertUnauthorized();

    // A second sign-out of the same secret changes nothing and audits nothing.
    $sessions->signOut(Access::forActor($admin), $tokenId);
    expect($logouts())->toBe(1);
});

it('takes the device from an account that lost the System Admin role or was disabled', function () {
    $admin = edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
    $employee = edtrMobileEmployee();
    $kioskToken = $this->postJson('/api/mobile/edtr/login', [
        'email' => $admin->email,
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertOk()->json('kiosk_token');
    $send = fn () => $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [edtrMobilePunch($employee, 'TIME_IN', CarbonImmutable::now('UTC')->subMinute())],
    ]);

    $admin->forceFill(['role_id' => edtrMobileRole('Kiosk Manager')->getKey()])->save();
    $send()->assertForbidden()->assertJsonPath('message', 'System Admin access is required.');

    $admin->forceFill(['role_id' => Role::query()->where('name', 'System Admin')->value('id'), 'is_active' => false])->save();
    $send()->assertForbidden()->assertJsonPath('message', 'This account is disabled.');

    expect(AttendanceLog::query()->count())->toBe(0);

    $admin->forceFill(['is_active' => true])->save();
    $send()->assertOk()->assertJsonPath('results.0.status', 'synced');
});

/*
|--------------------------------------------------------------------------
| Browser clients
|--------------------------------------------------------------------------
*/

it('answers a web page on another domain, so a browser portal can call the API', function () {
    $origin = 'https://biometrics.example';

    // The check a browser makes before it sends a cross-site request with a bearer secret.
    $this->call('OPTIONS', '/api/mobile/edtr/punches', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
    ])->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Headers', 'authorization,content-type');

    // The answer itself must carry the header too, or the browser hides it from the page.
    $this->withHeader('Origin', $origin)->postJson('/api/mobile/edtr/login', [
        'email' => 'missing@ems.test',
        'password' => 'password',
        'device_id' => (string) Str::uuid(),
    ])->assertUnauthorized()
        ->assertHeader('Access-Control-Allow-Origin', '*');

    // The signed-in pages stay closed to other sites.
    expect($this->withHeader('Origin', $origin)->get('/login')->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});
