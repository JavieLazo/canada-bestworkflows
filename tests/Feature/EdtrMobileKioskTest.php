<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EdtrMobileToken;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\IdentityAccess\Application\MobileEdtrSession;
use App\Modules\Workforce\Application\Queries\KioskEmployees;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

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

    return $employee;
}

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
});

it('searches staff and syncs an offline biometric punch after the admin signs out', function () {
    $admin = edtrMobileUser(edtrMobileRole('System Admin'), 'kiosk-admin@ems.test');
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

    $this->withToken($adminToken)
        ->getJson('/api/mobile/edtr/employees?q=Domingo')
        ->assertOk()
        ->assertJsonPath('employees.0.id', (string) $employee->getKey())
        ->assertJsonPath('employees.0.name', 'Liza Domingo');

    $this->withToken($kioskToken)
        ->getJson('/api/mobile/edtr/employees?q=Domingo')
        ->assertUnauthorized();

    $captured = CarbonImmutable::now('UTC')->subMinute()->milliseconds(0);
    $clientId = (string) Str::uuid();
    $futureId = (string) Str::uuid();

    $synced = $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [
            [
                'client_id' => $futureId,
                'employee_id' => (string) $employee->getKey(),
                'log_type' => 'TIME_IN',
                'logged_at' => CarbonImmutable::now('UTC')->addHour()->toIso8601String(),
            ],
            [
                'client_id' => $clientId,
                'employee_id' => (string) $employee->getKey(),
                'log_type' => 'TIME_IN',
                'logged_at' => $captured->toIso8601String(),
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy' => 12,
            ],
        ],
    ])->assertOk();

    expect($synced->json('results.0.status'))->toBe('synced')
        ->and($synced->json('results.0.client_id'))->toBe($clientId)
        ->and($synced->json('results.1.status'))->toBe('rejected');

    $log = AttendanceLog::query()->sole();
    expect($log->source)->toBe('BIOMETRIC')
        ->and($log->log_type)->toBe('TIME_IN')
        ->and($log->employee_id)->toBe((string) $employee->getKey())
        ->and(abs($log->logged_at->getTimestamp() - $captured->getTimestamp()))->toBeLessThan(2);

    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [[
            'client_id' => $clientId,
            'employee_id' => (string) $employee->getKey(),
            'log_type' => 'TIME_IN',
            'logged_at' => $captured->toIso8601String(),
        ]],
    ])->assertOk()->assertJsonPath('results.0.status', 'synced');

    expect(AttendanceLog::query()->count())->toBe(1);

    $this->withToken($adminToken)->postJson('/api/mobile/edtr/logout')->assertOk();
    $this->withToken($adminToken)->getJson('/api/mobile/edtr/employees?q=Domingo')->assertUnauthorized();

    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [[
            'client_id' => (string) Str::uuid(),
            'employee_id' => (string) $employee->getKey(),
            'log_type' => 'TIME_OUT',
            'logged_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy' => 8,
        ]],
    ])->assertOk()->assertJsonPath('results.0.status', 'synced');

    expect(AttendanceLog::query()->where('log_type', 'TIME_OUT')->count())->toBe(1);
});

it('lets a staff member register only their own face and leaves punch sync in place', function () {
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

    $this->withToken($kioskToken)->postJson('/api/mobile/edtr/punches', [
        'punches' => [[
            'client_id' => (string) Str::uuid(),
            'employee_id' => (string) $mine->getKey(),
            'log_type' => 'TIME_IN',
            'logged_at' => CarbonImmutable::now('UTC')->subMinute()->toIso8601String(),
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy' => 10,
        ]],
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
    expect($logouts())->toBe(0);
    $this->withToken($login->json('admin_token'))->getJson('/api/mobile/edtr/employees')->assertOk();

    $sessions->signOut(Access::forActor($admin), $tokenId);
    expect($logouts())->toBe(1);
    $this->withToken($login->json('admin_token'))->getJson('/api/mobile/edtr/employees')->assertUnauthorized();
});
