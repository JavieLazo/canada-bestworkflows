<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountLabels;
use App\Modules\IdentityAccess\Contracts\Views\GrantView;
use App\Modules\IdentityAccess\Contracts\Views\RoleView;
use App\Modules\IdentityAccess\Contracts\Views\UserView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/** Reauthorizes account reads on every render, including direct component refreshes. */
final class GetAccounts
{
    /** @return array{accounts: Collection<int, UserView>, roles: Collection<int, RoleView>, grants: Collection<int, GrantView>} */
    public function handle(Access $actor): array
    {
        if (! $actor->hasModule(ModuleCode::USERS)) {
            return ['accounts' => new Collection, 'roles' => new Collection, 'grants' => new Collection];
        }

        $accounts = User::query()->get(self::columns());
        $names = app(AccountLabels::class)->names($accounts);

        return [
            'accounts' => $accounts->map(fn (User $row): UserView => $this->view($row, $names[$row->id]))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'roles' => Role::query()->orderBy('name')->get()->map(static fn (Role $row): RoleView => Snapshot::of($row, RoleView::class)),
            'grants' => RoleModuleGrant::query()->get(['role_id', 'module'])
                ->map(static fn (RoleModuleGrant $row): GrantView => new GrantView($row->role_id, $row->module)),
        ];
    }

    public function account(Access $actor, string $id): ?UserView
    {
        if (! $actor->hasModule(ModuleCode::USERS)) {
            return null;
        }
        $row = User::query()->select(self::columns())->find($id);

        return $row === null ? null : $this->view($row, $row->name);
    }

    /** @return list<string> */
    private static function columns(): array
    {
        $columns = ['id', 'email', 'role_id', 'employee_id', 'is_active'];

        if (User::accountNameReady()) {
            $columns[] = 'account_name';
        }

        return $columns;
    }

    private function view(User $account, string $name): UserView
    {
        return new UserView([
            'id' => $account->id, 'name' => $name, 'account_name' => $account->getAttributes()['account_name'] ?? null,
            'email' => $account->email,
            'role_id' => $account->role_id, 'employee_id' => $account->employee_id,
            'is_active' => $account->is_active,
        ]);
    }

    /**
     * Whether a sign-in already uses this email, compared the way account
     * provisioning compares it when it refuses a duplicate.
     */
    public function emailTaken(Access $actor, string $email): bool
    {
        return $actor->hasModule(ModuleCode::USERS)
            && User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->exists();
    }

    public function role(Access $actor, string $id): ?RoleView
    {
        $row = $actor->hasModule(ModuleCode::USERS) ? Role::query()->find($id) : null;

        return $row === null ? null : Snapshot::of($row, RoleView::class);
    }
}
