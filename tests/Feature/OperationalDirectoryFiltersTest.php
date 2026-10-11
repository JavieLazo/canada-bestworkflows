<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use Illuminate\Support\Str;

/** Operational employee filters and sorting remain downstream of native permission and ID scope. */

/** @param array<string, mixed> $attributes */
function odfEmployee(array $attributes = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_replace([
        'employee_code' => 'ODF-'.Str::random(8),
        'first_name' => 'Ana',
        'last_name' => 'Employee',
        'date_hired' => '2024-01-01',
        'employment_status' => 'Regular',
        'is_active' => true,
    ], $attributes))->save();

    return $employee;
}

/** @param list<string> $modules */
function odfAccess(array $modules = [ModuleCode::EMPLOYEES], bool $allData = true, ?EmployeeProfile $employee = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Directory filters '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employee?->getKey()]));
}

it('combines position activity employment status search and permitted IDs in the owner query', function () {
    $matched = odfEmployee(['first_name' => 'Ana', 'position' => 'Senior Driver', 'employment_status' => 'Regular']);
    $outsideIds = odfEmployee(['first_name' => 'Ana', 'position' => 'Senior Driver', 'employment_status' => 'Regular']);
    $inactive = odfEmployee(['first_name' => 'Ana', 'position' => 'Senior Driver', 'employment_status' => 'Regular', 'is_active' => false]);
    $otherPosition = odfEmployee(['first_name' => 'Ana', 'position' => 'Driver', 'employment_status' => 'Regular']);
    $otherStatus = odfEmployee(['first_name' => 'Ana', 'position' => 'Senior Driver', 'employment_status' => 'JOB_ORDER']);
    $otherName = odfEmployee(['first_name' => 'Bea', 'position' => 'Senior Driver', 'employment_status' => 'Regular']);
    $ids = [$matched->id, $inactive->id, $otherPosition->id, $otherStatus->id, $otherName->id];

    $rows = app(OperationalDirectory::class)->list(odfAccess(), $ids, 'Ana Driver', [
        'position' => 'Senior Driver', 'active' => 'ACTIVE', 'employmentStatus' => 'Regular',
    ]);

    expect($rows->pluck('id')->all())->toBe([$matched->id])
        ->and($rows->pluck('id')->all())->not->toContain($outsideIds->id)
        ->and($rows->first()?->toArray())->not->toHaveKey('monthly_rate');
});

it('finds unassigned positions without including named positions', function () {
    $null = odfEmployee(['first_name' => 'Ana', 'position' => null]);
    $empty = odfEmployee(['first_name' => 'Bea', 'position' => '']);
    $spaces = odfEmployee(['first_name' => 'Cara', 'position' => '   ']);
    odfEmployee(['first_name' => 'Dana', 'position' => 'Driver']);

    expect(app(OperationalDirectory::class)->list(odfAccess(), filters: ['position' => 'NONE'])->pluck('id')->all())
        ->toBe([$null->id, $empty->id, $spaces->id]);
});

it('keeps employment status values distinct from their legacy tokens', function () {
    $legacy = odfEmployee(['employment_status' => 'JOB_ORDER']);
    odfEmployee(['employment_status' => 'Job Order']);

    expect(app(OperationalDirectory::class)->list(odfAccess(), filters: ['employmentStatus' => 'JOB_ORDER'])->pluck('id')->all())
        ->toBe([$legacy->id]);
});

it('returns the requested activity set and fails closed on an unknown activity filter', function (string $filter, array $expectedNames) {
    odfEmployee(['first_name' => 'Active', 'is_active' => true]);
    odfEmployee(['first_name' => 'Former', 'is_active' => false]);

    expect(app(OperationalDirectory::class)->list(odfAccess(), filters: ['active' => $filter])->pluck('first_name')->all())
        ->toBe($expectedNames);
})->with([
    'all' => ['ALL', ['Active', 'Former']],
    'active' => ['ACTIVE', ['Active']],
    'inactive' => ['INACTIVE', ['Former']],
    'unknown' => ['invalid', []],
]);

it('orders employees using whitelisted sort choices with stable name and suffix ties', function (string $sort, array $expectedNames) {
    odfEmployee(['first_name' => 'Amy', 'last_name' => 'Zimmer', 'employee_id_no' => 'EMP-200', 'position' => 'Clerk']);
    odfEmployee(['first_name' => 'Ben', 'last_name' => 'Alba', 'employee_id_no' => 'EMP-100', 'position' => 'Driver']);
    odfEmployee(['first_name' => 'Ben', 'last_name' => 'Alba', 'suffix' => 'Jr.', 'employee_id_no' => 'EMP-300', 'position' => 'Driver']);
    odfEmployee(['first_name' => 'Carl', 'last_name' => 'Mendoza', 'employee_id_no' => null, 'position' => null]);

    $rows = app(OperationalDirectory::class)->list(odfAccess(), filters: ['sort' => $sort]);

    expect($rows->map(static fn (DirectoryEmployee $employee): string => $employee->first_name.($employee->suffix ?? ''))->all())
        ->toBe($expectedNames);
})->with([
    'name ascending' => ['name-asc', ['Ben', 'BenJr.', 'Carl', 'Amy']],
    'name descending' => ['name-desc', ['Amy', 'Carl', 'BenJr.', 'Ben']],
    'employee ID ascending' => ['employee-id-asc', ['Ben', 'Amy', 'BenJr.', 'Carl']],
    'employee ID descending' => ['employee-id-desc', ['BenJr.', 'Amy', 'Ben', 'Carl']],
    'position' => ['position', ['Amy', 'Ben', 'BenJr.', 'Carl']],
    'invalid sort falls back to names' => ['position desc; drop table employee_profiles', ['Ben', 'BenJr.', 'Carl', 'Amy']],
]);

it('uses the primary key to make fully identical names stable across every sort', function (string $sort) {
    $first = odfEmployee(['position' => 'Driver']);
    $second = odfEmployee(['position' => 'Driver']);
    $expected = [$first->id, $second->id];
    sort($expected, SORT_STRING);
    $directory = app(OperationalDirectory::class);
    $actor = odfAccess();

    expect($directory->list($actor, filters: ['sort' => $sort])->pluck('id')->all())->toBe($expected)
        ->and($directory->list($actor)->pluck('id')->all())->toBe($expected);
})->with(['name-asc', 'name-desc', 'employee-id-asc', 'employee-id-desc', 'position']);

it('preserves own-row visibility when the filters would otherwise match another employee', function () {
    $own = odfEmployee(['position' => 'Clerk']);
    $other = odfEmployee(['position' => 'Driver', 'is_active' => false]);
    $actor = odfAccess([ModuleCode::LEAVES], allData: false, employee: $own);
    $directory = app(OperationalDirectory::class);

    expect($directory->list($actor, [$own->id, $other->id], filters: ['sort' => 'employee-id-desc'])->pluck('id')->all())
        ->toBe([$own->id])
        ->and($directory->list($actor, [$own->id, $other->id], filters: ['position' => 'Driver', 'active' => 'INACTIVE']))->toBeEmpty()
        ->and($directory->list($actor, [$other->id]))->toBeEmpty();
});

it('never returns employees for an unlinked actor without a relevant all-data grant', function () {
    $employee = odfEmployee(['position' => 'Driver']);
    $directory = app(OperationalDirectory::class);
    $actor = odfAccess([ModuleCode::PROFILE]);

    expect($directory->list($actor, [$employee->id], filters: ['position' => 'Driver', 'sort' => 'position']))->toBeEmpty()
        ->and($directory->list(Access::forGuest(), filters: ['active' => 'ALL']))->toBeEmpty();
});

it('retains operational search terms for Employee ID suffix and position with new filters', function () {
    $matched = odfEmployee(['employee_id_no' => '2026-118', 'suffix' => 'Jr.', 'position' => 'Senior Driver']);
    odfEmployee(['employee_id_no' => '2026-119', 'position' => 'Senior Driver']);

    expect(app(OperationalDirectory::class)->list(odfAccess(), search: '2026-118 Jr Driver', filters: ['active' => 'ACTIVE'])->pluck('id')->all())
        ->toBe([$matched->id]);
});
