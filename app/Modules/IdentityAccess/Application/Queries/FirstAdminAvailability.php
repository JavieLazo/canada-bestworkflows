<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Queries;

use App\Models\User;

final class FirstAdminAvailability
{
    public function needed(): bool
    {
        return User::query()->doesntExist();
    }
}
