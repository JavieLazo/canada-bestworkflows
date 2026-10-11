<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Show;
use App\Models\EmployeeAddress;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Own address and personal-data proposals preserve the official record and unrelated evidence until approved. */

/** @param list<string> $modules ModuleCode constants, one grant each */
function profaddrActor(array $modules, ?EmployeeProfile $employee, bool $allData = false): User
{
    $role = Role::query()->create([
        'name' => 'Test profile address '.Str::random(6),
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

/** @param array<string, mixed> $columns */
function profaddrEmployee(string $code = '7', array $columns = []): EmployeeProfile
{
    return EmployeeProfile::query()->create($columns + [
        'employee_code' => $code,
        'first_name' => 'Ana',
        'last_name' => 'Reyes '.$code,
        'employment_status' => 'Regular',
        'date_hired' => '2024-02-01',
        'monthly_rate' => 25000,
        'address' => 'Blk 3 Lot 7, Poblacion',
    ]);
}

/** @param array<string, string|null> $parts */
function profaddrAddress(EmployeeProfile $employee, string $type, array $parts): EmployeeAddress
{
    return EmployeeAddress::query()->create(['employee_id' => $employee->id, 'type' => $type] + $parts);
}

/** @return array<string, array<string, mixed>> type => stored parts */
function profaddrRows(EmployeeProfile $employee): array
{
    return EmployeeAddress::query()->where('employee_id', $employee->id)->get()
        ->mapWithKeys(fn (EmployeeAddress $row): array => [$row->type => $row->only(['house_no', 'street', 'subdivision', 'barangay', 'city', 'province', 'zip_code'])])
        ->sortKeys()
        ->all();
}

function profaddrLegacyAddress(EmployeeProfile $employee): ?string
{
    $value = DB::table('employee_profiles')->where('id', $employee->id)->value('address');

    return is_string($value) ? $value : null;
}

/** The toast text a Livewire test saw, matched exactly. */
function profaddrToast(string $text): Closure
{
    return fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === $text;
}

function profaddrApprove(): void
{
    $reviewer = profaddrActor([ModuleCode::EMPLOYEES], null, true);
    $request = EmployeeRecordChangeRequest::query()->where('status', 'PENDING')->firstOrFail();
    app(ReviewRecordChange::class)->handle(Access::forActor($reviewer), $request->id, true);
}

it('shares the complete address form and legacy note with the official record', function () {
    $employee = profaddrEmployee();
    $this->actingAs(profaddrActor([ModuleCode::PROFILE], $employee));
    $page = Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'addresses')
        ->assertSee('Entered before: Blk 3 Lot 7, Poblacion')
        ->assertSeeHtml('aria-label="Residential house/block/lot no."')
        ->assertSeeHtml('aria-label="Residential ZIP code"')
        ->assertSeeHtml('aria-label="Same as residential"')
        ->assertSet('form.res_street', '')
        ->assertDontSeeHtml('wire:model="personal.address"');
    $page->set('form.permanent_address_same', 'false')
        ->assertSeeHtml('aria-label="Permanent street"')
        ->assertSeeHtml('aria-label="Permanent ZIP code"');
});

it('applies approved address parts together while preserving the legacy address and unrelated identifiers', function () {
    $employee = profaddrEmployee();
    EmployeePdsDetail::query()->create(['employee_id' => $employee->id, 'umid_no' => '0111-2222333-4', 'philsys_pcn' => '1234-5678-9012-3456']);
    $this->actingAs(profaddrActor([ModuleCode::PROFILE], $employee));
    Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'addresses')
        ->set('form.res_house_no', 'Blk 5 Lot 2')
        ->set('form.res_street', 'Rizal St.')
        ->set('form.res_zip_code', '4327')
        ->set('form.permanent_address_same', 'false')
        ->set('form.perm_city', 'Lucena City')
        ->call('saveSection', 'addresses')
        ->assertDispatched('record-change-submitted');
    expect(profaddrRows($employee))->toBe([]);
    profaddrApprove();
    $details = EmployeePdsDetail::query()->where('employee_id', $employee->id)->firstOrFail();
    expect(profaddrRows($employee)['RESIDENTIAL']['street'])->toBe('Rizal St.')
        ->and(profaddrRows($employee)['PERMANENT']['city'])->toBe('Lucena City')
        ->and($details->permanent_address_same)->toBeFalse()
        ->and($details->umid_no)->toBe('0111-2222333-4')
        ->and($details->philsys_pcn)->toBe('1234-5678-9012-3456')
        ->and(profaddrLegacyAddress($employee))->toBe('Blk 3 Lot 7, Poblacion');

    Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'addresses')
        ->set('form.permanent_address_same', 'true')
        ->call('saveSection', 'addresses');
    expect(array_keys(profaddrRows($employee)))->toBe(['PERMANENT', 'RESIDENTIAL']);
    profaddrApprove();
    expect(array_keys(profaddrRows($employee)))->toBe(['RESIDENTIAL']);
});

it('allows an own civil-status correction and specification through approval', function () {
    $employee = profaddrEmployee();
    $this->actingAs(profaddrActor([ModuleCode::PROFILE], $employee));
    Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'personal')
        ->set('form.civil_status', 'Other')
        ->set('form.civil_status_other', 'Civil union')
        ->call('saveSection', 'personal')
        ->assertDispatched('record-change-submitted');
    expect($employee->refresh()->civil_status)->toBeNull();
    profaddrApprove();
    expect($employee->refresh()->civil_status)->toBe('Other')
        ->and(EmployeePdsDetail::query()->where('employee_id', $employee->id)->value('civil_status_other'))->toBe('Civil union');
});

it('shows citizenship and statutory identifiers as part of the full own record', function () {
    $employee = profaddrEmployee();
    EmployeePdsDetail::query()->create(['employee_id' => $employee->id, 'citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'NATURALIZATION', 'dual_citizenship_country' => 'Canada', 'umid_no' => '0111-2222333-4', 'philsys_pcn' => '1234-5678-9012-3456']);
    $this->actingAs(profaddrActor([ModuleCode::PROFILE], $employee));
    Livewire::test(Show::class, ['own' => true])
        ->assertSee('Canada')
        ->assertSee('0111-2222333-4')
        ->assertSee('1234-5678-9012-3456')
        ->call('startEdit', 'statutory')
        ->assertSee('Submit for approval');
});

it('keeps all own reads and proposals pinned even when a manager supplies another employee id', function () {
    $own = profaddrEmployee('7');
    $other = profaddrEmployee('8');
    $this->actingAs(profaddrActor([ModuleCode::PROFILE, ModuleCode::EMPLOYEES], $own, true));
    Livewire::test(Show::class, ['own' => true, 'employee' => $other->id])
        ->assertSet('employeeId', $own->id)
        ->call('startEdit', 'addresses')
        ->set('form.employee_id', $other->id)
        ->set('form.id', $other->id)
        ->set('form.res_city', 'Tayabas City')
        ->call('saveSection', 'addresses');
    expect(EmployeeRecordChangeRequest::query()->value('employee_id'))->toBe($own->id)
        ->and(profaddrRows($own))->toBe([])
        ->and(profaddrRows($other))->toBe([]);
    profaddrApprove();
    expect(profaddrRows($own)['RESIDENTIAL']['city'])->toBe('Tayabas City')
        ->and(profaddrRows($other))->toBe([]);
});

it('refuses malformed ZIP and a civil-status Other without a specification', function () {
    $employee = profaddrEmployee('7', ['civil_status' => 'Single']);
    profaddrAddress($employee, EmployeeAddress::RESIDENTIAL, ['street' => 'Rizal St.', 'zip_code' => '4327']);
    $before = profaddrRows($employee);
    $this->actingAs(profaddrActor([ModuleCode::PROFILE], $employee));
    Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'addresses')
        ->set('form.res_zip_code', '43')
        ->call('saveSection', 'addresses')
        ->assertSet('formErrors.res_zip_code', 'ZIP code must be 4 digits.')
        ->call('startEdit', 'personal')
        ->set('form.civil_status', 'Other')
        ->set('form.civil_status_other', '   ')
        ->call('saveSection', 'personal');
    expect(profaddrRows($employee))->toBe($before)
        ->and($employee->refresh()->civil_status)->toBe('Single')
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
});

it('does not create a request when there is no change to the official values', function () {
    $employee = profaddrEmployee();
    profaddrAddress($employee, EmployeeAddress::RESIDENTIAL, ['street' => 'Rizal St.']);
    EmployeePdsDetail::query()->create(['employee_id' => $employee->id, 'permanent_address_same' => true]);
    $this->actingAs(profaddrActor([ModuleCode::PROFILE], $employee));
    Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'addresses')
        ->call('saveSection', 'addresses')
        ->assertDispatched('toast-show', profaddrToast('There are no changes to submit.'));
    expect(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and(profaddrRows($employee)['RESIDENTIAL']['street'])->toBe('Rizal St.');
});
