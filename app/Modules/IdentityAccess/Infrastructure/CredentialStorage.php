<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Credential SQL bindings contain hashes and must never reach an exception log or browser. */
final class CredentialStorage
{
    public static function failure(#[\SensitiveParameter] QueryException $error, string $message): RuntimeException
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        Log::error('[credentials] Database write failed', [
            'sqlstate' => preg_match('/^[A-Z0-9]{5}$/', $state) === 1 ? $state : null,
            'driver_code' => is_numeric($error->errorInfo[1] ?? null) ? (int) $error->errorInfo[1] : null,
        ]);

        return new RuntimeException($message);
    }
}
