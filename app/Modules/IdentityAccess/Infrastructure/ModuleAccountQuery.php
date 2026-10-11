<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountLabels;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Identity owns the grant join; callers only ever see non-credential account labels. */
final class ModuleAccountQuery implements ModuleAccounts
{
    public function holders(Access $actor, string $module): Collection
    {
        abort_unless($actor->hasModule($module), 403);

        $accounts = $this->holding($module)->get(['users.id', 'users.employee_id', 'users.is_active']);
        $names = app(AccountLabels::class)->names($accounts);

        return $accounts
            ->map(static fn (User $row): AccountLabel => new AccountLabel((int) $row->id, $names[$row->id], $row->employee_id, (bool) $row->is_active))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    public function filter(Access $actor, string $module, array $ids): array
    {
        abort_unless($actor->hasModule($module), 403);

        if ($ids === []) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->holding($module)->whereIn('users.id', $ids)->pluck('users.id')->all(),
        )));
    }

    /** @return Builder<User> */
    private function holding(string $module): Builder
    {
        return User::query()
            ->join('role_module_grants', 'role_module_grants.role_id', '=', 'users.role_id')
            ->where('users.is_active', true)
            ->where('role_module_grants.module', $module)
            ->distinct();
    }
}
