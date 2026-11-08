<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;

/**
 * An account whose kiosk sign-in passed its password and second factor, before
 * anything is issued to the device: the caller still decides whether it may
 * use the kiosk. `role` is empty for an account with no role.
 */
final readonly class MobileEdtrAccount
{
    public function __construct(
        public User $user,
        public string $name,
        public string $email,
        public string $role,
        public bool $systemAdmin,
    ) {}
}
