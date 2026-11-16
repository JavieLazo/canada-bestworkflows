<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

use App\Support\Access;
use Illuminate\Support\Collection;

/** Account validity for assigning project leadership, without leaking credentials. */
interface ProjectAccounts
{
    /** @param list<int>|null $ids
     * @return Collection<int, AccountLabel>
     */
    public function participants(Access $actor, ?array $ids = null): Collection;

    public function active(Access $actor, string $id): bool;

    /**
     * Whether the account may be NAMED a project's Task Leader: active, and in
     * a role holding PROJECTS. A leader acts on their project from Project
     * Management (WorkManagement's WorkRules::runsProject()), and an account
     * without that module could never open it — so it is not offered.
     */
    public function canLead(Access $actor, string $id): bool;

    /**
     * Every account canLead() accepts, for the Task Leader picker.
     *
     * @return list<int>
     */
    public function leaderIds(Access $actor): array;
}
