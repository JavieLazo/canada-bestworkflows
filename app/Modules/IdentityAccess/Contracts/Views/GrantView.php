<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts\Views;

final readonly class GrantView
{
    public function __construct(public string $role_id, public string $module) {}
}
