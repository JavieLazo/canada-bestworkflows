<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Platform\Retention\AccountReferenceSource;

/** Aggregates owner-supplied retention facts without importing their models. */
final class AccountRetention
{
    /** @param iterable<AccountReferenceSource> $sources */
    public function __construct(private readonly iterable $sources) {}

    public function any(int|string $accountId): bool
    {
        foreach ($this->sources as $source) {
            if ($source->references($accountId)) {
                return true;
            }
        }

        return false;
    }
}
