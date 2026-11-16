<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * Who else can use a module, for the directories inside that module (Chat's
 * New chat list, Community's @mentions). Never a way to read another role's
 * grants: the actor must hold the module being asked about.
 */
interface ModuleAccounts
{
    /**
     * Active accounts whose role holds the module, the actor included, by name.
     *
     * @return Collection<int, AccountLabel>
     */
    public function holders(Access $actor, string $module): Collection;

    /**
     * The ids, of those given, that belong to active accounts holding the module.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function filter(Access $actor, string $module, array $ids, bool $lock = false): array;
}
