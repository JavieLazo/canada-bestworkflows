<?php

declare(strict_types=1);

namespace App\Platform\Retention;

/** Internal retention facts, supplied by the module that owns each reference. */
interface AccountReferenceSource
{
    public function references(int|string $accountId): bool;
}
