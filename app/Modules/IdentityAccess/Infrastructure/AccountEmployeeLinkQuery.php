<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use App\Models\User;
use App\Platform\Records\AccountEmployeeLinks;
use Illuminate\Support\Facades\DB;

/** Identity owns current account links and active status for employee-record writes. */
final class AccountEmployeeLinkQuery implements AccountEmployeeLinks
{
    public function lockCurrentLinks(array $accountIds): array
    {
        $ids = array_values(array_unique($accountIds));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return [];
        }
        // A SHARED lock — it holds the link and the active status still until
        // commit, and stays compatible with the foreign-key read another write
        // by the same account takes on this row. An exclusive lock here
        // deadlocks against a transaction that holds an owner sentinel and then
        // inserts a row naming the account (an inventory receipt's recorder).
        $accounts = User::query()->whereKey($ids)->orderBy('id')
            ->when(DB::transactionLevel() > 0, static fn ($query) => $query->sharedLock())
            ->get(['id', 'employee_id', 'is_active']);
        $links = [];
        foreach ($accounts as $account) {
            if ($account->is_active) {
                $links[$account->id] = $account->employee_id;
            }
        }

        return $links;
    }

    public function isActiveLinked(int $accountId, string $employeeId): bool
    {
        $links = $this->lockCurrentLinks([$accountId]);

        return ($links[$accountId] ?? null) === $employeeId;
    }

    public function isActiveIndependent(int $accountId, string $employeeId): bool
    {
        $links = $this->lockCurrentLinks([$accountId]);

        return array_key_exists($accountId, $links) && $links[$accountId] !== $employeeId;
    }

    public function isActive(int $accountId): bool
    {
        return array_key_exists($accountId, $this->lockCurrentLinks([$accountId]));
    }
}
