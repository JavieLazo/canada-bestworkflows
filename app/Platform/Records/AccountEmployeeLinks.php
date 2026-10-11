<?php

declare(strict_types=1);

namespace App\Platform\Records;

/** Employee-record decisions recheck the requester's current active account link. */
interface AccountEmployeeLinks
{
    /**
     * Lock the account rows in ID order inside a transaction, before other owner
     * locks. Only active accounts appear; an unlinked active account maps to null.
     *
     * @param  list<int>  $accountIds
     * @return array<int, string|null>
     */
    public function lockCurrentLinks(array $accountIds): array;

    /** Inside a transaction, lock the account before the caller locks its employee. */
    public function isActiveLinked(int $accountId, string $employeeId): bool;

    /** Lock the account and recheck its active status before the caller takes owner locks. */
    public function isActive(int $accountId): bool;

    /** The reviewer must still be active and linked to a different employee, or unlinked. */
    public function isActiveIndependent(int $accountId, string $employeeId): bool;
}
