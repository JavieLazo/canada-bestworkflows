<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Show;
use App\Livewire\Portal\PortalPage;
use App\Models\EmployeeAddress;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\Commands\DeleteEmployee;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Modules\Workforce\Application\Commands\SubmitRecordChange;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmployeeInput;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The personal data sheet additions live in rows of their own: the
 * employee's details row and address rows. The legacy one-line address and
 * citizenship on `employee_profiles` are never written by any save, and
 * every reader shows them wherever no structured value exists.
 */

/** @param list<string> $modules */
function pdsrecRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'PDS '.implode('-', $modules).' '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

function pdsrecUser(Role $role, ?EmployeeProfile $employee = null): User
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    return $user->refresh();
}

function pdsrecSite(): WorkSite
{
    Position::query()->create(['code' => 'POS-PDS', 'name' => 'Developer']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-PDS1', 'name' => 'Probationary']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-PDS2', 'name' => 'Regular']);

    return WorkSite::query()->create([
        'name' => 'Work From Home', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);
}

/** @param array<string, mixed> $attributes */
function pdsrecEmployee(WorkSite $site, array $attributes = []): EmployeeProfile
{
    return EmployeeProfile::query()->create($attributes + [
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Developer',
        'employment_status' => 'Regular', 'date_hired' => '2024-08-01', 'work_site_id' => $site->id,
        'monthly_rate' => 30000, 'address' => 'Blk 3 Lot 7, Poblacion', 'citizenship' => 'Filipino',
        'phone' => '0917 555 0101', 'email' => 'rosa.'.Str::lower(Str::random(6)).'@company.com',
        'birth_date' => '1996-03-14', 'sex' => 'Female', 'civil_status' => 'Married', 'height_cm' => 162.5,
    ]);
}

function pdsrecHr(): Access
{
    return Access::forActor(pdsrecUser(pdsrecRole([ModuleCode::EMPLOYEES], true)));
}

function pdsrecSelf(EmployeeProfile $employee): Access
{
    return Access::forActor(pdsrecUser(pdsrecRole([ModuleCode::PROFILE], false), $employee));
}

/** @param array<string, mixed> $changes */
function pdsrecSave(Access $actor, EmployeeProfile $employee, string $section, array $changes = []): ?string
{
    $form = EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id));

    return app(UpdateEmployee::class)->handle($actor, $employee->id, $section, $changes + $form);
}

/** @param array<string, mixed> $changes */
function pdsrecApproved(Access $actor, EmployeeProfile $employee, string $section, array $changes): bool
{
    $form = EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id));
    $id = app(SubmitRecordChange::class)->section($actor, $section, $changes + $form, 'Correct my record');
    app(ReviewRecordChange::class)->handle(pdsrecHr(), $id, true);

    return true;
}

/**
 * @param  array<string, mixed>  $parts
 * @return array<string, mixed>
 */
function pdsrecAddresses(array $parts = []): array
{
    $blank = ['permanent_address_same' => true];
    foreach (EmployeeFields::ADDRESS_PREFIX as $prefix) {
        foreach (array_keys(EmployeeFields::ADDRESS_PARTS) as $part) {
            $blank[$prefix.$part] = '';
        }
    }

    return $parts + $blank;
}

function pdsrecView(EmployeeProfile|EmployeeProfileView $employee): EmployeeProfileView
{
    $view = app(EmployeeRecords::class)->find(pdsrecHr(), (string) $employee->id, true);
    expect($view)->not->toBeNull();

    return $view;
}

/** @return array<string, mixed> the two legacy columns as stored */
function pdsrecLegacy(string $id): array
{
    return (array) DB::table('employee_profiles')->where('id', $id)->first(['address', 'citizenship']);
}

function pdsrecDetails(EmployeeProfile $employee): ?EmployeePdsDetail
{
    return EmployeePdsDetail::query()->where('employee_id', $employee->id)->first();
}

/** @return Collection<string, EmployeeAddress> */
function pdsrecAddressRows(EmployeeProfile $employee): Collection
{
    return EmployeeAddress::query()->where('employee_id', $employee->id)->get()->keyBy('type');
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed> every required answer of the hire wizard
 */
function pdsrecHireForm(WorkSite $site, array $changes = []): array
{
    return $changes + [
        'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567',
        'email' => 'juan.pds@company.com', 'birth_date' => '1990-05-01', 'res_street' => '123 Mabini St.',
        'sex' => 'Male', 'civil_status' => 'Single', 'emergency_name' => 'Maria Dela Cruz',
        'emergency_relation' => 'Spouse', 'emergency_phone' => '(02) 8123-4567', 'position_id' => (string) Position::query()->where('name', 'Developer')->value('id'),
        'employment_status' => 'Probationary', 'work_site_id' => $site->id, 'date_hired' => '2026-08-01',
        'monthly_rate' => '25000',
    ] + EmployeeFields::blankForm();
}

/** @return Collection<int, string> the SQL sent while `$run` runs */
function pdsrecQueries(Closure $run): Collection
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $run();

        return collect(DB::getQueryLog())->pluck('query')->map(static fn (mixed $q): string => (string) $q);
    } finally {
        DB::disableQueryLog();
    }
}

/**
 * Whether one SQL statement names a Personal Data Sheet table: the details,
 * the addresses, the six list tables. The punch station and the directory
 * never read them.
 */
function pdsrecNamesPdsTable(string $sql): bool
{
    foreach (['employee_pds_details', 'employee_addresses', 'employee_education', 'employee_eligibilities', 'employee_work_experiences', 'employee_trainings', 'employee_voluntary_works', 'employee_other_items'] as $table) {
        if (str_contains($sql, $table)) {
            return true;
        }
    }

    return false;
}

/**
 * Runs $run with both details tables missing — the window between a release's
 * files and its migration — and puts them back afterwards. On MySQL/MariaDB a
 * DROP commits the open transaction, so the tables are dropped before $run
 * seeds anything, a transaction is reopened for $run's rows and rolled back
 * when it ends, and the tables are recreated by their own migrations under a
 * fresh transaction for RefreshDatabase to roll back.
 */
function pdsrecWithoutTables(Closure $run): void
{
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    Schema::drop('employee_addresses');
    Schema::drop('employee_pds_details');
    // Table probes are answered once per request (EmployeeDetailRows::hasTable).
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $run();
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        foreach (['2026_10_07_0001_create_employee_pds_details_table', '2026_10_07_0002_create_employee_addresses_table'] as $file) {
            (require base_path("database/migrations/{$file}.php"))->up();
        }
        (require base_path('database/migrations/2026_10_07_0003_add_family_and_declarations_to_employee_pds_details_table.php'))->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
}

it('never writes the legacy address or citizenship from the record page, the wizard or My Profile', function () {
    $site = pdsrecSite();
    $employee = pdsrecEmployee($site);
    $hr = pdsrecHr();
    $self = pdsrecSelf($employee);
    $hire = null;

    $queries = pdsrecQueries(function () use ($hr, $self, $employee, $site, &$hire): void {
        pdsrecSave($hr, $employee, 'personal', ['citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'BIRTH', 'dual_citizenship_country' => 'Japan']);
        pdsrecSave($hr, $employee, 'addresses', ['res_street' => 'Rizal St.', 'res_city' => 'Tayabas City']);
        pdsrecSave($hr, $employee, 'statutory', ['umid_no' => '0028-1234567-8']);
        pdsrecApproved($self, $employee, 'personal', ['phone' => '0917 555 0199', 'address' => 'X']);
        pdsrecApproved($self, $employee, 'addresses', pdsrecAddresses(['res_street' => 'Mabini St.', 'res_city' => 'Tayabas City']));
        $hire = app(CreateEmployee::class)->handle($hr, pdsrecHireForm($site));
    });

    $writes = $queries->filter(static fn (string $q): bool => preg_match('/^\s*(insert into|update)\s+[`"]employee_profiles[`"]/i', $q) === 1
        && preg_match('/[`"](address|citizenship)[`"]/', $q) === 1);
    expect(pdsrecLegacy($employee->id))->toBe(['address' => 'Blk 3 Lot 7, Poblacion', 'citizenship' => 'Filipino'])
        ->and($hire)->toBeInstanceOf(EmployeeProfileView::class)
        ->and(pdsrecLegacy((string) $hire?->id))->toBe(['address' => null, 'citizenship' => null])
        ->and($writes)->toBeEmpty()
        ->and(pdsrecAddressRows($employee)->get('RESIDENTIAL')?->street)->toBe('Mabini St.');
});

it('shows a legacy-only record\'s address and citizenship, and prefers the typed parts and the chosen citizenship once they exist', function () {
    $site = pdsrecSite();
    $employee = pdsrecEmployee($site);
    $hr = pdsrecHr();

    $view = pdsrecView($employee);
    $own = app(EmployeeRecords::class)->own(pdsrecSelf($employee), true);
    expect(EmployeeFields::residentialText($view))->toBe('Blk 3 Lot 7, Poblacion')
        ->and(EmployeeFields::legacyAddress($view))->toBe('Blk 3 Lot 7, Poblacion')
        ->and(EmployeeFields::citizenshipText($view))->toBe('Filipino')
        ->and(EmployeeFields::legacyCitizenship($view))->toBe('Filipino')
        ->and(EmployeeFields::permanentText($view))->toBe('')
        ->and($own !== null ? EmployeeFields::residentialText($own) : null)->toBe('Blk 3 Lot 7, Poblacion');

    pdsrecSave($hr, $employee, 'addresses', [
        'res_house_no' => 'Blk 5 Lot 2', 'res_street' => 'Rizal St.', 'res_city' => 'Tayabas City', 'res_province' => 'Quezon', 'res_zip_code' => '4327',
    ]);
    pdsrecSave($hr, $employee, 'personal', ['citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'NATURALIZATION', 'dual_citizenship_country' => 'Japan']);

    $view = pdsrecView($employee);
    expect(EmployeeFields::residentialText($view))->toBe('Blk 5 Lot 2, Rizal St., Tayabas City, Quezon 4327')
        ->and(EmployeeFields::legacyAddress($view))->toBe('')
        ->and(EmployeeFields::citizenshipText($view))->toBe('Dual citizenship · by naturalization · Japan')
        ->and(EmployeeFields::readValue($view, (array) EmployeeFields::field('citizenship_kind')))->toBe('Dual citizenship · by naturalization · Japan')
        ->and(EmployeeFields::legacyCitizenship($view))->toBe('')
        ->and(EmployeeFields::permanentText($view))->toBe('Same as residential')
        ->and(EmployeeFields::addressLine(['zip_code' => '4327']))->toBe('4327')
        ->and(pdsrecLegacy($employee->id))->toBe(['address' => 'Blk 3 Lot 7, Poblacion', 'citizenship' => 'Filipino']);
});

it('stores civil status Other with its specification and clears the specification when another status is saved', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();

    pdsrecSave($hr, $employee, 'personal', ['civil_status' => 'Other', 'civil_status_other' => 'Annulled']);
    expect($employee->fresh()?->civil_status)->toBe('Other')
        ->and(pdsrecDetails($employee)?->civil_status_other)->toBe('Annulled')
        ->and(EmployeeFields::civilStatusText(pdsrecView($employee)))->toBe('Other — Annulled');

    pdsrecSave($hr, $employee, 'personal', ['civil_status' => 'Single']);
    expect($employee->fresh()?->civil_status)->toBe('Single')
        ->and(pdsrecDetails($employee)?->civil_status_other)->toBeNull()
        ->and(EmployeeFields::civilStatusText(pdsrecView($employee)))->toBe('Single');
});

it('refuses Other without a specification through the wizard step, management save and own change request, writing nothing', function () {
    $site = pdsrecSite();
    $employee = pdsrecEmployee($site);
    $hr = pdsrecHr();
    $form = pdsrecHireForm($site, ['civil_status' => 'Other']);

    expect(EmployeeInput::stepErrors($form, 0))->toHaveKey('civil_status_other', 'Specify the civil status.')
        ->and(fn () => app(CreateEmployee::class)->handle($hr, $form))->toThrow(RuntimeException::class, 'Specify the civil status.')
        ->and(fn () => pdsrecSave($hr, $employee, 'personal', ['civil_status' => 'Other', 'civil_status_other' => '  ']))->toThrow(RuntimeException::class, 'Specify the civil status.')
        ->and(fn () => pdsrecApproved(pdsrecSelf($employee), $employee, 'personal', ['civil_status' => 'Other']))->toThrow(RuntimeException::class, 'Specify the civil status.')
        ->and($employee->fresh()?->civil_status)->toBe('Married')
        ->and(EmployeePdsDetail::query()->count())->toBe(0)
        ->and(EmployeeProfile::query()->count())->toBe(1);
});

it('stores Filipino without dual parts and clears the parts when Dual citizenship is dropped', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();

    pdsrecSave($hr, $employee, 'personal', ['citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'BIRTH', 'dual_citizenship_country' => 'Japan']);
    expect(pdsrecDetails($employee)?->dual_citizenship_country)->toBe('Japan');

    pdsrecSave($hr, $employee, 'personal', ['citizenship_kind' => 'FILIPINO']);
    $row = pdsrecDetails($employee);
    expect($row?->citizenship_kind)->toBe('FILIPINO')
        ->and($row?->dual_citizenship_by)->toBeNull()
        ->and($row?->dual_citizenship_country)->toBeNull()
        ->and(EmployeeFields::citizenshipText(pdsrecView($employee)))->toBe('Filipino');
});

it('refuses Dual citizenship without how it was acquired or without the country', function (array $changes, string $key, string $message) {
    $site = pdsrecSite();
    $employee = pdsrecEmployee($site);

    expect(EmployeeInput::stepErrors(pdsrecHireForm($site, ['citizenship_kind' => 'DUAL'] + $changes), 0))->toHaveKey($key, $message)
        ->and(fn () => pdsrecSave(pdsrecHr(), $employee, 'personal', ['citizenship_kind' => 'DUAL'] + $changes))->toThrow(RuntimeException::class, $message)
        ->and(pdsrecDetails($employee))->toBeNull();
})->with([
    'without how' => [['dual_citizenship_country' => 'Japan'], 'dual_citizenship_by', 'Choose whether the dual citizenship is by birth or by naturalization.'],
    'without the country' => [['dual_citizenship_by' => 'BIRTH'], 'dual_citizenship_country', 'Name the country of the other citizenship.'],
]);

it('takes and shows height in metres while storing centimetres', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();
    $field = (array) EmployeeFields::field('height_cm');

    $view = pdsrecView($employee);
    expect(EmployeeFields::toForm($view)['height_cm'])->toBe('1.625')
        ->and(EmployeeFields::readValue($view, $field))->toBe('1.625 m');

    pdsrecSave($hr, $employee, 'personal');
    expect($employee->fresh()?->height_cm)->toBe(162.5);

    pdsrecSave($hr, $employee, 'personal', ['height_cm' => '1.68']);
    expect($employee->fresh()?->height_cm)->toBe(168.0)
        ->and(EmployeeInput::amount($field, '1.655'))->toBe(165.5)
        ->and(EmployeeInput::amount($field, '1.13'))->toBe(113.0)
        ->and(EmployeeInput::amount($field, ''))->toBeNull()
        ->and(EmployeeFields::metresText(178.0))->toBe('1.78');
});

it('names a height that is not in metres', function (string $text, ?string $message) {
    expect(EmployeeInput::fieldError((array) EmployeeFields::field('height_cm'), $text))->toBe($message);
})->with([
    ['-1', 'Height must be in metres, like 1.68.'],
    ['1.62555', 'Height must be in metres, like 1.68.'],
    ['abc', 'Height must be in metres, like 1.68.'],
    ['3.01', 'Height must be at most 3 metres — 168 cm is typed 1.68.'],
    ['168', 'Height must be at most 3 metres — 168 cm is typed 1.68.'],
    ['1.7', null],
    ['1.625', null],
    ['', null],
]);

it('refuses a ZIP code that is not four digits', function (string $key, string $text, ?string $message) {
    expect(EmployeeInput::fieldError((array) EmployeeFields::field($key), $text))->toBe($message);
})->with([
    ['res_zip_code', '432', 'ZIP code must be 4 digits.'],
    ['res_zip_code', '43270', 'ZIP code must be 4 digits.'],
    ['res_zip_code', 'ABCD', 'ZIP code must be 4 digits.'],
    ['res_zip_code', '4327', null],
    ['res_zip_code', '', null],
    ['perm_zip_code', '432', 'ZIP code must be 4 digits.'],
    ['perm_zip_code', '4327', null],
]);

it('replaces the address rows with the Addresses section and drops the permanent row when Same as residential is chosen', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();

    pdsrecSave($hr, $employee, 'addresses', ['res_street' => 'Rizal St.', 'permanent_address_same' => false, 'perm_city' => 'Lucena City']);
    $rows = pdsrecAddressRows($employee);
    expect($rows->keys()->all())->toEqualCanonicalizing(['RESIDENTIAL', 'PERMANENT'])
        ->and($rows->get('PERMANENT')?->city)->toBe('Lucena City')
        ->and(pdsrecDetails($employee)?->permanent_address_same)->toBeFalse()
        ->and(EmployeeFields::permanentText(pdsrecView($employee)))->toBe('Lucena City');

    pdsrecSave($hr, $employee, 'addresses', ['permanent_address_same' => true]);
    expect(pdsrecAddressRows($employee)->keys()->all())->toBe(['RESIDENTIAL'])
        ->and(pdsrecDetails($employee)?->permanent_address_same)->toBeTrue()
        ->and(EmployeeFields::permanentText(pdsrecView($employee)))->toBe('Same as residential');
});

it('leaves the stored rows as they were when an Addresses save is refused', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();
    pdsrecSave($hr, $employee, 'addresses', ['res_street' => 'Rizal St.', 'res_zip_code' => '4327']);

    expect(fn () => pdsrecSave($hr, $employee, 'addresses', ['res_street' => 'Mabini St.', 'res_zip_code' => '43']))
        ->toThrow(RuntimeException::class, 'ZIP code must be 4 digits.');
    $row = pdsrecAddressRows($employee)->get('RESIDENTIAL');
    expect($row?->street)->toBe('Rizal St.')->and($row?->zip_code)->toBe('4327');
});

it('creates no details row until a save carries a value, and reads a missing row as blank and the permanent address as not answered', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();

    pdsrecSave($hr, $employee, 'personal');
    pdsrecSave($hr, $employee, 'statutory');
    $view = pdsrecView($employee);
    expect(EmployeePdsDetail::query()->count())->toBe(0)
        ->and($view->umid_no)->toBeNull()
        ->and($view->permanent_address_same)->toBeNull()
        ->and(EmployeeFields::permanentText($view))->toBe('')
        ->and(EmployeeFields::toForm($view)['permanent_address_same'])->toBeTrue()
        ->and(EmployeeFields::readValue($view, (array) EmployeeFields::field('permanent_address_same')))->toBe('—');

    pdsrecSave($hr, $employee, 'statutory', ['umid_no' => '0028-1234567-8']);
    expect(EmployeePdsDetail::query()->count())->toBe(1);
});

it('keeps UMID and PhilSys when Personal Details is saved, and Specify when Government IDs is saved', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();

    pdsrecSave($hr, $employee, 'statutory', ['umid_no' => 'U-1', 'philsys_pcn' => '1234-5678-9012-3456']);
    pdsrecSave($hr, $employee, 'personal', ['civil_status' => 'Other', 'civil_status_other' => 'Annulled']);
    $row = pdsrecDetails($employee);
    expect($row?->umid_no)->toBe('U-1')->and($row?->philsys_pcn)->toBe('1234-5678-9012-3456')->and($row?->civil_status_other)->toBe('Annulled');

    pdsrecSave($hr, $employee, 'statutory', ['umid_no' => 'U-2']);
    $row = pdsrecDetails($employee);
    expect($row?->umid_no)->toBe('U-2')->and($row?->civil_status_other)->toBe('Annulled');
});

it('hires with the residential address row and the Same as residential answer, and requires a residential part', function () {
    $site = pdsrecSite();
    $hire = app(CreateEmployee::class)->handle(pdsrecHr(), pdsrecHireForm($site));
    $model = EmployeeProfile::query()->findOrFail($hire->id);

    expect(pdsrecAddressRows($model)->keys()->all())->toBe(['RESIDENTIAL'])
        ->and(pdsrecAddressRows($model)->get('RESIDENTIAL')?->street)->toBe('123 Mabini St.')
        ->and(pdsrecDetails($model)?->permanent_address_same)->toBeTrue()
        ->and($hire->res_street)->toBe('123 Mabini St.')
        ->and(EmployeeFields::permanentText($hire))->toBe('Same as residential');

    $blank = pdsrecHireForm($site, ['res_street' => '', 'email' => 'other.pds@company.com']);
    expect(EmployeeInput::stepErrors($blank, 1))->toBe(['residential_address' => 'Residential address is required — fill in at least one part.'])
        ->and(EmployeeInput::stepErrors(['res_city' => 'Tayabas City'] + $blank, 1))->toBe([])
        ->and(EmployeeInput::stepErrors(['permanent_address_same' => false, 'res_city' => 'Tayabas City'] + $blank, 1))
        ->toBe(['permanent_address' => 'Permanent address is required — fill in at least one part, or choose Same as residential.'])
        ->and(fn () => app(CreateEmployee::class)->handle(pdsrecHr(), $blank))->toThrow(RuntimeException::class, 'Residential address is required — fill in at least one part.');
});

it('refuses direct own citizenship, UMID and PhilSys writes without approval', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $self = pdsrecSelf($employee);

    expect(fn () => pdsrecSave($self, $employee, 'statutory', ['umid_no' => 'U-1', 'philsys_pcn' => 'P-1']))->toThrow(HttpException::class)
        ->and(fn () => pdsrecSave($self, $employee, 'personal', ['citizenship_kind' => 'FILIPINO']))->toThrow(HttpException::class)
        ->and(fn () => pdsrecSave($self, $employee, 'addresses', ['res_street' => 'Rizal St.']))->toThrow(HttpException::class)
        ->and(EmployeePdsDetail::query()->count())->toBe(0)
        ->and(EmployeeAddress::query()->count())->toBe(0);
});

it('refuses section saves to an all_data PAYROLL viewer without EMPLOYEES with no effect', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $viewer = Access::forActor(pdsrecUser(pdsrecRole([ModuleCode::PAYROLL], true)));

    expect(fn () => pdsrecSave($viewer, $employee, 'addresses', ['res_street' => 'Rizal St.']))->toThrow(HttpException::class)
        ->and(fn () => pdsrecSave($viewer, $employee, 'statutory', ['umid_no' => 'U-1']))->toThrow(HttpException::class)
        ->and(EmployeePdsDetail::query()->count())->toBe(0)
        ->and(EmployeeAddress::query()->count())->toBe(0);
});

it('aborts CreateEmployee for a caller without all_data and creates no profile, details or address rows', function () {
    $site = pdsrecSite();
    $actor = Access::forActor(pdsrecUser(pdsrecRole([ModuleCode::EMPLOYEES], false)));

    expect(fn () => app(CreateEmployee::class)->handle($actor, pdsrecHireForm($site)))->toThrow(HttpException::class)
        ->and(EmployeeProfile::query()->count())->toBe(0)
        ->and(EmployeePdsDetail::query()->count())->toBe(0)
        ->and(EmployeeAddress::query()->count())->toBe(0);
});

it('resolves no foreign record for an own-rows actor, so nothing is written', function () {
    $site = pdsrecSite();
    $mine = pdsrecEmployee($site);
    $other = pdsrecEmployee($site);

    expect(fn () => pdsrecSave(pdsrecSelf($mine), $other, 'addresses', ['res_street' => 'Rizal St.']))->toThrow(HttpException::class)
        ->and(EmployeeAddress::query()->count())->toBe(0)
        ->and(EmployeePdsDetail::query()->count())->toBe(0);
});

it('deletes the details and address rows with the record', function () {
    // An active record is refused before anything is deleted.
    $employee = pdsrecEmployee(pdsrecSite(), ['is_active' => false]);
    $hr = pdsrecHr();
    pdsrecSave($hr, $employee, 'addresses', ['res_street' => 'Rizal St.']);
    pdsrecSave($hr, $employee, 'statutory', ['umid_no' => 'U-1']);

    app(DeleteEmployee::class)->handle($hr, $employee->id);

    expect(EmployeeProfile::query()->whereKey($employee->id)->exists())->toBeFalse()
        ->and(EmployeePdsDetail::query()->count())->toBe(0)
        ->and(EmployeeAddress::query()->count())->toBe(0);
});

it('keeps the HR-entered citizenship, UMID and PhilSys after approved employee proposals', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();
    pdsrecSave($hr, $employee, 'personal', ['citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'BIRTH', 'dual_citizenship_country' => 'Japan']);
    pdsrecSave($hr, $employee, 'statutory', ['umid_no' => 'U-1', 'philsys_pcn' => 'P-1']);
    $self = pdsrecSelf($employee);

    expect(pdsrecApproved($self, $employee, 'personal', ['phone' => '0917 555 0177', 'civil_status' => 'Other', 'civil_status_other' => 'Annulled']))->toBeTrue()
        ->and(pdsrecApproved($self, $employee, 'addresses', pdsrecAddresses(['res_city' => 'Tayabas City'])))->toBeTrue();

    $row = pdsrecDetails($employee);
    expect($row?->citizenship_kind)->toBe('DUAL')
        ->and($row?->dual_citizenship_by)->toBe('BIRTH')
        ->and($row?->dual_citizenship_country)->toBe('Japan')
        ->and($row?->umid_no)->toBe('U-1')
        ->and($row?->philsys_pcn)->toBe('P-1')
        ->and($row?->civil_status_other)->toBe('Annulled')
        ->and($employee->fresh()?->phone)->toBe('0917 555 0177');
});

it('refuses to blank every residential part while a stored one-line address exists, on the record page and on My Profile', function () {
    $site = pdsrecSite();
    $employee = pdsrecEmployee($site);
    $hr = pdsrecHr();
    $message = 'Residential address needs at least one part — the address entered before is kept until it is replaced.';
    pdsrecSave($hr, $employee, 'addresses', ['res_street' => 'Rizal St.']);

    expect(fn () => pdsrecSave($hr, $employee, 'addresses', ['res_street' => '']))->toThrow(RuntimeException::class, $message)
        ->and(fn () => pdsrecApproved(pdsrecSelf($employee), $employee, 'addresses', pdsrecAddresses()))->toThrow(RuntimeException::class, $message)
        ->and(pdsrecAddressRows($employee)->get('RESIDENTIAL')?->street)->toBe('Rizal St.')
        ->and(pdsrecLegacy($employee->id)['address'])->toBe('Blk 3 Lot 7, Poblacion');

    $plain = pdsrecEmployee($site, ['address' => null]);
    pdsrecSave($hr, $plain, 'addresses', ['res_street' => 'Rizal St.']);
    pdsrecSave($hr, $plain, 'addresses', ['res_street' => '']);
    expect(pdsrecAddressRows($plain))->toBeEmpty();

    $legacyOnly = pdsrecEmployee($site);
    expect(pdsrecApproved(pdsrecSelf($legacyOnly), $legacyOnly, 'personal', ['phone' => '0917 555 0166']))->toBeTrue()
        ->and(pdsrecAddressRows($legacyOnly))->toBeEmpty()
        ->and($legacyOnly->fresh()?->phone)->toBe('0917 555 0166');
});

it('reads a tampered array in the own record proposal as blank, the way the record page does, never as a TypeError', function () {
    $employee = pdsrecEmployee(pdsrecSite(), ['address' => null]);
    $self = pdsrecSelf($employee);

    pdsrecApproved($self, $employee, 'addresses', pdsrecAddresses(['res_street' => ['x'], 'res_city' => 'Tayabas City']));
    $row = pdsrecAddressRows($employee)->get('RESIDENTIAL');
    expect($row?->street)->toBeNull()->and($row?->city)->toBe('Tayabas City');

    pdsrecApproved($self, $employee, 'personal', ['phone' => ['0917']]);
    expect($employee->fresh()?->phone)->toBeNull();
});

it('creates the first address and details rows without a gap lock', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();
    $parts = ['res_street' => 'Rizal St.', 'res_city' => 'Tayabas City'];

    $first = pdsrecQueries(function () use ($hr, $employee, $parts): void {
        pdsrecSave($hr, $employee, 'addresses', $parts);
        pdsrecSave($hr, $employee, 'statutory', ['umid_no' => 'U-1']);
    });
    $again = pdsrecQueries(fn () => pdsrecSave($hr, $employee, 'addresses', $parts));

    expect($first->filter(static fn (string $q): bool => str_contains(strtolower($q), 'for update')
        && pdsrecNamesPdsTable($q)))->toBeEmpty()
        ->and($first->filter(static fn (string $q): bool => preg_match('/^\s*delete\s+from\s+[`"]employee_addresses/i', $q) === 1))->toBeEmpty()
        ->and($again->filter(static fn (string $q): bool => preg_match('/^\s*(insert|update|delete)\b.*employee_addresses/is', $q) === 1))->toBeEmpty();
});

it('keeps the punch station working while the details tables are missing', function () {
    pdsrecWithoutTables(function (): void {
        $employee = pdsrecEmployee(pdsrecSite());
        test()->actingAs(pdsrecUser(pdsrecRole([ModuleCode::PORTAL], false), $employee));
        app()->forgetInstance(Access::class);

        $queries = pdsrecQueries(fn () => Livewire::test(PortalPage::class)->assertSet('tab', 'punch'));
        expect($queries->filter(static fn (string $q): bool => pdsrecNamesPdsTable($q)))->toBeEmpty();
    });
});

it('reads the legacy values and saves a approved phone-only record change while the details tables are missing', function () {
    pdsrecWithoutTables(function (): void {
        $employee = pdsrecEmployee(pdsrecSite());

        $view = pdsrecView($employee);
        expect(EmployeeDetailRows::tablesReady())->toBeFalse()
            ->and(EmployeeFields::residentialText($view))->toBe('Blk 3 Lot 7, Poblacion')
            ->and(EmployeeFields::citizenshipText($view))->toBe('Filipino')
            ->and(pdsrecApproved(pdsrecSelf($employee), $employee, 'personal', ['phone' => '0917 555 0155']))->toBeTrue()
            ->and($employee->fresh()?->phone)->toBe('0917 555 0155')
            ->and(pdsrecSave(pdsrecHr(), $employee, 'personal', ['phone' => '0917 555 0156']))->toBe('Personal Details');
    });
});

it('refuses a save that carries a ZIP or a UMID while the details tables are missing, writing no column', function () {
    pdsrecWithoutTables(function (): void {
        $employee = pdsrecEmployee(pdsrecSite());
        $hr = pdsrecHr();
        $before = (array) DB::table('employee_profiles')->where('id', $employee->id)->first();

        expect(fn () => pdsrecSave($hr, $employee, 'addresses', ['res_zip_code' => '4327']))->toThrow(RuntimeException::class, EmployeeDetailRows::NOT_READY)
            ->and(fn () => pdsrecSave($hr, $employee, 'statutory', ['umid_no' => 'U-1', 'tin' => '683278996']))->toThrow(RuntimeException::class, EmployeeDetailRows::NOT_READY)
            ->and(fn () => pdsrecApproved(pdsrecSelf($employee), $employee, 'addresses', pdsrecAddresses(['res_city' => 'Tayabas City'])))->toThrow(RuntimeException::class, EmployeeDetailRows::NOT_READY)
            ->and((array) DB::table('employee_profiles')->where('id', $employee->id)->first())->toBe($before);
    });
});

it('fails loudly when a view projected without details is asked for its address', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();
    $views = null;

    $queries = pdsrecQueries(function () use ($hr, &$views): void {
        $views = app(EmployeeRecords::class)->list($hr);
    });
    $view = $views?->first();

    expect($view)->toBeInstanceOf(EmployeeProfileView::class)
        ->and(fn () => EmployeeFields::residentialText($view))->toThrow(LogicException::class, 'Employee details were not loaded for this view.')
        ->and(fn () => EmployeeFields::citizenshipText($view))->toThrow(LogicException::class, 'Employee details were not loaded for this view.')
        ->and($queries->filter(static fn (string $q): bool => pdsrecNamesPdsTable($q)))->toBeEmpty()
        ->and($employee->id)->toBe($view?->id);
});

/* |---| The CSV and the printed 201 sheet |---| */

/** Signs in an Employee Records administrator for the page and the export route. */
function pdsrecSignIn(): void
{
    test()->actingAs(pdsrecUser(pdsrecRole([ModuleCode::EMPLOYEES], true)));
    app()->forgetInstance(Access::class);
}

/** @return list<array<string, string>> the export's rows, keyed by header */
function pdsrecCsv(): array
{
    $csv = test()->get('/employees/export')->assertOk()->streamedContent();
    $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\r\n", substr($csv, strlen(Csv::BOM))));
    $headers = array_map('strval', array_shift($lines));

    return array_map(static fn (array $cells): array => array_combine($headers, array_map('strval', $cells)), $lines);
}

/** The print-only 201 sheet on the record page. */
function pdsrecSheet(EmployeeProfile $employee): string
{
    $html = Livewire::test(Show::class, ['employee' => $employee->id])->html();
    $start = (int) strpos($html, '<section class="sheet-201');

    return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
}

it('exports the structured-else-legacy citizenship and address in their old columns, and the height in centimetres', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();
    pdsrecSignIn();

    // A record nobody has touched since: the stored one-line address and
    // citizenship in their old columns, nothing in the appended ones, and
    // "Same as residential" blank because nobody has answered it.
    $row = pdsrecCsv()[0];
    expect($row['Address'])->toBe('Blk 3 Lot 7, Poblacion')
        ->and($row['Citizenship'])->toBe('Filipino')
        ->and($row['Height'])->toBe('162.5')
        ->and($row['Gender'])->toBe('Female')
        ->and($row['Contact Number'])->toBe('0917 555 0101')
        ->and($row['Civil Status'])->toBe('Married')
        ->and($row['Citizenship Type'])->toBe('')
        ->and($row['Residential Street'])->toBe('')
        ->and($row['Permanent Address Same as Residential'])->toBe('')
        ->and($row['UMID ID No.'])->toBe('');

    pdsrecSave($hr, $employee, 'personal', [
        'civil_status' => 'Other', 'civil_status_other' => 'Separated in fact',
        'citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'NATURALIZATION', 'dual_citizenship_country' => 'Japan',
    ]);
    pdsrecSave($hr, $employee, 'addresses', [
        'res_house_no' => 'Blk 5 Lot 2', 'res_street' => 'Rizal St.', 'res_city' => 'Tayabas City', 'res_province' => 'Quezon', 'res_zip_code' => '4327',
        'permanent_address_same' => false, 'perm_city' => 'Lucena City', 'perm_zip_code' => '4301',
    ]);
    pdsrecSave($hr, $employee, 'statutory', ['umid_no' => '0111-2222333-4', 'philsys_pcn' => '1234-5678-9012-3456']);

    $row = pdsrecCsv()[0];
    expect($row['Address'])->toBe('Blk 5 Lot 2, Rizal St., Tayabas City, Quezon 4327')
        ->and($row['Citizenship'])->toBe('Dual citizenship · by naturalization · Japan')
        ->and($row['Height'])->toBe('162.5')
        ->and($row['Civil Status'])->toBe('Other/s')
        ->and($row['Civil Status Specify'])->toBe('Separated in fact')
        ->and($row['Citizenship Type'])->toBe('Dual citizenship')
        ->and($row['Dual Citizenship By'])->toBe('By naturalization')
        ->and($row['Dual Citizenship Country'])->toBe('Japan')
        ->and($row['Residential House/Block/Lot No.'])->toBe('Blk 5 Lot 2')
        ->and($row['Residential ZIP code'])->toBe('4327')
        ->and($row['Permanent Address Same as Residential'])->toBe('No')
        ->and($row['Permanent City/Municipality'])->toBe('Lucena City')
        ->and($row['Permanent ZIP code'])->toBe('4301')
        ->and($row['UMID ID No.'])->toBe('0111-2222333-4')
        ->and($row['PhilSys Card No.'])->toBe('1234-5678-9012-3456')
        ->and(pdsrecLegacy($employee->id))->toBe(['address' => 'Blk 3 Lot 7, Poblacion', 'citizenship' => 'Filipino']);

    // Same as residential: "Yes", and the permanent parts go with their row.
    pdsrecSave($hr, $employee, 'addresses', ['permanent_address_same' => true]);
    $row = pdsrecCsv()[0];
    expect($row['Permanent Address Same as Residential'])->toBe('Yes')
        ->and($row['Permanent City/Municipality'])->toBe('')
        ->and($row['Permanent ZIP code'])->toBe('');
});

it('prints Other — the specification, the structured-else-legacy citizenship and home address, and centimetres on the 201 sheet', function () {
    $employee = pdsrecEmployee(pdsrecSite());
    $hr = pdsrecHr();
    pdsrecSignIn();

    $sheet = pdsrecSheet($employee);
    expect($sheet)->toContain('Blk 3 Lot 7, Poblacion')
        ->toContain('Filipino')
        ->toContain('Married')
        ->toContain('162.5 cm')
        ->not->toContain('1.625 m');

    // The longest values the columns hold: the sheet never truncates.
    $country = str_repeat('N', 60);
    $specify = str_repeat('S', 60);
    $parts = [
        'res_house_no' => str_repeat('H', 60), 'res_street' => str_repeat('T', 120), 'res_subdivision' => str_repeat('V', 120),
        'res_barangay' => str_repeat('B', 120), 'res_city' => str_repeat('C', 120), 'res_province' => str_repeat('P', 120),
        'res_zip_code' => '4327',
    ];
    pdsrecSave($hr, $employee, 'personal', [
        'civil_status' => 'Other', 'civil_status_other' => $specify,
        'citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'NATURALIZATION', 'dual_citizenship_country' => $country,
    ]);
    pdsrecSave($hr, $employee, 'addresses', $parts);

    $sheet = pdsrecSheet($employee);
    $line = implode(', ', array_slice(array_values($parts), 0, 6)).' 4327';
    expect($sheet)->toContain('Other — '.$specify)
        ->toContain('Dual citizenship · by naturalization · '.$country)
        ->toContain($line)
        ->not->toContain('Blk 3 Lot 7, Poblacion')
        ->toContain('162.5 cm')
        ->and(pdsrecLegacy($employee->id))->toBe(['address' => 'Blk 3 Lot 7, Poblacion', 'citizenship' => 'Filipino']);

    // Any other civil status prints exactly as stored.
    pdsrecSave($hr, $employee, 'personal', ['civil_status' => 'Widowed']);
    expect(pdsrecSheet($employee))->toContain('Widowed')->not->toContain($specify);
});

it('exports and prints the legacy address and citizenship while the details tables are missing', function () {
    pdsrecWithoutTables(function (): void {
        $employee = pdsrecEmployee(pdsrecSite());
        pdsrecSignIn();

        $row = pdsrecCsv()[0];
        expect($row['Address'])->toBe('Blk 3 Lot 7, Poblacion')
            ->and($row['Citizenship'])->toBe('Filipino')
            ->and($row['Permanent Address Same as Residential'])->toBe('')
            ->and(pdsrecSheet($employee))->toContain('Blk 3 Lot 7, Poblacion')->toContain('Filipino');
    });
});
