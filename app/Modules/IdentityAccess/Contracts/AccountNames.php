<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

use App\Support\Access;
use Illuminate\Support\Collection;

/** Attribution labels for authorized records, not an account-administration API. */
interface AccountNames
{
    /** @param list<int>|null $ids
     * @return Collection<int, AccountLabel>
     */
    public function list(Access $actor, ?array $ids = null): Collection;
}
