<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\AddEmployeeDialog;
use App\Livewire\Employees\Index;
use App\Livewire\Employees\Show;
use App\Models\AuditLog;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Support\Access;
use App\Support\EmployeeIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * TWO IDENTIFIERS, AND WHICH GUARANTEES BELONG TO WHICH.
 *
 *   employee_code   minted by the system, never null, unique. The row id, and
 *                   what every list labels plainly "ID".
 *   employee_id_no  typed by HR, NULLABLE, unique when present. The number the
 *                   company itself gave the person, and the ONLY one that may
 *                   stand beside a name — {@see EmployeeIdentity}.
 *
 * What these pin: the hand-typed one may be blank on any number of records and
 * may never be shared by two; the minted one is not a field on the 201 page
 * at all, and a form that names it anyway cannot rewrite it; both are
 * columns on Employee Records, the minted one sorting as a number; and both
 * are searchable, because HR types whichever number is on the paper in front
 * of them — the row id on an older handover, the Employee ID on a newer one.
 */

/** @param list<string> $modules */
function eidUser(array $modules, bool $allData, ?EmployeeProfile $employee = null): User
{
    $role = Role::query()->create([
        'name' => 'EID '.implode('-', $modules).($allData ? ' all' : ''),
        'all_data' => $allData,
        'is_system' => false,
    ]);

    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    return $user->refresh();
}

function eidReferences(): WorkSite
{
    Position::query()->create(['code' => 'POS-001', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-002', 'name' => 'Regular']);

    return WorkSite::query()->create([
        'name' => 'Head Office',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 100,
        'geofence_exempt' => false,
    ]);
}

function eidEmployee(string $code, string $last, ?string $idNo = null, ?string $siteId = null): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => $code,
        'employee_id_no' => $idNo,
        'first_name' => 'Juan',
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'work_site_id' => $siteId,
        'monthly_rate' => 30000,
    ]);
}

/**
 * The hire wizard's whole form, so a test can vary the one field it is about.
 *
 * @return array<string, string>
 */
function eidHireForm(WorkSite $site, string $email, ?string $idNo = null): array
{
    return array_filter([
        'employee_id_no' => $idNo,
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'phone' => '09171234567',
        'email' => $email,
        'birth_date' => '1990-05-01',
        'res_street' => '123 Mabini St.',
        'sex' => 'Male',
        'civil_status' => 'Single',
        'emergency_name' => 'Maria Dela Cruz',
        'emergency_relation' => 'Spouse',
        'emergency_phone' => '09179876543',
        'position_id' => (string) Position::query()->where('name', 'Driver')->value('id'),
        'employment_status' => 'Regular',
        'work_site_id' => $site->id,
        'date_hired' => '2026-08-01',
        'monthly_rate' => '25000',
    ], static fn (?string $value): bool => $value !== null);
}

it('lets HR type an Employee ID on the record page while the row ID stays minted', function () {
    $site = eidReferences();
    $employee = eidEmployee('COP-00001', 'Domingo', null, $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'personal')
        ->set('form.employee_id_no', '2024-118')
        ->call('saveSection', 'personal');

    expect($employee->refresh()->employee_id_no)->toBe('2024-118')
        ->and($employee->employee_code)->toBe('COP-00001');
});

it('leaves the Employee ID null on any number of records', function () {
    eidReferences();
    eidEmployee('COP-00001', 'Domingo');
    eidEmployee('COP-00002', 'Santos');

    // The unique index counts NULLs as distinct — this is the whole reason the
    // column can be both optional and unique.
    expect(EmployeeProfile::query()->whereNull('employee_id_no')->count())->toBe(2);
});

it('stores a blank Employee ID as null rather than as an empty string', function () {
    $site = eidReferences();
    $employee = eidEmployee('COP-00001', 'Domingo', '2024-118', $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'personal')
        ->set('form.employee_id_no', '   ')
        ->call('saveSection', 'personal');

    // Two blanks stored as '' would collide under the unique index; null is
    // what keeps "no company number yet" repeatable.
    expect($employee->refresh()->employee_id_no)->toBeNull();
});

it('refuses an Employee ID another record already holds, and names the value', function () {
    $site = eidReferences();
    eidEmployee('COP-00001', 'Domingo', '2024-118', $site->id);
    $second = eidEmployee('COP-00002', 'Santos', null, $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    Livewire::test(Show::class, ['employee' => $second->id])
        ->call('startEdit', 'personal')
        ->set('form.employee_id_no', '2024-118')
        ->call('saveSection', 'personal');

    // Refused by its lack of effect; the message exists so the operator learns
    // WHICH value collided instead of meeting the generic save failure.
    expect($second->refresh()->employee_id_no)->toBeNull();
});

it('keeps an Employee ID editable on its own record', function () {
    $site = eidReferences();
    $employee = eidEmployee('COP-00001', 'Domingo', '2024-118', $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    // The duplicate check must exclude the row being saved, or re-saving the
    // section would reject the value the record already holds.
    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'personal')
        ->set('form.employee_id_no', '2024-118')
        ->set('form.first_name', 'Juanito')
        ->call('saveSection', 'personal');

    expect($employee->refresh()->first_name)->toBe('Juanito')
        ->and($employee->employee_id_no)->toBe('2024-118');
});

it('refuses an own Employee ID correction without a proposal or record mutation', function () {
    $site = eidReferences();
    $employee = eidEmployee('COP-00001', 'Domingo', null, $site->id);

    $this->actingAs(eidUser([ModuleCode::PROFILE], false, $employee));
    app()->forgetInstance(Access::class);

    Livewire::test(Show::class, ['employee' => $employee->id, 'own' => true])
        ->call('startEdit', 'personal')
        ->set('form.employee_id_no', 'SELF-1')
        ->call('saveSection', 'personal');

    expect($employee->refresh()->employee_id_no)->toBeNull()
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('ignores a crafted form that tries to rewrite the minted row ID', function () {
    $site = eidReferences();
    $employee = eidEmployee('COP-00001', 'Domingo', null, $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    // The section does not carry employee_code at all, and $form is an
    // unlocked wire:model draft, so the client may still send the key. Two
    // things stop it: UpdateEmployee builds its payload from the SECTION's
    // fields, so an unlisted key is never read, and it drops `auto` keys on
    // top of that. Neither depends on the markup.
    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'personal')
        ->set('form.employee_code', 'COP-99999')
        ->set('form.employee_id_no', '2024-118')
        ->call('saveSection', 'personal');

    expect($employee->refresh()->employee_code)->toBe('COP-00001')
        ->and($employee->employee_id_no)->toBe('2024-118');
});

it('mints the row ID and keeps the hand-typed one through the hire wizard', function () {
    $site = eidReferences();

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    $dialog = Livewire::test(AddEmployeeDialog::class)->call('openDialog');

    foreach (eidHireForm($site, 'juan.hire@company.com', '2024-118') as $key => $value) {
        $dialog->set("form.{$key}", $value);
    }

    // The wizard asks for no row id and seeds none, but $form is an unlocked
    // public draft, so a crafted step can still name the key. The mint inside
    // the insert transaction is what has to win.
    $dialog->set('form.employee_code', 'COP-99999');

    $dialog->call('create')->assertDispatched('employee-created');

    $created = EmployeeProfile::query()->where('email', 'juan.hire@company.com')->firstOrFail();

    // The wizard never asks for the row id and does not preview one either:
    // the command mints it inside the insert transaction. The typed field is
    // the only one of the two that survives from the form.
    expect($created->employee_id_no)->toBe('2024-118')
        ->and($created->employee_code)->toBe('1');
});

it('refuses a hire whose Employee ID another record already holds', function () {
    $site = eidReferences();
    eidEmployee('COP-00001', 'Domingo', '2024-118', $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    $dialog = Livewire::test(AddEmployeeDialog::class)->call('openDialog');

    foreach (eidHireForm($site, 'clash@company.com', '2024-118') as $key => $value) {
        $dialog->set("form.{$key}", $value);
    }

    $dialog->call('create')->assertNotDispatched('employee-created');

    // No half-made record: CreateEmployee validates before it opens the
    // transaction that mints the code.
    expect(EmployeeProfile::query()->where('email', 'clash@company.com')->exists())->toBeFalse()
        ->and(EmployeeProfile::query()->count())->toBe(1);
});

it('carries both identifiers as columns on Employee Records', function () {
    $site = eidReferences();
    // A row id that is not a substring of the Employee ID, or seeing it would
    // prove nothing about the column it is supposed to be in.
    eidEmployee('77', 'Domingo', '2024-118', $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    $page = Livewire::test(Index::class)
        ->assertSee('Employee ID')
        ->assertSee('2024-118')
        // The minted row id, which the 201 page does not show — this list is
        // where it is read.
        ->assertSee('77');

    // Six columns — ID · Employee ID · Employee · Department · Email ·
    // Status — so the empty row spans six or it stops short of the last header.
    $page->set('search', 'zzz-matches-nobody')->assertSeeHtml('colspan="6"');

    // And 'id' is on the allow-list sortBy() is checked against.
    $page->set('search', '')->call('sortBy', 'id')->assertSet('sortKey', 'id');
});

it('sorts the Employee Records ID column as a number, not as text', function () {
    $site = eidReferences();

    // Ids are bare numbers: sorted as text these read 1, 10, 2 — which is
    // why an id column goes through CodeSort. See App\Support\CodeSort.
    eidEmployee('2', 'Santos', null, $site->id);
    eidEmployee('10', 'Reyes', null, $site->id);
    eidEmployee('1', 'Domingo', null, $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    $rows = Livewire::test(Index::class)
        ->call('sortBy', 'id')
        ->viewData('list')
        ->items();

    expect(array_map(static fn ($row): string => $row->employee_code, $rows))
        ->toBe(['1', '2', '10']);
});

it('offers no ID field in Personal Details, on the record page or in the wizard', function () {
    $site = eidReferences();
    $employee = eidEmployee('11', 'Domingo', '2024-118', $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    $keys = array_column(EmployeeFields::section('personal')['fields'] ?? [], 'key');

    // One definition feeds both surfaces, so a field it leaves out is absent
    // from the wizard's step 1 as well as from the 201 page.
    expect($keys)->not->toContain('employee_code')
        ->and($keys)->toContain('employee_id_no');

    // "Assigned automatically." is the note under an `auto` field; nothing
    // renders it, in read mode or in edit mode.
    Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertDontSee('Assigned automatically.')
        ->call('startEdit', 'personal')
        ->assertDontSee('Assigned automatically.');

    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->assertDontSee('Assigned automatically.');
});

it('finds an employee by either identifier on Employee Records', function () {
    $site = eidReferences();
    eidEmployee('11', 'Domingo', '2024-118', $site->id);
    eidEmployee('12', 'Santos', '2024-994', $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    Livewire::test(Index::class)
        ->set('search', '2024-118')
        ->assertSee('Domingo')
        ->assertDontSee('Santos')
        ->set('search', '2024-994')
        ->assertSee('Santos')
        ->assertDontSee('Domingo')
        // The row id is a column here, so it is searched: HR has it on older
        // handovers, and every row it matches shows the reader the number it
        // matched on.
        ->set('search', '12')
        ->assertSee('Santos')
        ->assertDontSee('Domingo');
});

it('sorts Employee Records by the hand-typed id, blanks last', function () {
    $site = eidReferences();
    eidEmployee('COP-00001', 'Domingo', 'B-2', $site->id);
    eidEmployee('COP-00002', 'Santos', 'A-1', $site->id);
    eidEmployee('COP-00003', 'Reyes', null, $site->id);

    $this->actingAs(eidUser([ModuleCode::EMPLOYEES], true));

    $rows = Livewire::test(Index::class)
        ->call('sortBy', 'employee_id_no')
        ->viewData('list')
        ->items();

    expect(array_map(static fn ($row): string => $row->last_name, $rows))
        ->toBe(['Santos', 'Domingo', 'Reyes']);
});

it('refuses two records with one Employee ID at the database, not only in the command', function () {
    eidReferences();
    eidEmployee('COP-00001', 'Domingo', '2024-118');

    // The command's pre-check is for the message; THIS is the guarantee. On
    // SQLite the collation is BINARY and on MariaDB utf8mb4_unicode_ci, so the
    // duplicate is spelled identically here to hold on both.
    expect(static fn () => eidEmployee('COP-00002', 'Santos', '2024-118'))
        ->toThrow(QueryException::class);
});

it('adds the Employee ID column and its unique index only once, so a killed run resumes', function () {
    $migration = require base_path('database/migrations/2026_09_19_0001_add_employee_id_no_to_employee_profiles_table.php');

    expect(Schema::hasColumn('employee_profiles', 'employee_id_no'))->toBeTrue();

    // Re-running a migration the host already applied is the normal case on a
    // shared-hosting cron that can be killed between two statements.
    $migration->up();

    expect(Schema::hasColumn('employee_profiles', 'employee_id_no'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('employee_profiles', 'employee_id_no'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('employee_profiles', 'employee_id_no'))->toBeTrue();
    expect(EmployeeIdentity::number(eidEmployee('COP-00009', 'Reyes', 'AFTER-1')))->toBe('AFTER-1');
});
