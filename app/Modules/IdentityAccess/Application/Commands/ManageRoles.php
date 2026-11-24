<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Protects the caller's recovery access while managing roles and grants. */
final class ManageRoles
{
    public function save(Access $actor, ?string $id, string $name, bool $allData): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $id, $name, $allData): void {
            $name = trim($name);
            if ($name === '') {
                throw new RuntimeException("Role name can't be empty.");
            }
            if (mb_strlen($name) > 80) {
                throw new RuntimeException('Role name must be 80 characters or fewer.');
            }
            if ($id === null) {
                $role = Role::query()->create(['name' => $name, 'all_data' => $allData, 'is_system' => false]);
                RequiredAudit::record($actor, AuditEvent::ROLE_CREATED, 'roles', $role->getKey(), "{$role->name} role created");
            } else {
                $role = Role::query()->whereKey($id)->lockForUpdate()->first();
                if ($role === null) {
                    throw new RuntimeException('That role no longer exists.');
                }
                if ($actor->roleId === (string) $role->getKey() && ! $allData) {
                    throw new RuntimeException('You cannot remove organization-wide scope from your own role.');
                }
                $role->name = $name;
                $role->all_data = $allData;
                $role->save();
                RequiredAudit::record($actor, AuditEvent::ROLE_UPDATED, 'roles', $role->getKey(), "{$role->name} role updated");
            }
        });
    }

    public function toggleGrant(Access $actor, string $roleId, string $module, ?bool $granted = null): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $roleId, $module, $granted): void {
            if (! in_array($module, ModuleCode::all(), true)) {
                throw new RuntimeException('That module does not exist.');
            }
            // A module turned off for the installation keeps the grants it
            // had, so turning it back on restores them exactly; nothing
            // gives or removes it meanwhile.
            if (app(ModuleRegistry::class)->isOff($module)) {
                throw new RuntimeException(app(ModuleRegistry::class)->moduleLabel($module).' is turned off for this installation, so its access cannot be changed.');
            }
            $role = Role::query()->whereKey($roleId)->lockForUpdate()->first();
            if ($role === null) {
                return;
            }
            $exists = RoleModuleGrant::query()->where('role_id', $role->getKey())->where('module', $module)->exists();
            // The screen's confirm sends the intended state; null toggles the stored state for a direct caller that has none.
            $on = $granted ?? ! $exists;
            if ($on === $exists) {
                return;
            }
            if (! $on) {
                if ($module === ModuleCode::USERS && $actor->roleId === (string) $role->getKey()) {
                    throw new RuntimeException('You cannot remove User Management from your own role.');
                }
                RoleModuleGrant::query()->where('role_id', $role->getKey())->where('module', $module)->delete();
            } else {
                RoleModuleGrant::query()->firstOrCreate(['role_id' => (string) $role->getKey(), 'module' => $module]);
            }
            RequiredAudit::record($actor, AuditEvent::grant($on), 'role_module_grants', $role->getKey(), "{$role->name}: {$module} ".($on ? 'granted' : 'revoked'));
        });
    }

    private function authorize(Access $actor): void
    {
        if (! $actor->mayReadAllData(ModuleCode::USERS)) {
            throw new RuntimeException($actor->hasModule(ModuleCode::USERS)
                ? 'Your role cannot manage organization-wide data.' : 'You do not have access to that.');
        }
    }
}
