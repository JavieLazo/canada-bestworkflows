<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\User;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Application\Queries\FirstAdminAvailability;
use App\Modules\IdentityAccess\Infrastructure\CredentialStorage;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** First-run provisioning shared by the browser setup and console command. */
final class BootstrapFirstAdmin
{
    public function handle(Access $actor, string $email, #[\SensitiveParameter] string $password): ?int
    {
        if ($actor->isSignedIn() || ! app(FirstAdminAvailability::class)->needed()) {
            return null;
        }

        $email = Str::lower(trim($email));
        Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:200']])->validate();
        // Production may check compromised passwords remotely. Do that before
        // taking the database mutex, then recheck authorization under the lock.
        PasswordPolicy::validate($password);

        return DB::transaction(static function () use ($email, $password): ?int {
            // An existing sentinel serializes two empty-install attempts; a
            // locking query against an empty users table alone is insufficient.
            TransactionMutex::acquire('copems:setup');
            if (User::query()->lockForUpdate()->exists()) {
                return null;
            }

            $role = Role::query()->firstOrCreate(
                ['name' => 'System Admin'],
                ['all_data' => true, 'is_system' => true],
            );
            if ($role->grants()->doesntExist()) {
                foreach (ModuleCode::all() as $module) {
                    if ($module !== ModuleCode::TASKS) {
                        $role->grants()->create(['module' => $module]);
                    }
                }
            }

            $user = new User;
            $user->email = $email;
            $user->password = $password;
            $user->forceFill([
                'role_id' => $role->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            try {
                $user->save();
            } catch (QueryException $error) {
                throw CredentialStorage::failure($error, 'The administrator account could not be created.');
            }

            AuditService::anonymous($user->name, AuditEvent::BOOTSTRAP_ADMIN, 'users', (string) $user->id);

            return (int) $user->getKey();
        });
    }
}
