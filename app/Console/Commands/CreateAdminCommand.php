<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\FirstAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Bootstrap the FIRST admin account from the command line.
 *
 * The same bootstrap the browser registration screen runs — both go through
 * {@see FirstAdmin::provision()} — for anyone who would rather not open a
 * one-time public page, or is scripting the install. Typically the cPanel
 * cron-once trick when there is no SSH:
 *
 *   artisan bestworkflows:create-admin admin@example.com "Full Name"
 *
 * The password is GENERATED and printed once (cron mails stdout to the Cron
 * Email address); it is never accepted as an argument, so it cannot land in
 * cron job listings or shell history. Change it after first login.
 *
 * The System Admin role and its grants are created if the seeder has not run
 * yet, so a bootstrap against a bare-but-migrated schema cannot produce a
 * roleless admin. Refuses to run when any account already exists.
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

        // Shared with the browser bootstrap so the two cannot diverge; the
        // command's part is a generated one-time password and console output.
        $password = Str::password(16);

        $user = FirstAdmin::provision($name, $email, $password);

        if ($user === null) {
            $this->error('Refused: accounts already exist. Use User Management instead.');

            return self::FAILURE;
        }

        $this->info("Admin created: {$email}");
        $this->warn("One-time password: {$password}");
        $this->line('Change it immediately after first login.');

        return self::SUCCESS;
    }
}
