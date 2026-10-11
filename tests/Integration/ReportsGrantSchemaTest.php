<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The production-engine grants CHECK accepts REPORTS and every code that
 * existed with it, rejects unknown values, resumes after an interrupted
 * replacement and only loses REPORTS grants on rollback. THE LIST IS THIS
 * MIGRATION'S OWN: re-running it reinstates its literal CHECK, which names no
 * module a later migration added. Every test restores the latest schema.
 */

/** @return list<string> */
function rptSchemaModules(): array
{
    return ['EMPLOYEES', 'EDTR', 'ROSTER', 'LEAVES', 'PROJECTS', 'PAYROLL', 'REPORTS', 'RECORDS', 'USERS', 'AUDIT', 'PORTAL', 'TASKS', 'COMMUNITY', 'CHAT', 'PROFILE'];
}

function rptSchemaCheckCount(): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'role_module_grants' AND CONSTRAINT_NAME = 'rmg_module_chk' AND CONSTRAINT_TYPE = 'CHECK'",
    );

    return (int) ($row->n ?? 0);
}

function rptSchemaRole(): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Reports schema '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();

    return $role;
}

function rptSchemaGrant(Role $role, string $module): string
{
    $id = (string) Str::ulid();
    DB::table('role_module_grants')->insert([
        'id' => $id,
        'role_id' => $role->getKey(),
        'module' => $module,
    ]);

    return $id;
}

it('retries the Reports CHECK with no grants added and enforces the complete literal module list', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $role = rptSchemaRole();
    $migration = require base_path('database/migrations/2026_10_02_0007_add_reports_to_role_module_grants_table.php');
    $migration->up();
    $migration->up();

    expect(rptSchemaCheckCount())->toBe(1)
        ->and(DB::table('role_module_grants')->where('role_id', $role->getKey())->count())->toBe(0);

    foreach (rptSchemaModules() as $module) {
        rptSchemaGrant($role, $module);
    }

    expect(DB::table('role_module_grants')->where('role_id', $role->getKey())->count())->toBe(count(rptSchemaModules()));
    expect(fn () => rptSchemaGrant($role, 'UNKNOWN'))->toThrow(QueryException::class, 'rmg_module_chk');
    expect(fn () => rptSchemaGrant($role, 'INVENTORY'))->toThrow(QueryException::class, 'rmg_module_chk');
    expect(fn () => DB::table('role_module_grants')->where('role_id', $role->getKey())->where('module', 'REPORTS')->update(['module' => 'UNKNOWN']))
        ->toThrow(QueryException::class, 'rmg_module_chk');

    $migration->up();
    expect(DB::table('role_module_grants')->where('role_id', $role->getKey())->count())->toBe(count(rptSchemaModules()));
});

it('resumes the Reports CHECK replacement after its drop completed before the cron stopped', function () {
    $role = rptSchemaRole();
    $payroll = rptSchemaGrant($role, 'PAYROLL');
    $migration = require base_path('database/migrations/2026_10_02_0007_add_reports_to_role_module_grants_table.php');

    DB::statement('ALTER TABLE `role_module_grants` DROP CONSTRAINT `rmg_module_chk`');
    expect(rptSchemaCheckCount())->toBe(0);
    $migration->up();
    $migration->up();

    expect(rptSchemaCheckCount())->toBe(1)
        ->and(DB::table('role_module_grants')->where('id', $payroll)->exists())->toBeTrue();
    rptSchemaGrant($role, 'REPORTS');
    expect(fn () => rptSchemaGrant($role, 'UNKNOWN'))->toThrow(QueryException::class, 'rmg_module_chk');
});

it('restores the former CHECK on repeated Reports rollback and resumes an interrupted rollback', function () {
    $role = rptSchemaRole();
    $payroll = rptSchemaGrant($role, 'PAYROLL');
    $reports = rptSchemaGrant($role, 'REPORTS');
    $migration = require base_path('database/migrations/2026_10_02_0007_add_reports_to_role_module_grants_table.php');

    try {
        $migration->down();
        $migration->down();

        expect(rptSchemaCheckCount())->toBe(1)
            ->and(DB::table('role_module_grants')->where('id', $payroll)->exists())->toBeTrue()
            ->and(DB::table('role_module_grants')->where('id', $reports)->exists())->toBeFalse()
            ->and(Role::query()->whereKey($role->getKey())->exists())->toBeTrue();
        expect(fn () => rptSchemaGrant($role, 'REPORTS'))->toThrow(QueryException::class, 'rmg_module_chk');

        DB::statement('ALTER TABLE `role_module_grants` DROP CONSTRAINT `rmg_module_chk`');
        $migration->down();

        expect(rptSchemaCheckCount())->toBe(1);
        expect(fn () => rptSchemaGrant($role, 'REPORTS'))->toThrow(QueryException::class, 'rmg_module_chk');
    } finally {
        $migration->up();
    }

    rptSchemaGrant($role, 'REPORTS');
    expect(DB::table('role_module_grants')->where('role_id', $role->getKey())->count())->toBe(2);
});
