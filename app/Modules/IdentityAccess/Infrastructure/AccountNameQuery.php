<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountLabels;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Support\Access;
use Illuminate\Support\Collection;

/** Keeps cross-module attribution restricted to non-credential account columns. */
final class AccountNameQuery implements AccountNames
{
    /** @param list<int>|null $ids
     * @return Collection<int, AccountLabel>
     */
    public function list(Access $actor, ?array $ids = null): Collection
    {
        abort_if($actor->userId === null, 403);
        $query = User::query();
        if ($ids !== null) {
            $query->whereKey($ids);
        }

        $accounts = $query->get(['id', 'employee_id', 'is_active']);
        $names = app(AccountLabels::class)->names($accounts);

        return $accounts
            ->map(static fn (User $row): AccountLabel => new AccountLabel($row->id, $names[$row->id], $row->employee_id, $row->is_active))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
