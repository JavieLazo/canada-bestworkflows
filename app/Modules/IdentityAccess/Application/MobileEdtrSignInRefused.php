<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use RuntimeException;

/**
 * A refused kiosk sign-in. The device reads the HTTP status as well as the
 * message, so the refusal carries both.
 */
final class MobileEdtrSignInRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
