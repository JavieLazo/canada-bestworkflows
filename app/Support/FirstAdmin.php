<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Standing up the very first account.
 *
 * A fresh install has roles but no users (ProductionSeeder seeds configuration,
 * never accounts), so someone has to become the first System Administrator
 * before User Management — which is gated on holding an account already — can
 * hand out any others. Two front doors reach the same logic here, so they
 * cannot drift: the `bestworkflows:create-admin` console command, and the
 * browser registration screen that is open ONLY while this returns needed().
 *
 * The account is provisioned, not merely created: it is given the System Admin
 * role (every module the matrix grants that role, org-wide `all_data`), marked
 * active and email-verified, so the person who sets the system up can actually
 * run it. A self-registered account with no role — Fortify's stock behaviour —
 * would land on a shell with nothing on it.
 */
final class FirstAdmin
{
    /** Whether the system still has no account, so the bootstrap is open. */
    public static function needed(): bool
    {
        return User::query()->doesntExist();
    }

    /**
     * Create the first account as a System Administrator, or return null if one
     * already exists.
     *
     * The emptiness check is a locking recount INSIDE the transaction, not the
     * `needed()` above: that is what authorises the write, so two people
     * hitting the bootstrap at once cannot both win and cannot produce a second
     * account. The caller's earlier `needed()` is only a courtesy that avoids
     * rendering a form nobody can submit.
     */
    public static function provision(string $name, string $email, string $plainPassword): ?User
    {
        return DB::transaction(static function () use ($name, $email, $plainPassword): ?User {
            if (User::query()->lockForUpdate()->exists()) {
                return null;
            }

            $role = Role::query()->firstOrCreate(
                ['name' => 'System Admin'],
                ['all_data' => true, 'is_system' => true],
            );

            // On a seeded install the role already carries its grants; this
            // fills them in only for the bare-database case, and matches the
            // role matrix — every module except TASKS, which is the employee's
            // own queue rather than an administrative surface.
            if ($role->grants()->doesntExist()) {
                foreach (ModuleCode::all() as $module) {
                    if ($module === ModuleCode::TASKS) {
                        continue;
                    }

                    $role->grants()->create(['module' => $module]);
                }
            }

            $user = new User;
            $user->fill(['name' => $name, 'email' => $email]);
            // The 'hashed' cast bcrypts on assignment and is idempotent, so a
            // plain value is correct here.
            $user->password = $plainPassword;
            $user->forceFill([
                'role_id' => $role->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();

            AuditService::anonymous($name, AuditEvent::BOOTSTRAP_ADMIN, 'users', (string) $user->id);

            return $user;
        });
    }
}
