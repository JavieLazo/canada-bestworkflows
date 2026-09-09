<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\RoleModuleGrant;

/**
 * ROLES + GRANTS from seed.ts:591-605.
 *
 * TASKS is EMPLOYEE-ONLY: My Tasks lists the tasks assigned to the signed-in
 * *employee*, so it belongs to the Employee role alone — not even System Admin
 * holds it. Every other role sees the same tasks from the reviewing end, in
 * Project Management.
 *
 * Grants are additive-idempotent: re-running restores the seeded grants but
 * never deletes ones an admin added through the Users screen.
 */
final class RoleSeeder extends PortSeeder
{
    private const ROLES = [
        ['id' => 'role-admin', 'name' => 'System Admin', 'all_data' => true, 'is_system' => true],
        ['id' => 'role-hr', 'name' => 'HR Officer', 'all_data' => true, 'is_system' => false],
        ['id' => 'role-payroll', 'name' => 'Payroll Officer', 'all_data' => true, 'is_system' => false],
        ['id' => 'role-supervisor', 'name' => 'Supervisor', 'all_data' => true, 'is_system' => false],
        ['id' => 'role-employee', 'name' => 'Employee', 'all_data' => false, 'is_system' => false],
    ];

    public function run(): void
    {
        $roleIds = [];

        foreach (self::ROLES as $role) {
            $row = $this->upsert(Role::class, ['name' => $role['name']], [
                'id' => $role['id'],
                'all_data' => $role['all_data'],
                'is_system' => $role['is_system'],
                'created_at' => $this->at(300, 9, 0),
            ]);

            $roleIds[$role['id']] = (string) $row->getKey();
        }

        foreach ($this->grants() as $seedRoleId => $modules) {
            foreach ($modules as $i => $module) {
                $this->upsert(RoleModuleGrant::class, [
                    'role_id' => $roleIds[$seedRoleId],
                    'module' => $module,
                ], [
                    'id' => "grant-{$seedRoleId}-{$i}",
                ]);
            }
        }
    }

    /** @return array<string, list<string>> seed role id => modules, seed.ts GRANTS order */
    private function grants(): array
    {
        return [
            // Every module except the employee-only TASKS.
            'role-admin' => array_values(array_diff(ModuleCode::all(), [ModuleCode::TASKS])),
            'role-hr' => [
                ModuleCode::EDTR,
                ModuleCode::ROSTER,
                ModuleCode::LEAVES,
                ModuleCode::EMPLOYEES,
                ModuleCode::RECORDS,
                ModuleCode::AUDIT,
                ModuleCode::PORTAL,
                ModuleCode::PROFILE,
            ],
            'role-payroll' => [
                ModuleCode::PAYROLL,
                ModuleCode::EDTR,
                ModuleCode::PORTAL,
                ModuleCode::PROFILE,
            ],
            'role-supervisor' => [
                ModuleCode::EDTR,
                ModuleCode::ROSTER,
                ModuleCode::LEAVES,
                ModuleCode::PROJECTS,
                ModuleCode::PORTAL,
                ModuleCode::PROFILE,
            ],
            'role-employee' => [
                ModuleCode::PORTAL,
                ModuleCode::TASKS,
                ModuleCode::PROFILE,
            ],
        ];
    }
}
