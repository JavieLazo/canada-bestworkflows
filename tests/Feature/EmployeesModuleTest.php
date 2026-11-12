<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\AddEmployeeDialog;
use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Employees\History;
use App\Livewire\Employees\Index;
use App\Livewire\Employees\Show;
use App\Models\AuditLog;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeColumns;
use App\Modules\Workforce\Application\EmployeeInput;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\Queries\EmploymentHistoryRecords;
use App\Modules\Workforce\Domain\Contact\PhoneRule;
use App\Support\Access;
use App\Support\EmailRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * EMPLOYEES module: route gating, actor scoping, and the hire happy path.
 *
 * NOTE: these tests exercise the real Access path, which reads the account's
 * role and grants through `User::role()` — a relation owned by the accounts
 * foundation. They fail loudly (RelationNotFoundException) if that relation
 * is missing, which is the correct signal: no module works without it.
 */
/** @param list<string> $modules ModuleCode constants to grant. */
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
        'employee_code' => 'COP-00001',
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
        ->set('form.employee_id_no', '2026-001')
        ->set('form.first_name', 'Juan')
        ->set('form.last_name', 'Dela Cruz')
        ->set('form.phone', '09171234567')
        ->set('form.email', 'juan@company.com')
        ->set('form.birth_date', '1990-05-01')
        ->set('form.res_street', '123 Mabini St.')
        ->set('form.res_city', 'Tayabas City')
        ->set('form.sex', 'Male')
        ->set('form.civil_status', 'Single')
        ->set('form.emergency_name', 'Maria Dela Cruz')
        ->set('form.emergency_relation', 'Spouse')
        ->set('form.emergency_phone', '09179876543')
        ->set('form.position_id', (string) Position::query()->where('name', 'Driver')->value('id'))
        ->set('form.employment_status', 'Probationary')
        ->set('form.work_site_id', $site->id)
        ->set('form.date_hired', '2026-08-01')
        ->set('form.monthly_rate', '25000')
        ->call('create')
        ->assertDispatched('employee-created')
        ->assertSet('open', false);

    $created = EmployeeProfile::query()->where('email', 'juan@company.com')->first();

    expect($created)->not->toBeNull();
    expect($created->employee_code)->toBe('1');
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

    $history = EmployeeEmploymentHistory::query()->where('employee_id', $created->id)->sole();
    expect($history->event_type)->toBe(EmploymentHistoryRecorder::HIRED)
        ->and($history->effective_date->format('Y-m-d'))->toBe('2026-08-01')
        ->and($history->position)->toBe('Driver')
        ->and($history->employment_status)->toBe('Probationary')
        ->and($history->work_site_id)->toBe($site->id)
        ->and($history->monthly_rate)->toBe(25000.0)
        ->and($history->recorded_by)->toBe($admin->id)
        ->and($history->recorded_by_name)->toBe($admin->name);
});

it('appends employment history only when employee details change', function () {
    $site = seedEmployeesReferences();
    Position::query()->create(['code' => 'POS-002', 'name' => 'Dispatcher']);
    $employee = seedEmployeeRecord($site->id);
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    $form = EmployeeFields::toForm($employee->refresh());
    app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', $form);
    expect(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->count())->toBe(0);

    app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', [
        'position_id' => (string) Position::query()->where('name', 'Dispatcher')->value('id'),
        'date_regular' => '2024-07-01',
        'monthly_rate' => '36000',
        'daily_rate' => '1500',
    ] + $form);

    $history = EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->sole();
    expect($history->event_type)->toBe(EmploymentHistoryRecorder::UPDATED)
        ->and($history->changed_fields)->toBe('position,date_regular,monthly_rate,daily_rate')
        ->and($history->position)->toBe('Dispatcher')
        ->and($history->date_regular?->format('Y-m-d'))->toBe('2024-07-01')
        ->and($history->monthly_rate)->toBe(36000.0)
        ->and($history->daily_rate)->toBe(1500.0)
        ->and($history->recorded_by)->toBe($admin->id);

    $page = Livewire::test(History::class)->set('employeeId', $employee->id)
        ->assertSee('Employment History')
        ->assertSee('Previous value')
        ->assertSee('New value')
        ->assertSee('01 Jul 2024')
        ->assertSee('₱36,000.00')
        ->assertSee('₱1,500.00');
    // The timeline's header names today's position too, so the CHANGE is read from its own row.
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    expect((new DOMXPath($document))->evaluate('normalize-space(//*[@data-field="position"]/*[@data-after])'))->toBe('New value: Dispatcher');

    $changes = app(EmploymentHistoryRecords::class)->page(app(Access::class), $employee->id)->items()[0]->changes;
    expect(array_column($changes, 'field'))->toBe(['position', 'date_regular', 'monthly_rate', 'daily_rate'])
        ->and(array_column($changes, 'after'))->toBe(['Dispatcher', '2024-07-01', '36000.00', '1500.00']);

});

it('scopes employment history to the employee record visibility', function () {
    $site = seedEmployeesReferences();
    $own = seedEmployeeRecord($site->id);
    $other = EmployeeProfile::query()->create([
        'employee_code' => 'COP-00002',
        'first_name' => 'Mara',
        'last_name' => 'Santos',
        'employment_status' => 'Regular',
        'date_hired' => '2024-02-01',
        'work_site_id' => $site->id,
        'monthly_rate' => 32000,
    ]);
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));
    $this->actingAs($admin);
    app(EmploymentHistoryRecorder::class)->hired(app(Access::class), $own);
    app(EmploymentHistoryRecorder::class)->hired(app(Access::class), $other);

    $self = employeesUser(employeesRole([ModuleCode::EMPLOYEES], false), $own);
    $actor = Access::forActor($self);

    expect(app(EmploymentHistoryRecords::class)->forEmployee($actor, $own->id))->toHaveCount(1)
        ->and(app(EmploymentHistoryRecords::class)->forEmployee($actor, $other->id))->toHaveCount(0);

    $payrollOnly = Access::forActor(employeesUser(employeesRole([ModuleCode::PAYROLL], true)));
    expect(app(EmploymentHistoryRecords::class)->forEmployee($payrollOnly, $own->id))->toHaveCount(0);
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

it('serves a document to independent management and refuses its owner and a stranger', function () {
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

    $manager = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));
    $this->actingAs($manager)
        ->get(route('employees.documents.download', $doc->id))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $scopedRole = employeesRole([ModuleCode::EMPLOYEES], false);
    $owner = employeesUser($scopedRole, $employee);
    $this->actingAs($owner);
    app()->forgetInstance(Access::class);
    $this->get(route('employees.documents.download', $doc->id))->assertForbidden();

    // EMPLOYEES without all_data and not the owner: forbidden.
    $stranger = employeesUser($scopedRole);

    $this->actingAs($stranger);
    app()->forgetInstance(Access::class);
    $this->get(route('employees.documents.download', $doc->id))
        ->assertForbidden();
});

it('no longer carries Allowances, Residence Certificate or the Payroll Settings section on the record, the wizard or the CSV', function () {
    $removed = [
        'allowances', 'residence_cert', 'hourly_rate', 'pay_code', 'agency_code', 'section_code', 'tax_code',
        'allow_sss', 'allow_philhealth', 'allow_pagibig', 'allow_withholding_tax', 'annualize_tax',
        'fixed_tax', 'percent_tax', 'fixed_sss_ee', 'fixed_sss_er', 'fixed_philhealth_ee',
        'fixed_philhealth_er', 'fixed_pagibig_ee', 'fixed_pagibig_er',
        'sss_mode', 'philhealth_mode', 'pagibig_mode',
    ];

    expect(EmployeeFields::section('payroll'))->toBeNull()
        ->and(array_values(array_intersect(array_column(EmployeeFields::allFields(), 'key'), $removed)))->toBe([])
        ->and(array_values(array_intersect(array_keys(EmployeeFields::blankForm()), $removed)))->toBe([])
        ->and(array_values(array_intersect(array_keys(EmployeeColumns::headers()), $removed)))->toBe([]);

    $site = seedEmployeesReferences();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    // A hire gets the column defaults — the standard computation — even from
    // a client posting the `$removed` keys: the payload is built from the
    // field list, never from the form's own keys.
    $view = app(CreateEmployee::class)->handle(app(Access::class), [
        'allowances' => '5000', 'residence_cert' => 'CTC-123', 'hourly_rate' => '250', 'pay_code' => 'SEMI', 'allow_sss' => false,
        'annualize_tax' => true, 'fixed_tax' => '1000', 'percent_tax' => '10', 'sss_mode' => 'MONTHLY',
    ] + employeesValidForm($site));
    $hired = EmployeeProfile::query()->findOrFail($view->id);

    expect($hired->allowances)->toBe(0.0)
        ->and($hired->residence_cert)->toBeNull()
        ->and($hired->hourly_rate)->toBe(0.0)
        ->and($hired->pay_code)->toBeNull()
        ->and($hired->allow_sss)->toBeTrue()
        ->and($hired->allow_withholding_tax)->toBeTrue()
        ->and($hired->annualize_tax)->toBeFalse()
        ->and($hired->fixed_tax)->toBe(0.0)
        ->and($hired->percent_tax)->toBe(0.0)
        ->and($hired->sss_mode)->toBe('SEMI_MONTHLY');

    // What a row already holds stays as it is: the `payroll` section id names
    // no section and saves nothing, and a section save never reaches a key
    // off its field list.
    $hired->forceFill(['allow_withholding_tax' => false, 'allowances' => 1500])->save();

    expect(app(UpdateEmployee::class)->handle(app(Access::class), $hired->id, 'payroll', [
        'allow_withholding_tax' => true, 'allowances' => '0',
    ]))->toBeNull();

    expect(app(UpdateEmployee::class)->handle(app(Access::class), $hired->id, 'employment', [
        'allow_withholding_tax' => true, 'allowances' => '0',
    ] + EmployeeFields::toForm($hired)))->toBe('Employee Details');

    expect($hired->refresh()->allow_withholding_tax)->toBeFalse()
        ->and($hired->allowances)->toBe(1500.0);

    Livewire::test(Show::class, ['employee' => $hired->id])
        ->assertSee('Rate Per Month')
        ->assertDontSee('Allowances')
        ->assertDontSee('Residence Certificate')
        ->assertDontSee('Payroll Settings')
        ->assertDontSee('Deduct SSS');

    // Thirteen steps, Addresses second and the Personal Data Sheet's parts
    // among them; Government IDs & Bank is the last, where Create appears.
    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->assertSee('Step 1 of 13')
        ->assertDontSee('Payroll Settings')
        ->set('step', 12)
        ->assertSee('Step 13 of 13')
        ->assertSeeHtml('aria-label="Step 13: Government IDs &amp; Bank"')
        ->assertDontSee('Payroll Settings')
        ->assertSee('Create employee');
});

it('keeps Place of Birth, the structured Citizenship and the emergency contact Address on the record and in the wizard', function () {
    $site = seedEmployeesReferences();
    $employee = seedEmployeeRecord($site->id);
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    // Emergency Contact is two by two on the record's wide right column, after
    // Addresses, as well as in the wizard: no field spans the row, and the
    // narrow left card holds only the portrait and Personal Details.
    $emergency = EmployeeFields::section('emergency');
    expect($emergency['columns'] ?? null)->toBe(2)
        ->and(array_column($emergency['fields'] ?? [], 'key'))->toBe(['emergency_name', 'emergency_relation', 'emergency_phone', 'emergency_address'])
        ->and(array_filter(array_column($emergency['fields'] ?? [], 'wide')))->toBe([])
        ->and(Show::PROFILE_LEFT)->toBe(['personal'])
        ->and(Show::PROFILE_RIGHT)->toBe(['employment', 'addresses', 'emergency', 'statutory']);

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSeeHtmlInOrder(['wire:key="section-addresses"', 'wire:key="section-emergency"', 'wire:key="section-statutory"'])
        ->assertSee('Place of Birth')
        ->assertSee('Citizenship')
        ->assertSeeHtmlInOrder(['wire:key="section-emergency"', 'sm:grid-cols-2', 'Full Name'])
        ->call('startEdit', 'personal')
        ->set('form.place_of_birth', 'Quezon City')
        ->set('form.citizenship_kind', 'FILIPINO')
        ->call('saveSection', 'personal')
        ->assertSet('editingSection', null)
        ->assertSee('Quezon City')
        ->assertSee('Filipino');

    // The citizenship lands in the details row; the legacy column stays as
    // it was stored (blank on this record).
    expect($employee->refresh()->place_of_birth)->toBe('Quezon City')
        ->and($employee->citizenship)->toBeNull()
        ->and(EmployeePdsDetail::query()->where('employee_id', $employee->id)->value('citizenship_kind'))->toBe('FILIPINO');

    // Own corrections remain proposed until an independent manager approves.
    $self = employeesUser(employeesRole([ModuleCode::PROFILE], false), $employee);
    $this->actingAs($self);
    app()->forgetInstance(Access::class);

    Livewire::test(Show::class, ['employee' => $employee->id, 'own' => true])
        ->call('startEdit', 'emergency')
        ->set('form.emergency_name', 'Ben Domingo')
        ->set('form.emergency_relation', 'Brother')
        ->set('form.emergency_phone', '0917 555 0101')
        ->set('form.emergency_address', '45 Rizal Ave., Pasig City')
        ->call('saveSection', 'emergency')
        ->assertSet('editingSection', null);

    expect($employee->refresh()->emergency_address)->toBeNull();
    $request = EmployeeRecordChangeRequest::query()->where('employee_id', $employee->id)->firstOrFail();
    app(ReviewRecordChange::class)->handle(Access::forActor($admin), $request->id, true);

    expect($employee->refresh()->emergency_address)->toBe('45 Rizal Ave., Pasig City')
        ->and($employee->emergency_name)->toBe('Ben Domingo');

    // A profile-only account cannot call direct management writes.
    expect(fn () => app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'personal', ['place_of_birth' => 'Cebu City'] + EmployeeFields::toForm($employee)))
        ->toThrow(HttpException::class);
    expect($employee->refresh()->place_of_birth)->toBe('Quezon City');

    $this->actingAs($admin);
    app()->forgetInstance(Access::class);

    // Step 1 asks for the two personal fields, step 2 for the addresses in
    // parts, step 3 for the emergency contact's Address.
    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->assertSee('Place of Birth')
        ->assertSee('Citizenship')
        ->set('step', 1)
        ->assertSee('Addresses')
        ->assertSee('House/Block/Lot No.')
        ->set('step', 2)
        ->assertSee('Emergency Contact')
        ->assertSee('Address');

    // Optional on the hire: a wizard that leaves all three blank still hires,
    // with no citizenship anywhere — neither chosen nor in the legacy column.
    $view = app(CreateEmployee::class)->handle(app(Access::class), employeesValidForm($site));
    $hired = EmployeeProfile::query()->findOrFail($view->id);

    expect($hired->place_of_birth)->toBeNull()
        ->and($hired->citizenship)->toBeNull()
        ->and(EmployeePdsDetail::query()->where('employee_id', $hired->id)->value('citizenship_kind'))->toBeNull()
        ->and($hired->emergency_address)->toBeNull();
});

it('keeps a Rate Per Day beside Rate Per Month on the record, and saves a blank one as no rate', function () {
    $site = seedEmployeesReferences();
    $employee = seedEmployeeRecord($site->id);
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    // Side by side in Employee Details' two columns: Rate Per Day follows
    // Rate Per Month.
    $keys = array_column(EmployeeFields::section('employment')['fields'] ?? [], 'key');
    $at = array_search('monthly_rate', $keys, true);
    expect($at)->not->toBeFalse()
        ->and(array_slice($keys, (int) $at, 2))->toBe(['monthly_rate', 'daily_rate']);

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSee('Rate Per Day')
        ->call('startEdit', 'employment')
        ->assertSee('Leave blank to work it out from the Rate Per Month.')
        ->set('form.daily_rate', '1250.50')
        ->call('saveSection', 'employment')
        ->assertSet('editingSection', null)
        ->assertSee("\u{20B1}1,250.50");

    expect($employee->refresh()->daily_rate)->toBe(1250.5)
        ->and($employee->monthly_rate)->toBe(30000.0);

    // Cleared, it is no rate at all (NULL), not a rate of zero: payroll
    // then works the day out from Rate Per Month.
    $page->call('startEdit', 'employment')
        ->set('form.daily_rate', '')
        ->call('saveSection', 'employment')
        ->assertSet('editingSection', null);

    expect($employee->refresh()->daily_rate)->toBeNull();

    // The command refuses, without the page, a rate the page would catch.
    expect(fn () => app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', ['daily_rate' => '12.345'] + EmployeeFields::toForm($employee)))
        ->toThrow(RuntimeException::class, 'Rate Per Day must be a plain amount with at most two decimals, like 25000.50.');
    expect($employee->refresh()->daily_rate)->toBeNull();
});

it('hires with no rate, with only a Rate Per Day, or with only a Rate Per Month', function () {
    $site = seedEmployeesReferences();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    // Neither rate is a required answer of the hire, whatever the other holds.
    expect(EmployeeFields::isRequired('monthly_rate', ['daily_rate' => '']))->toBeFalse()
        ->and(EmployeeFields::isRequired('monthly_rate', ['daily_rate' => '850']))->toBeFalse()
        ->and(EmployeeFields::isRequired('daily_rate', []))->toBeFalse()
        ->and(EmployeeFields::isRequired('first_name', ['daily_rate' => '850']))->toBeTrue();

    $none = app(CreateEmployee::class)->handle(app(Access::class), ['monthly_rate' => '', 'daily_rate' => ''] + employeesValidForm($site));
    $hired = EmployeeProfile::query()->findOrFail($none->id);
    expect($hired->monthly_rate)->toBe(0.0)
        ->and($hired->daily_rate)->toBeNull();

    $daily = app(CreateEmployee::class)->handle(app(Access::class), ['employee_id_no' => '2026-002', 'email' => 'daily.hire@company.com', 'monthly_rate' => '', 'daily_rate' => '850'] + employeesValidForm($site));
    $hired = EmployeeProfile::query()->findOrFail($daily->id);
    expect($hired->daily_rate)->toBe(850.0)
        ->and($hired->monthly_rate)->toBe(0.0);

    // A monthly-only hire carries no Rate Per Day at all.
    $monthly = app(CreateEmployee::class)->handle(app(Access::class), ['employee_id_no' => '2026-003', 'email' => 'second.hire@company.com'] + employeesValidForm($site));
    expect(EmployeeProfile::query()->findOrFail($monthly->id)->daily_rate)->toBeNull();
});

it('requires only the ten answers of a hire, and fills in what the record cannot be saved without', function () {
    seedEmployeesReferences();
    $this->actingAs(employeesUser(employeesRole([ModuleCode::EMPLOYEES], true)));

    $errors = [];
    foreach (array_keys(EmployeeFields::wizard()) as $step) {
        $errors += EmployeeInput::stepErrors(EmployeeFields::blankForm() + ['department_id' => '', 'unit_id' => ''], $step);
    }

    expect($errors)->toEqualCanonicalizing([
        'employee_id_no' => 'Employee ID is required.',
        'first_name' => 'First Name is required.',
        'last_name' => 'Last Name is required.',
        'birth_date' => 'Date of Birth is required.',
        'civil_status' => 'Civil Status is required.',
        'phone' => 'Mobile no. is required.',
        'email' => 'Email is required.',
        'residential_address' => 'Residential address is required — fill in at least one part.',
        'department' => 'Department is required.',
        'position' => 'Position is required.',
    ])->and(array_keys($errors))->toEqualCanonicalizing([
        'employee_id_no', 'first_name', 'last_name', 'birth_date', 'civil_status', 'phone', 'email',
        'residential_address', 'department', 'position',
    ]);

    // "Not placed" names no department, whatever position is picked under it.
    $position = (string) Position::query()->where('name', 'Driver')->value('id');
    $notPlaced = EmployeeInput::stepErrors(['department_id' => EmployeePosition::NOT_PLACED, 'position_id' => $position] + EmployeeFields::blankForm(), 3);
    expect($notPlaced)->toHaveKey('department')
        ->and($notPlaced)->not->toHaveKey('position');

    // Only those answers: no sex, emergency contact, status, worksite, date joined or rate.
    $view = app(CreateEmployee::class)->handle(app(Access::class), [
        'employee_id_no' => '2026-010', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'phone' => '0917 123 4567',
        'email' => 'ana.minimal@company.com', 'birth_date' => '1992-03-04', 'civil_status' => 'Single',
        'res_city' => 'Tayabas City', 'position_id' => $position,
        'employment_status' => '', 'date_hired' => '', 'work_site_id' => '', 'monthly_rate' => '',
    ] + EmployeeFields::blankForm());
    $hired = EmployeeProfile::query()->findOrFail($view->id);

    expect($hired->employee_id_no)->toBe('2026-010')
        ->and($hired->position)->toBe('Driver')
        ->and($hired->work_site_id)->toBeNull()
        ->and($hired->sex)->toBeNull()
        ->and($hired->emergency_name)->toBeNull()
        ->and($hired->monthly_rate)->toBe(0.0)
        ->and($hired->employment_status)->toBe('Probationary')
        ->and($hired->date_hired->format('Y-m-d'))->toBe(EmployeeFields::todayKey())
        ->and($view->employment_status)->toBe('Probationary');
});

it('lets every dropdown of the hire wizard be set back to blank', function () {
    seedEmployeesReferences();
    $this->actingAs(employeesUser(employeesRole([ModuleCode::EMPLOYEES], true)));

    $wizard = Livewire::test(AddEmployeeDialog::class)->call('openDialog');

    foreach (array_keys(EmployeeFields::wizard()) as $step) {
        $html = $wizard->set('step', $step)->html();

        // No dropdown opens on a hidden, disabled placeholder that cannot be picked again.
        expect($html)->not->toMatch('/<option[^>]*value=""[^>]*(disabled[^>]*hidden|hidden[^>]*disabled)/');
    }

    $wizard->set('step', 0)->set('form.civil_status', 'Single')->set('form.sex', 'Male')
        ->set('form.civil_status', '')->set('form.sex', '')
        ->assertSet('form.civil_status', '')
        ->call('goNext')->assertSet('step', 0)
        ->assertSee('Civil Status is required.')
        ->assertDontSee('Sex at birth is required.');
});

it('carries the suffix through the wizard and into the collapsed full name', function () {
    $site = seedEmployeesReferences();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->set('form.employee_id_no', '2026-001')
        ->set('form.first_name', 'Juan')
        ->set('form.middle_name', 'Reyes')
        ->set('form.last_name', 'Dela Cruz')
        ->set('form.suffix', 'Jr.')
        ->set('form.phone', '09171234567')
        ->set('form.email', 'juan.jr@company.com')
        ->set('form.birth_date', '1990-05-01')
        ->set('form.res_street', '123 Mabini St.')
        ->set('form.res_city', 'Tayabas City')
        ->set('form.sex', 'Male')
        ->set('form.civil_status', 'Single')
        ->set('form.emergency_name', 'Maria Dela Cruz')
        ->set('form.emergency_relation', 'Spouse')
        ->set('form.emergency_phone', '09179876543')
        ->set('form.position_id', (string) Position::query()->where('name', 'Driver')->value('id'))
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

it('saves a managed suffix edit and submits an own suffix correction for approval', function () {
    seedEmployeesReferences();
    $employee = seedEmployeeRecord();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'personal')
        ->set('form.suffix', 'III')
        ->call('saveSection', 'personal');

    expect($employee->refresh()->suffix)->toBe('III');

    // Name parts are proposals on the actor's own employee record.
    $self = employeesUser(employeesRole([ModuleCode::PROFILE], false), $employee);

    $this->actingAs($self);

    // Access is container-scoped and Livewire::test() bypasses the middleware
    // that drops it between requests (EnsureAccountActive), so the admin's
    // instance would otherwise answer for the second actor too.
    app()->forgetInstance(Access::class);

    Livewire::test(Show::class, ['employee' => $employee->id, 'own' => true])
        ->call('startEdit', 'personal')
        ->set('form.suffix', 'IV')
        ->call('saveSection', 'personal');

    expect($employee->refresh()->suffix)->toBe('III')
        ->and(EmployeeRecordChangeRequest::query()->value('status'))->toBe('PENDING');
});

it('caps the suffix at twenty characters', function () {
    seedEmployeesReferences();
    $employee = seedEmployeeRecord();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'personal')
        ->set('form.suffix', str_repeat('J', 21))
        ->call('saveSection', 'personal');

    expect($employee->refresh()->suffix)->toBeNull();
});

/* |------------------------------------------------------------------------|
   | The list's status column and the absent leave-credit fields           |
   |------------------------------------------------------------------------| */

it('heads the status column "Employment Status" on the list', function () {
    seedEmployeesReferences();
    seedEmployeeRecord();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    // The sort button for the `status` key carries the Employment Status
    // heading; the filter keeps its own wording, so the label is asserted
    // next to its key.
    Livewire::test(Index::class)
        ->assertSeeHtmlInOrder(["sortBy('status')", 'Employment Status'])
        ->assertSee('Regular');
});

it('no longer carries leave credits on the record page or in the hire wizard', function () {
    $keys = array_column(EmployeeFields::allFields(), 'key');

    expect($keys)->not->toContain('vacation_credits')
        ->and($keys)->not->toContain('sick_credits')
        ->and(EmployeeFields::blankForm())->not->toHaveKey('vacation_credits')
        ->and(EmployeeFields::NUMERIC_CAPS)->not->toHaveKey('vacation_credits')
        ->and(EmployeeFields::NUMERIC_CAPS)->not->toHaveKey('sick_credits');

    // A client posting the credit keys does not get them saved: the
    // payload is built from the field list, never from the form's own keys.
    $payload = EmployeeInput::payload(['vacation_credits' => '15', 'sick_credits' => '15'] + EmployeeFields::blankForm());

    expect($payload)->not->toHaveKey('vacation_credits')
        ->and($payload)->not->toHaveKey('sick_credits');

    seedEmployeesReferences();
    $employee = seedEmployeeRecord();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSee('Rate Per Month')
        ->assertDontSee('Leave Credits');

    // Step 4 of the wizard, Employee Details, carries no Leave Credits either.
    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->set('step', 3)
        ->assertSee('Employee Details')
        ->assertSee('Rate Per Month')
        ->assertDontSee('Leave Credits');
});

/* |------------------------------------------------------------------------|
   | Shape rules: contact numbers, dates, amounts and caps, on their own step |
   |------------------------------------------------------------------------| */

/**
 * Every required answer of the hire wizard, so a test can break exactly one.
 *
 * @return array<string, string>
 */
function employeesValidForm(WorkSite $site): array
{
    return [
        'employee_id_no' => '2026-001',
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'phone' => '0917 123 4567',
        'email' => 'juan.valid@company.com',
        'birth_date' => '1990-05-01',
        'res_street' => '123 Mabini St.',
        'res_city' => 'Tayabas City',
        'sex' => 'Male',
        'civil_status' => 'Single',
        'emergency_name' => 'Maria Dela Cruz',
        'emergency_relation' => 'Spouse',
        'emergency_phone' => '(02) 8123-4567',
        'position_id' => (string) Position::query()->where('name', 'Driver')->value('id'),
        'employment_status' => 'Probationary',
        'work_site_id' => $site->id,
        'date_hired' => '2026-08-01',
        'monthly_rate' => '25000',
    ];
}

/**
 * The wizard opened and filled with employeesValidForm().
 *
 * @return Testable<AddEmployeeDialog>
 */
function employeesFilledWizard(WorkSite $site): Testable
{
    $wizard = Livewire::test(AddEmployeeDialog::class)->call('openDialog');

    foreach (employeesValidForm($site) as $key => $value) {
        $wizard->set("form.{$key}", $value);
    }

    return $wizard;
}

it('names the shape problem of one answer on its own step', function (string $key, mixed $value, int $step, ?string $message) {
    if ($value === '{tomorrow}') {
        $value = CarbonImmutable::parse(EmployeeFields::todayKey())->addDay()->format('Y-m-d');
    } elseif ($value === '{today}') {
        $value = EmployeeFields::todayKey();
    }

    // The other required answers on the step are blank and report themselves;
    // only the answer under test is asserted.
    $errors = EmployeeInput::stepErrors([$key => $value] + EmployeeFields::blankForm(), $step);

    expect($errors[$key] ?? null)->toBe($message);
})->with([
    'a birthday that is not a calendar day' => ['birth_date', '2026-02-30', 0, 'Date of Birth must be a real calendar date.'],
    'a birthday in a spelling the picker never sends' => ['birth_date', '01/05/1990', 0, 'Date of Birth must be a real calendar date.'],
    'a birthday tomorrow' => ['birth_date', '{tomorrow}', 0, 'Date of Birth cannot be in the future.'],
    'a birthday today' => ['birth_date', '{today}', 0, null],
    'a date joined that is not a date' => ['date_hired', 'garbage', 3, 'Date Joined must be a real calendar date.'],
    'a date joined in the future (a scheduled hire, as in the reference)' => ['date_hired', '2030-01-01', 3, null],
    'an email without an @' => ['email', 'no-at-sign', 0, EmailRule::MESSAGE],
    'an email over the 200-character cap' => ['email', str_repeat('x', 199).'@y', 0, 'Email must be at most 200 characters.'],
    'a contact number made of letters' => ['phone', 'abc', 0, PhoneRule::MESSAGE],
    'an international contact number' => ['phone', '+63 917 123 4567', 0, null],
    'an emergency number with an extension in it' => ['emergency_phone', '0917 123 4567 loc 12', 2, PhoneRule::MESSAGE],
    'a negative rate' => ['monthly_rate', '-1', 3, 'Rate Per Month must be a positive number.'],
    'a rate that is not a number' => ['monthly_rate', 'abc', 3, 'Rate Per Month must be a positive number.'],
    'a rate with three decimals' => ['monthly_rate', '25000.123', 3, 'Rate Per Month must be a plain amount with at most two decimals, like 25000.50.'],
    'a rate in scientific notation' => ['monthly_rate', '1e5', 3, 'Rate Per Month must be a plain amount with at most two decimals, like 25000.50.'],
    'a rate over the schema cap' => ['monthly_rate', '100000000', 3, 'Rate Per Month must be at most 99,999,999.'],
    'a rate the money box is still settling' => ['monthly_rate', '25000.', 3, null],
    'a sub-peso rate' => ['monthly_rate', '.5', 3, null],
    'no rate of either kind' => ['monthly_rate', '', 3, null],
    'a negative day rate' => ['daily_rate', '-1', 3, 'Rate Per Day must be a positive number.'],
    'a day rate with three decimals' => ['daily_rate', '850.123', 3, 'Rate Per Day must be a plain amount with at most two decimals, like 25000.50.'],
    'a day rate over its cap' => ['daily_rate', '10000000', 3, 'Rate Per Day must be at most 9,999,999.'],
    'a blank day rate' => ['daily_rate', '', 3, null],
    'a sex at birth off the list' => ['sex', 'Attack Helicopter', 0, 'Sex at birth must be one of the listed options.'],
    'a suffix over its cap' => ['suffix', str_repeat('J', 21), 0, 'Suffix must be at most 20 characters.'],
    'a place of birth over its cap' => ['place_of_birth', str_repeat('x', 121), 0, 'Place of Birth must be at most 120 characters.'],
    'a citizenship off the list' => ['citizenship_kind', 'Martian', 0, 'Citizenship must be one of the listed options.'],
    'a civil status specification nobody is asked for (civil status is not Other)' => ['civil_status_other', str_repeat('x', 61), 0, null],
    'an emergency address over its cap' => ['emergency_address', str_repeat('x', 301), 2, 'Address must be at most 300 characters.'],
    'a tampered array where text belongs' => ['first_name', ['x'], 0, 'First Name is required.'],
]);

it('caps the civil status specification and the other citizenship\'s country at their 60-character columns while they are asked for', function () {
    $form = [
        'civil_status' => 'Other', 'civil_status_other' => str_repeat('x', 61),
        'citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'BIRTH', 'dual_citizenship_country' => str_repeat('x', 61),
    ] + EmployeeFields::blankForm();

    $errors = EmployeeInput::stepErrors($form, 0);

    expect($errors['civil_status_other'] ?? null)->toBe('Specify must be at most 60 characters.')
        ->and($errors['dual_citizenship_country'] ?? null)->toBe('Country must be at most 60 characters.');

    $errors = EmployeeInput::stepErrors(['civil_status_other' => str_repeat('x', 60), 'dual_citizenship_country' => str_repeat('x', 60)] + $form, 0);

    expect($errors)->not->toHaveKey('civil_status_other')
        ->and($errors)->not->toHaveKey('dual_citizenship_country');
});

it('keeps the wizard on the step whose answer is malformed and shows the message under it', function () {
    $site = seedEmployeesReferences();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    $wizard = employeesFilledWizard($site)
        ->set('form.phone', 'abc')
        ->call('goNext')
        ->assertSet('step', 0)
        ->assertSee(PhoneRule::MESSAGE)
        ->set('form.phone', '0917 123 4567')
        ->call('goNext')
        ->assertSet('step', 1);

    // A malformed amount three steps in, submitted from the last step: Create
    // jumps back to Employee Details with the message shown, and saves nothing.
    $wizard->set('form.monthly_rate', '25000.123')
        ->set('step', 12)
        ->call('create')
        ->assertNotDispatched('employee-created')
        ->assertSet('step', 3)
        ->assertSee('Rate Per Month must be a plain amount with at most two decimals, like 25000.50.');

    expect(EmployeeProfile::query()->count())->toBe(0);

    $wizard->set('form.monthly_rate', '25000.50')
        ->set('step', 12)
        ->call('create')
        ->assertDispatched('employee-created');

    $created = EmployeeProfile::query()->where('email', 'juan.valid@company.com')->firstOrFail();

    expect($created->phone)->toBe('0917 123 4567')
        ->and($created->emergency_phone)->toBe('(02) 8123-4567')
        ->and($created->monthly_rate)->toBe(25000.5);
});

it('refuses a malformed contact number in CreateEmployee without the page', function () {
    $site = seedEmployeesReferences();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    $form = employeesValidForm($site) + EmployeeFields::blankForm();
    $form['phone'] = '1';

    expect(fn () => app(CreateEmployee::class)->handle(app(Access::class), $form))
        ->toThrow(RuntimeException::class, PhoneRule::MESSAGE);

    expect(EmployeeProfile::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'EMPLOYEE_CREATED')->exists())->toBeFalse();
});

it('refuses a malformed contact number from the record page and the command behind it', function () {
    seedEmployeesReferences();
    $employee = seedEmployeeRecord();
    $admin = employeesUser(employeesRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($admin);

    // Checked as the field is left, then again on Save; the section stays open.
    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'emergency')
        ->set('form.emergency_name', 'Ben Domingo')
        ->set('form.emergency_phone', 'abc')
        ->assertSet('formErrors.emergency_phone', PhoneRule::MESSAGE)
        ->call('saveSection', 'emergency')
        ->assertSet('editingSection', 'emergency');

    expect($employee->refresh()->emergency_name)->toBeNull()
        ->and($employee->emergency_phone)->toBeNull();

    expect(fn () => app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'emergency', [
        'emergency_name' => 'Ben Domingo',
        'emergency_relation' => 'Brother',
        'emergency_phone' => '1',
    ]))->toThrow(RuntimeException::class, PhoneRule::MESSAGE);

    expect($employee->refresh()->emergency_name)->toBeNull();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'emergency')
        ->set('form.emergency_name', 'Ben Domingo')
        ->set('form.emergency_phone', '+63 917 555 9999')
        ->call('saveSection', 'emergency')
        ->assertSet('editingSection', null);

    expect($employee->refresh()->emergency_phone)->toBe('+63 917 555 9999');
});
