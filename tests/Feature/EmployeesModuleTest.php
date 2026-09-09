<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\AddEmployeeDialog;
use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Employees\Index;
use App\Livewire\Employees\Show;
use App\Models\AuditLog;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\Access;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * EMPLOYEES module: route gating, actor scoping, and the hire happy path.
 *
 * NOTE: these tests exercise the real Access path, which reads the account's
 * role and grants through `User::role()` — a relation owned by the accounts
 * foundation. They fail loudly (RelationNotFoundException) if that relation
 * is missing, which is the correct signal: no module works without it.
 */
function employeesRole(array $modules, bool $allData): Role
{
    $role = Role::query()->create([
        'name' => 'Test '.implode('-', $modules).($allData ? ' all' : ''),
        'all_data' => $allData,
        'is_system' => false,
    ]);

    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

function employeesUser(Role $role, ?EmployeeProfile $employee = null): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'role_id' => $role->id,
        'employee_id' => $employee?->id,
    ])->save();

    return $user->refresh();
}

function seedEmployeesReferences(): WorkSite
{
    Position::query()->create(['code' => 'POS-001', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-001', 'name' => 'Probationary']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-002', 'name' => 'Regular']);

    return WorkSite::query()->create([
        'name' => 'Head Office',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 100,
        'geofence_exempt' => false,
    ]);
}

function seedEmployeeRecord(?string $siteId = null): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => 'BWF-00001',
        'first_name' => 'Liza',
        'last_name' => 'Domingo',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'work_site_id' => $siteId,
        'monthly_rate' => 30000,
    ]);
}

it('redirects guests to the login page', function () {
    $this->get('/employees')->assertRedirect();
});

it('gates the pages on the EMPLOYEES module grant', function () {
    $user = employeesUser(employeesRole([ModuleCode::PORTAL], false));

    // No EMPLOYEES grant: bounced to the landing path, never rendered.
    $this->actingAs($user)->get('/employees')->assertRedirect('/portal');

    $granted = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($granted)
        ->get('/employees')
        ->assertOk()
        ->assertSee('Employee Records');
});

it('shows an employee-less actor zero rows even though records exist', function () {
    seedEmployeesReferences();
    seedEmployeeRecord();

    // Holds the module but NOT all_data and has no employee link: the
    // ROWS_NONE arm — an empty list, never the whole table.
    $user = employeesUser(employeesRole([ModuleCode::EMPLOYEES], false));

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->assertDontSee('Domingo')
        ->assertSee('No employee matches that search.');
});

it('returns an empty scoped document query for an employee-less actor', function () {
    seedEmployeesReferences();
    $employee = seedEmployeeRecord();

    EmployeeDocument::query()->create([
        'employee_id' => $employee->id,
        'name' => 'Contract',
        'category' => 'Contract',
        'file_name' => 'contract.pdf',
        'mime' => 'application/pdf',
        'size' => 1000,
        'path' => 'employee-documents/contract.pdf',
    ]);

    $actor = new Access(
        userId: '1',
        employeeId: null,
        fullName: 'Unlinked',
        roleName: 'Test',
        allData: false,
        modules: [ModuleCode::EMPLOYEES],
    );

    $count = EmployeeDocument::query()
        ->toEmployee($actor, 'employee_id', ModuleCode::EMPLOYEES)
        ->count();

    expect($count)->toBe(0);
});

it('hires an employee through the wizard and audits it', function () {
    $site = seedEmployeesReferences();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->set('form.first_name', 'Juan')
        ->set('form.last_name', 'Dela Cruz')
        ->set('form.phone', '09171234567')
        ->set('form.email', 'juan@company.com')
        ->set('form.birth_date', '1990-05-01')
        ->set('form.address', '123 Mabini St.')
        ->set('form.sex', 'Male')
        ->set('form.civil_status', 'Single')
        ->set('form.emergency_name', 'Maria Dela Cruz')
        ->set('form.emergency_relation', 'Spouse')
        ->set('form.emergency_phone', '09179876543')
        ->set('form.position', 'Driver')
        ->set('form.employment_status', 'Probationary')
        ->set('form.work_site_id', $site->id)
        ->set('form.date_hired', '2026-08-01')
        ->set('form.monthly_rate', '25000')
        ->call('create')
        ->assertDispatched('employee-created')
        ->assertSet('open', false);

    $created = EmployeeProfile::query()->where('email', 'juan@company.com')->first();

    expect($created)->not->toBeNull();
    expect($created->employee_code)->toBe('BWF-00001');
    expect($created->position)->toBe('Driver');
    expect($created->monthly_rate)->toBe(25000.0);
    expect($created->is_active)->toBeTrue();

    expect(
        AuditLog::query()
            ->where('action', 'EMPLOYEE_CREATED')
            ->where('entity', 'employee_profiles')
            ->where('entity_id', $created->id)
            ->exists()
    )->toBeTrue();
});

it('refuses the hire to a caller without all_data', function () {
    $site = seedEmployeesReferences();
    $user = employeesUser(employeesRole([ModuleCode::EMPLOYEES], false));

    $this->actingAs($user);

    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->set('form.first_name', 'Juan')
        ->set('form.last_name', 'Dela Cruz')
        ->call('create')
        ->assertStatus(403);

    expect(EmployeeProfile::query()->count())->toBe(0);
});

it('serves a document to its owner and refuses a stranger', function () {
    Storage::fake('local');

    seedEmployeesReferences();
    $employee = seedEmployeeRecord();
    Storage::disk('local')->put('employee-documents/contract.pdf', '%PDF-1.4 test');

    $doc = EmployeeDocument::query()->create([
        'employee_id' => $employee->id,
        'name' => 'Contract',
        'category' => 'Contract',
        'file_name' => 'contract.pdf',
        'mime' => 'application/pdf',
        'size' => 13,
        'path' => 'employee-documents/contract.pdf',
    ]);

    // The owner needs no Employees module at all — their own 201 file.
    $owner = employeesUser(employeesRole([ModuleCode::PORTAL], false), $employee);

    $this->actingAs($owner)
        ->get(route('employees.documents.download', $doc->id))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // EMPLOYEES without all_data and not the owner: forbidden.
    $stranger = employeesUser(employeesRole([ModuleCode::EMPLOYEES], false));

    $this->actingAs($stranger)
        ->get(route('employees.documents.download', $doc->id))
        ->assertForbidden();
});

it('never offers Quarterly to the server even though the select shows it', function () {
    // The reference UI lists Quarterly (employee-fields.tsx MODES) while the
    // API enum rejects it (schemas.ts:80-82) — the ported quirk under test.
    expect(collect(EmployeeFields::MODES)->pluck('value')->all())
        ->toContain('QUARTERLY');
    expect(EmployeeFields::SERVER_MODES)->not->toContain('QUARTERLY');
});

it('carries the suffix through the wizard and into the collapsed full name', function () {
    $site = seedEmployeesReferences();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->set('form.first_name', 'Juan')
        ->set('form.middle_name', 'Reyes')
        ->set('form.last_name', 'Dela Cruz')
        ->set('form.suffix', 'Jr.')
        ->set('form.phone', '09171234567')
        ->set('form.email', 'juan.jr@company.com')
        ->set('form.birth_date', '1990-05-01')
        ->set('form.address', '123 Mabini St.')
        ->set('form.sex', 'Male')
        ->set('form.civil_status', 'Single')
        ->set('form.emergency_name', 'Maria Dela Cruz')
        ->set('form.emergency_relation', 'Spouse')
        ->set('form.emergency_phone', '09179876543')
        ->set('form.position', 'Driver')
        ->set('form.employment_status', 'Probationary')
        ->set('form.work_site_id', $site->id)
        ->set('form.date_hired', '2026-08-01')
        ->set('form.monthly_rate', '25000')
        ->call('create')
        ->assertDispatched('employee-created');

    $created = EmployeeProfile::query()->where('email', 'juan.jr@company.com')->firstOrFail();

    expect($created->suffix)->toBe('Jr.');
    expect(EmployeeFields::fullName($created))->toBe('Juan Reyes Dela Cruz Jr.');

    // A blank suffix must not leave a trailing space behind.
    $created->suffix = null;
    expect(EmployeeFields::fullName($created))->toBe('Juan Reyes Dela Cruz');
});

it('saves the suffix from the record page and refuses a self-service edit of it', function () {
    seedEmployeesReferences();
    $employee = seedEmployeeRecord();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEditing', 'personal')
        ->set('form.suffix', 'III')
        ->call('saveSection', 'personal');

    expect($employee->refresh()->suffix)->toBe('III');

    // The same field on one's own record: a name part is HR-only, so the
    // section save is rejected whole, exactly as first/middle/last already are.
    $self = employeesUser(employeesRole([ModuleCode::EMPLOYEES], false), $employee);

    $this->actingAs($self);

    // Access is container-scoped and Livewire::test() bypasses the middleware
    // that drops it between requests (EnsureAccountActive), so the admin's
    // instance would otherwise answer for the second actor too.
    app()->forgetInstance(Access::class);

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEditing', 'personal')
        ->set('form.suffix', 'IV')
        ->call('saveSection', 'personal');

    expect($employee->refresh()->suffix)->toBe('III');
});

it('caps the suffix at twenty characters', function () {
    seedEmployeesReferences();
    $employee = seedEmployeeRecord();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEditing', 'personal')
        ->set('form.suffix', str_repeat('J', 21))
        ->call('saveSection', 'personal');

    expect($employee->refresh()->suffix)->toBeNull();
});
