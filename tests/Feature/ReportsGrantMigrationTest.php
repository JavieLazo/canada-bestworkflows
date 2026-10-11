<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\RoleModuleGrant;

/**
 * Adding the Reports navigation grant never assigns access to existing roles.
 * Retrying the migration preserves grants; rollback removes REPORTS only.
 */
it('retries the Reports grant migration without assigning access and rolls back only Reports grants', function () {
    $role = new Role;
    $role->forceFill(['name' => 'Reports migration role', 'all_data' => true, 'is_system' => false])->save();
    $payroll = new RoleModuleGrant;
    $payroll->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();
    $migration = require base_path('database/migrations/2026_10_02_0007_add_reports_to_role_module_grants_table.php');

    try {
        $migration->up();
        $migration->up();

        expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->pluck('module')->all())->toBe(['PAYROLL']);

        $reports = new RoleModuleGrant;
        $reports->forceFill(['role_id' => $role->getKey(), 'module' => 'REPORTS'])->save();
        $migration->up();

        expect(RoleModuleGrant::query()->whereKey($reports->getKey())->exists())->toBeTrue();

        $migration->down();
        $migration->down();

        expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->pluck('module')->all())->toBe(['PAYROLL'])
            ->and(Role::query()->whereKey($role->getKey())->exists())->toBeTrue();
    } finally {
        // MySQL DDL commits implicitly; restore the latest CHECK and remove
        // this fixture explicitly so later tests see the fully migrated schema.
        $migration->up();
        Role::query()->whereKey($role->getKey())->delete();
    }
});
