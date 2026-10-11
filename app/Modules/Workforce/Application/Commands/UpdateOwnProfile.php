<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Support\Access;
use RuntimeException;

/** Prevents older self-profile callers from bypassing the employee record review workflow. */
final class UpdateOwnProfile
{
    /** @param array<string, mixed> $draft */
    public function personal(Access $access, array $draft): null
    {
        if (! $access->isSignedIn() || ! $access->hasEmployeeLink()) {
            return null;
        }
        throw new RuntimeException('Submit a record change request for approval.');
    }

    /** @param array<string, string> $draft */
    public function emergency(Access $access, array $draft): null
    {
        if (! $access->isSignedIn() || ! $access->hasEmployeeLink()) {
            return null;
        }
        throw new RuntimeException('Submit a record change request for approval.');
    }
}
