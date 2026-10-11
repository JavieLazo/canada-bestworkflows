<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\InventoryItem;
use App\Models\InventoryReceipt;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\ReturnItems;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Infrastructure\AccountReferences;
use App\Modules\Inventory\Infrastructure\EmployeeReferences;
use App\Modules\Workforce\Application\Commands\DeleteEmployee;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\Queries\EmploymentObligations;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Platform\Retention\EmployeeObligations;
use App\Support\Access;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Property custody integrates with employment and retention without coupling employee identities to accounts. */

/** @param list<string> $modules */
function inventoryIntegrationActor(array $modules = ['INVENTORY', 'EMPLOYEES', 'USERS'], bool $all = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Inventory integration '.Str::random(8), 'all_data' => $all, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $actor = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($actor);
    app()->forgetInstance(Access::class);

    return $actor;
}

function inventoryIntegrationEmployee(string $code): EmployeeProfile
{
    if (! EmploymentStatusOption::query()->where('name', 'Regular')->exists()) {
        $status = new EmploymentStatusOption;
        $status->forceFill(['code' => 'II-REGULAR', 'name' => 'Regular'])->save();
    }
    $site = new WorkSite;
    $site->forceFill(['name' => 'Worksite '.$code, 'latitude' => 14.5, 'longitude' => 120.9, 'radius_meters' => 100])->save();
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => $code, 'first_name' => $code, 'last_name' => 'Employee', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'work_site_id' => $site->id, 'is_active' => true, 'monthly_rate' => 30000, 'email' => 'private@example.test', 'phone' => '09170000000', 'address' => 'Private address'])->save();

    return $employee;
}

/** @return array{string,string} */
function inventoryIntegrationIssue(EmployeeProfile $employee, EmployeeProfile $issuer): array
{
    $access = app(Access::class);
    $itemId = app(SaveItem::class)->handle($access, null, ['code' => 'II-'.Str::random(8), 'name' => 'Accountable laptop', 'serial_no' => 'II-'.Str::random(8), 'acquired_on' => '2026-01-02', 'cost' => 25000]);
    $receiptId = app(IssueItems::class)->handle($access, [$itemId], $employee->id, $issuer->id, '2026-02-03', 'FIX-'.Str::random(12));

    return [$itemId, $receiptId];
}

/** @return array<string,mixed> */
function inventoryIntegrationEmployment(EmployeeProfile $employee, bool $active): array
{
    return ['is_active' => $active] + EmployeeFields::toForm($employee);
}

it('requires acknowledgement and a reason for inactivation while issued property remains', function () {
    inventoryIntegrationActor();
    $employee = inventoryIntegrationEmployee('II-001');
    $issuer = inventoryIntegrationEmployee('II-002');
    [$itemId, $receiptId] = inventoryIntegrationIssue($employee, $issuer);
    $command = app(UpdateEmployee::class);
    $form = inventoryIntegrationEmployment($employee, false);
    $auditCount = AuditLog::query()->count();

    foreach ([[false, ''], [true, ''], [false, 'Recovery will continue after departure'], [true, str_repeat('x', 1001)]] as [$acknowledged, $reason]) {
        expect(fn () => $command->handle(app(Access::class), $employee->id, 'employment', $form, $acknowledged, $reason))->toThrow(RuntimeException::class, 'Outstanding items remain.');
        expect($employee->refresh()->is_active)->toBeTrue();
    }

    expect(AuditLog::query()->count())->toBe($auditCount)
        ->and(InventoryItem::query()->findOrFail($itemId)->employee_id)->toBe($employee->id)
        ->and(InventoryReceipt::query()->findOrFail($receiptId)->status)->toBe('OPEN');
});

it('records authorized inactivation without closing custody or disabling any employee account', function () {
    inventoryIntegrationActor();
    $employee = inventoryIntegrationEmployee('II-003');
    $issuer = inventoryIntegrationEmployee('II-004');
    $firstAccount = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
    $secondAccount = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
    [$itemId, $receiptId] = inventoryIntegrationIssue($employee, $issuer);
    $obligations = app(EmploymentObligations::class)->forEmployee(app(Access::class), $employee->id);
    expect($obligations)->toHaveCount(1)->and($obligations[0]['label'])->toContain('Accountable laptop');

    app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', inventoryIntegrationEmployment($employee, false), true, 'Departure recorded; collection appointment is scheduled.');

    expect($employee->refresh()->is_active)->toBeFalse()
        ->and($firstAccount->refresh()->is_active)->toBeTrue()
        ->and($secondAccount->refresh()->is_active)->toBeTrue()
        ->and(InventoryItem::query()->findOrFail($itemId)->employee_id)->toBe($employee->id)
        ->and(InventoryReceipt::query()->findOrFail($receiptId)->status)->toBe('OPEN')
        ->and(app(EmployeeObligations::class)->outstanding($employee->id))->toHaveCount(1)
        ->and(AuditLog::query()->where('entity_id', $employee->id)->where('details', 'like', '%Departure recorded; collection appointment is scheduled.%')->exists())->toBeTrue();
});

it('permits reactivation and inactivation after all issued items have returned', function () {
    inventoryIntegrationActor();
    $employee = inventoryIntegrationEmployee('II-005');
    $issuer = inventoryIntegrationEmployee('II-006');
    [$itemId] = inventoryIntegrationIssue($employee, $issuer);
    $employee->forceFill(['is_active' => false])->save();

    app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', inventoryIntegrationEmployment($employee, true));
    expect($employee->refresh()->is_active)->toBeTrue();
    app(ReturnItems::class)->handle(app(Access::class), [$itemId], '2026-02-04', 'Returned to property office.');
    app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', inventoryIntegrationEmployment($employee, false));

    expect($employee->refresh()->is_active)->toBeFalse()
        ->and(app(EmployeeObligations::class)->outstanding($employee->id))->toBe([]);
});

it('cannot use an obligation acknowledgement to bypass revoked employee-edit access', function () {
    $actor = inventoryIntegrationActor();
    $employee = inventoryIntegrationEmployee('II-007');
    $issuer = inventoryIntegrationEmployee('II-008');
    inventoryIntegrationIssue($employee, $issuer);
    RoleModuleGrant::query()->where('role_id', $actor->role_id)->where('module', ModuleCode::EMPLOYEES)->delete();
    $this->actingAs($actor->refresh());
    app()->forgetInstance(Access::class);

    expect(fn () => app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'employment', inventoryIntegrationEmployment($employee, false), true, 'Forged authorization'))->toThrow(HttpException::class)
        ->and($employee->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('details', 'like', '%Forged authorization%')->exists())->toBeFalse();
});

it('retains recipient and issuer employee records after property has returned', function () {
    inventoryIntegrationActor();
    $employee = inventoryIntegrationEmployee('II-009');
    $issuer = inventoryIntegrationEmployee('II-010');
    [$itemId] = inventoryIntegrationIssue($employee, $issuer);
    app(ReturnItems::class)->handle(app(Access::class), [$itemId], '2026-02-04');

    foreach ([$employee, $issuer] as $person) {
        $person->forceFill(['is_active' => false])->save();
        expect(app(EmployeeReferences::class)->references($person->id))->toBeTrue();
        expect(fn () => app(DeleteEmployee::class)->handle(app(Access::class), $person->id))->toThrow(RuntimeException::class, 'linked or historical records');
        expect($person->fresh())->not->toBeNull();
    }
});

it('retains the unlinked recording account for inventory evidence', function () {
    $recorder = inventoryIntegrationActor();
    $employee = inventoryIntegrationEmployee('II-011');
    $issuer = inventoryIntegrationEmployee('II-012');
    inventoryIntegrationIssue($employee, $issuer);
    $recorder->forceFill(['is_active' => false])->save();
    inventoryIntegrationActor(['USERS']);

    expect($recorder->employee_id)->toBeNull()
        ->and(app(AccountReferences::class)->references($recorder->id))->toBeTrue();
    expect(fn () => app(ManageAccounts::class)->delete(app(Access::class), (string) $recorder->id))->toThrow(RuntimeException::class, 'linked or historical records');
    expect($recorder->fresh())->not->toBeNull();
});

it('captures only narrow employee identity and placement in an MR snapshot', function () {
    inventoryIntegrationActor();
    $employee = inventoryIntegrationEmployee('II-013');
    $issuer = inventoryIntegrationEmployee('II-014');
    $department = new OrgNode;
    $department->forceFill(['code' => 'II-D1', 'name' => 'Operations', 'kind' => 'DEPARTMENT'])->save();
    $unit = new OrgNode;
    $unit->forceFill(['code' => 'II-U1', 'name' => 'Property office', 'kind' => 'UNIT', 'parent_id' => $department->id])->save();
    $position = new Position;
    $position->forceFill(['code' => 'II-P1', 'name' => 'Property analyst', 'org_node_id' => $unit->id])->save();
    $employee->forceFill(['position_id' => $position->id, 'position' => $position->name])->save();
    $identity = app(InventoryEmployees::class)->identities(app(Access::class), [$employee->id])[$employee->id];

    expect(array_keys($identity))->toBe(['id', 'name', 'position', 'department', 'unit', 'is_active'])
        ->and($identity['department'])->toBe('Operations')->and($identity['unit'])->toBe('Property office');
    foreach (['monthly_rate', 'email', 'phone', 'address', 'sss_no', 'tin'] as $private) {
        expect($identity)->not->toHaveKey($private);
    }
    [, $receiptId] = inventoryIntegrationIssue($employee, $issuer);
    $unit->forceFill(['name' => 'Renamed unit'])->save();
    $employee->forceFill(['first_name' => 'Renamed'])->save();

    expect(InventoryReceipt::query()->findOrFail($receiptId)->employee_snapshot)->toBe($identity)
        ->and(app(InventoryEmployees::class)->identities(app(Access::class), [$employee->id])[$employee->id]['unit'])->toBe('Renamed unit');
});

it('narrows inventory identity lookups to a linked self-service employee and refuses guests', function () {
    $own = inventoryIntegrationEmployee('II-015');
    $foreign = inventoryIntegrationEmployee('II-016');
    inventoryIntegrationActor(['PORTAL'], true, $own->id);
    $query = app(InventoryEmployees::class);

    expect(array_keys($query->identities(app(Access::class), [$own->id, $foreign->id])))->toBe([$own->id])
        ->and($query->identities(Access::forGuest(), [$own->id]))->toBe([]);
    inventoryIntegrationActor(['PORTAL'], true);
    expect($query->identities(app(Access::class), [$own->id]))->toBe([]);
});
