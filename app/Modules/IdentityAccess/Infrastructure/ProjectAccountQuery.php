<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountLabels;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Identity owns the account checks project management needs when it names a
 * Task Leader: whether an account is active, and whether its role holds
 * PROJECTS. Only a Projects manager names leaders, so only one may ask.
 */
final class ProjectAccountQuery implements ProjectAccounts
{
    public function participants(Access $actor, ?array $ids = null): Collection
    {
        abort_unless($ids === null ? $actor->hasModule(ModuleCode::PROJECTS) : $actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS), 403);
        $query = User::query()->where('is_active', true)
            ->whereHas('role.grants', static fn (Builder $grant) => $grant->whereIn('module', [ModuleCode::PROJECTS, ModuleCode::TASKS]));
        if ($ids !== null) {
            $query->whereKey($ids);
        }
        $accounts = $query->get(['id', 'employee_id', 'is_active']);
        $names = app(AccountLabels::class)->names($accounts);

        return $accounts->map(static fn (User $account): AccountLabel => new AccountLabel($account->id, $names[$account->id], $account->employee_id, $account->is_active))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    public function active(Access $actor, string $id): bool
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PROJECTS), 403);

        return User::query()->whereKey($id)->where('is_active', true)->exists();
    }

    public function canLead(Access $actor, string $id): bool
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PROJECTS), 403);

        return $this->leaders()->whereKey($id)->exists();
    }

    public function leaderIds(Access $actor): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PROJECTS), 403);

        return array_values($this->leaders()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    /**
     * Active accounts whose role holds PROJECTS.
     *
     * @return Builder<User>
     */
    private function leaders(): Builder
    {
        return User::query()->where('is_active', true)
            ->whereHas('role.grants', static fn (Builder $grant) => $grant->where('module', ModuleCode::PROJECTS));
    }
}
