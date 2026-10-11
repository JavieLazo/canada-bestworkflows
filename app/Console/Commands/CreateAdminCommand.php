<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\IdentityAccess\Application\TemporaryPassword;
use App\Support\FirstAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Alternative to the browser setup; refuses to create any subsequent account. */
final class CreateAdminCommand extends Command
{
    protected $signature = 'bestworkflows:create-admin {email}';

    protected $aliases = ['ems:create-admin'];

    protected $description = 'Create the first System Admin account with a generated one-time password';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Usage: bestworkflows:create-admin valid@email');

            return self::FAILURE;
        }

        // Never accept credentials as command arguments or expose them in
        // command listings. The generated credential is printed only once.
        $password = TemporaryPassword::generate();
        if (FirstAdmin::provision($email, $password) === null) {
            $this->error('Refused: accounts already exist. Use User Management instead.');

            return self::FAILURE;
        }

        $this->info("Admin created: {$email}");
        $this->warn("One-time password: {$password}");
        $this->line('Change it immediately after first login.');

        return self::SUCCESS;
    }
}
