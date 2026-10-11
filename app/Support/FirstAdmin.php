<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\IdentityAccess\Application\Commands\BootstrapFirstAdmin;
use App\Modules\IdentityAccess\Application\Queries\FirstAdminAvailability;

/** Compatibility entry point for the browser and console first-run setup. */
final class FirstAdmin
{
    public static function needed(): bool
    {
        return app(FirstAdminAvailability::class)->needed();
    }

    /** The owning module returns an account ID, never a writable account model. */
    public static function provision(string $email, #[\SensitiveParameter] string $plainPassword): ?int
    {
        return app(BootstrapFirstAdmin::class)->handle(app(Access::class), $email, $plainPassword);
    }
}
