<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\User;
use App\Support\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bootstrap the FIRST admin account on a production database.
 *
 * The port has no web first-run setup screen (the demo seeder covers dev), so
 * production bootstraps through this command - typically via the cPanel
 * cron-once trick when there is no SSH:
 *
 *   artisan bestworkflows:create-admin admin@example.com "Full Name"
 *
 * The password is GENERATED and printed once (cron mails stdout to the Cron
 * Email address); it is never accepted as an argument, so it cannot land in
 * cron job listings or shell history. Change it after first login.
 *
 * Self-healing per the port plan 7.6: the System Admin role is created (with
 * every module except TASKS, all_data) if the seeder has not run yet, so a
 * bootstrap against a bare-but-migrated schema cannot produce a roleless
 * admin. Refuses to run when any account already exists.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'bestworkflows:create-admin {email} {name}';

    protected $description = 'Create the first System Admin account with a generated one-time password';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $this->error('Usage: bestworkflows:create-admin valid@email "Full Name"');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($email, $name): int {
            // The emptiness recount inside the transaction is the authorization.
            if (User::query()->lockForUpdate()->exists()) {
                $this->error('Refused: accounts already exist. Use User Management instead.');

                return self::FAILURE;
            }

            $role = Role::query()->firstOrCreate(
                ['name' => 'System Admin'],
                ['all_data' => true, 'is_system' => true],
            );

            if ($role->grants()->doesntExist()) {
                foreach (ModuleCode::all() as $module) {
                    if ($module === ModuleCode::TASKS) {
                        continue; // employee-facing module, per the role matrix
                    }
                    $role->grants()->create(['module' => $module]);
                }
            }

            $password = Str::password(16);

            $user = new User;
            $user->fill(['name' => $name, 'email' => $email]);
            $user->password = Hash::make($password);
            $user->forceFill([
                'role_id' => $role->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();

            AuditService::anonymous($name, AuditEvent::BOOTSTRAP_ADMIN, 'users', (string) $user->id);

            $this->info("Admin created: {$email}");
            $this->warn("One-time password: {$password}");
            $this->line('Change it immediately after first login.');

            return self::SUCCESS;
        });
    }
}
