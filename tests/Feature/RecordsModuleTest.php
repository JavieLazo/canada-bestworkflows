<?php

declare(strict_types=1);

use App\Livewire\Records\HolidaysPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Records\SitesPage;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Records module: /records/* gates on the RECORDS grant, the usage counts'
 * scoped reads return NOTHING for an employee-less actor, and the CRUD flows
 * sit behind the all_data + RECORDS gate exactly as requireAllDataModule did.
 */
function recordsRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Records '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

function recordsActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => recordsRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function recordsEmployee(string $code, string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Clerk',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('redirects guests to login', function () {
    $this->get('/records/positions')->assertRedirect(route('login'));
});

it('turns away a signed-in user without the RECORDS grant', function () {
    recordsActor(['LEAVES'], false);

    // EnsureModuleAccess redirects a browser to whatever it may see.
    $this->get('/records/positions')->assertRedirect('/leaves');
    $this->get('/records/holidays')->assertRedirect('/leaves');
    $this->get('/records/sites')->assertRedirect('/leaves');
});

it('sends /records to the first list, exactly as records/page.tsx does', function () {
    recordsActor(['RECORDS'], true);

    $this->get('/records')->assertRedirect('/records/positions');
});

it('renders every screen for a RECORDS holder', function () {
    recordsActor(['RECORDS'], true);

    $this->get('/records/positions')->assertOk()->assertSee('Position');
    $this->get('/records/leave-types')->assertOk()->assertSee('Leave Settings');
    $this->get('/records/holidays')->assertOk()->assertSee('Holiday Settings');
    $this->get('/records/shifts')->assertOk()->assertSee('Shifting Settings');
    $this->get('/records/sites')->assertOk()->assertSee('Site Settings');
    $this->get('/records/payroll-formulas')->assertOk()->assertSee('Payroll Formula Settings');
});

it('404s a slug that is not a reference list', function () {
    recordsActor(['RECORDS'], true);

    $this->get('/records/not-a-list')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Scoping — the employee-less actor's scoped reads see NOTHING
|--------------------------------------------------------------------------
*/

it('counts zero usage for an employee-less actor without all_data', function () {
    $employee = recordsEmployee('BWF-00021', 'Liza', 'Domingo');

    $request = new LeaveTravelRequest;
    $request->forceFill([
        'code' => 'REQ-001',
        'employee_id' => $employee->id,
        'request_type' => 'SICK',
        'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-04',
        'status' => 'PENDING',
    ])->save();

    recordsActor(['RECORDS'], false, null);

    // The scoped leave read behind the leave-types usage column is empty —
    // not IS NULL rows.
    $access = Access::forActor(auth()->user());
    expect(LeaveTravelRequest::query()->toEmployee($access, 'employee_id', 'LEAVES')->get())->toBeEmpty();

    // And the page renders without leaking a count.
    Livewire::test(ReferenceListPage::class, ['slug' => 'leave-types'])->assertStatus(200);
});

/*
|--------------------------------------------------------------------------
| Reference list CRUD — the happy path and its gate
|--------------------------------------------------------------------------
*/

it('adds, renames and deletes a position behind the all_data + RECORDS gate', function () {
    recordsActor(['RECORDS'], true);

    // Add — the next POS-000 code is read from the rows.
    Livewire::test(ReferenceListPage::class, ['slug' => 'positions'])
        ->call('openAdd')
        ->set('formName', 'Driver')
        ->set('formDescription', 'Company drivers')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $row = Position::query()->where('name', 'Driver')->first();

    expect($row)->not->toBeNull()
        ->and($row->code)->toBe('POS-001')
        ->and($row->description)->toBe('Company drivers');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'REFERENCE_CREATED',
        'entity' => 'positions',
        'entity_id' => (string) $row->getKey(),
    ]);

    // Rename.
    Livewire::test(ReferenceListPage::class, ['slug' => 'positions'])
        ->call('startEdit', (string) $row->getKey())
        ->set('formName', 'Company Driver')
        ->call('save');

    expect($row->fresh()->name)->toBe('Company Driver');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'REFERENCE_UPDATED',
        'entity' => 'positions',
        'entity_id' => (string) $row->getKey(),
    ]);

    // Delete.
    Livewire::test(ReferenceListPage::class, ['slug' => 'positions'])
        ->call('confirmDelete', (string) $row->getKey())
        ->call('deleteConfirmed');

    expect(Position::query()->whereKey($row->getKey())->exists())->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'REFERENCE_DELETED',
        'entity' => 'positions',
        'entity_id' => (string) $row->getKey(),
    ]);
});

it('requires a name, inline', function () {
    recordsActor(['RECORDS'], true);

    Livewire::test(ReferenceListPage::class, ['slug' => 'positions'])
        ->call('openAdd')
        ->set('formName', '   ')
        ->call('save')
        ->assertSet('formError', 'Name is required.')
        ->assertSet('dialogOpen', true);

    expect(Position::query()->count())->toBe(0);
});

it('refuses a write from a RECORDS holder without all_data', function () {
    recordsActor(['RECORDS'], false);

    Livewire::test(ReferenceListPage::class, ['slug' => 'positions'])
        ->call('openAdd')
        ->set('formName', 'Driver')
        ->call('save');

    expect(Position::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Holidays, shift types, sites — one flow each
|--------------------------------------------------------------------------
*/

it('adds a holiday with the HOL-000 code and the dialog defaults', function () {
    recordsActor(['RECORDS'], true);

    Livewire::test(HolidaysPage::class)
        ->call('openAdd')
        ->set('formName', 'Rizal Day')
        ->set('formDate', '2026-12-30')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $this->assertDatabaseHas('holidays', [
        'code' => 'HOL-001',
        'name' => 'Rizal Day',
        'schedule' => 'ANNUAL',
        'type' => 'REGULAR',
    ]);

    $this->assertDatabaseHas('audit_logs', ['action' => 'HOLIDAY_CREATED', 'entity' => 'holidays']);
});

it('adds a fixed shift pattern and refuses a broken one', function () {
    recordsActor(['RECORDS'], true);

    // The dialog's defaults are a complete 08:00–17:00 pattern.
    Livewire::test(ShiftTypesPage::class)
        ->call('openAdd')
        ->set('formName', 'Day Shift')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $this->assertDatabaseHas('shift_types', [
        'code' => 'SFT-001',
        'name' => 'Day Shift',
        'mode' => 'FIXED',
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'grace_minutes' => 15,
        'break_start' => '12:00:00',
        'break_end' => '13:00:00',
        'break_minutes' => 60,
        'requires_break_punch' => 1,
    ]);

    // A flexible pattern owing zero hours trips the shape policy and stays
    // in the dialog.
    Livewire::test(ShiftTypesPage::class)
        ->call('openAdd')
        ->set('formName', 'Broken Flex')
        ->set('formMode', 'FLEXIBLE')
        ->set('formRequiredHours', '0')
        ->call('save')
        ->assertSet('dialogOpen', true);

    expect(ShiftType::query()->where('name', 'Broken Flex')->exists())->toBeFalse();
});

it('adds a site, validates its geofence, and blocks deleting a used one', function () {
    recordsActor(['RECORDS'], true);

    // 0, 0 is not a place.
    Livewire::test(SitesPage::class)
        ->call('openAdd')
        ->set('formName', 'Nowhere')
        ->set('formLatitude', '0')
        ->set('formLongitude', '0')
        ->call('save')
        ->assertSet('dialogOpen', true);

    expect(WorkSite::query()->count())->toBe(0);

    // A real site saves.
    Livewire::test(SitesPage::class)
        ->call('openAdd')
        ->set('formName', 'Makati Head Office')
        ->set('formLatitude', '14.5547')
        ->set('formLongitude', '121.0244')
        ->set('formRadius', '100')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $site = WorkSite::query()->where('name', 'Makati Head Office')->first();

    expect($site)->not->toBeNull()
        ->and($site->radius_meters)->toBe(100)
        ->and($site->geofence_exempt)->toBeFalse();

    // A site with an assigned employee must remain.
    $employee = recordsEmployee('BWF-00031', 'Miguel', 'Reyes');
    $employee->forceFill(['work_site_id' => $site->id])->save();

    Livewire::test(SitesPage::class)
        ->call('confirmDelete', (string) $site->id)
        ->call('deleteConfirmed');

    expect(WorkSite::query()->whereKey($site->id)->exists())->toBeTrue();
});
