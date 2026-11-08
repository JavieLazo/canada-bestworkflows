<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;

/**
 * The kiosk secret a request was authenticated with, and its account as read
 * for that request. It lives only as long as the request does.
 */
final readonly class MobileEdtrDevice
{
    public function __construct(public string $tokenId, public User $user) {}
}
