<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** The disposable MariaDB database must enforce the expanded grant registry without granting access. */
it('retries the inventory grant constraint and preserves existing role assignments', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $role = new Role;
    $role->forceFill(['name' => 'Inventory grant fixture', 'all_data' => true, 'is_system' => false])->save();
    foreach (['EMPLOYEES', 'PORTAL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $before = RoleModuleGrant::query()->orderBy('id')->get()->toArray();
    $migration = require base_path('database/migrations/2026_10_08_0003_add_inventory_to_role_module_grants_table.php');
    $migration->up();
    $migration->up();
    expect(RoleModuleGrant::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and(RoleModuleGrant::query()->where('module', ModuleCode::INVENTORY)->exists())->toBeFalse();

    DB::statement('ALTER TABLE role_module_grants DROP CONSTRAINT rmg_module_chk');
    $migration->up();
    $migration->up();
    $check = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'role_module_grants' AND CONSTRAINT_NAME = 'rmg_module_chk' AND CONSTRAINT_TYPE = 'CHECK'");
    expect((int) $check->n)->toBe(1);

    foreach (array_diff(ModuleCode::all(), ['EMPLOYEES', 'PORTAL']) as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    expect(RoleModuleGrant::query()->where('role_id', $role->id)->count())->toBe(count(ModuleCode::all()));
    $invalid = new RoleModuleGrant;
    $invalid->forceFill(['role_id' => $role->id, 'module' => 'UNKNOWN']);
    expect(fn () => $invalid->save())->toThrow(QueryException::class, 'rmg_module_chk');
});
