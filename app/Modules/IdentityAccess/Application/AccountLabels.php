<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\Workforce\Contracts\EmployeeNames;
use Illuminate\Support\Collection;

/**
 * Batch official employee names so account lists never issue a query per
 * account. An account with no employee record reads the name typed for it,
 * fetched in one query for the accounts loaded without that column.
 */
final class AccountLabels
{
    public function __construct(private readonly EmployeeNames $employees) {}

    /**
     * @param  Collection<int, User>  $accounts
     * @return array<int, string>
     */
    public function names(Collection $accounts): array
    {
        $ids = [];
        foreach ($accounts as $account) {
            if ($account->employee_id !== null) {
                $ids[] = $account->employee_id;
            }
        }
        $names = $this->employees->labels($ids);
        $typed = $this->typed($accounts);

        return $accounts->mapWithKeys(static fn (User $account): array => [
            $account->id => $names[$account->employee_id ?? ''] ?? AccountLabel::unlinked($account->id, $typed[$account->id] ?? null),
        ])->all();
    }

    /**
     * The typed names of the accounts with no employee record, keyed by
     * account id.
     *
     * @param  Collection<int, User>  $accounts
     * @return array<int, string|null>
     */
    private function typed(Collection $accounts): array
    {
        $typed = [];
        $missing = [];

        foreach ($accounts as $account) {
            if ($account->employee_id !== null) {
                continue;
            }

            $attributes = $account->getAttributes();

            if (array_key_exists('account_name', $attributes)) {
                $typed[$account->id] = is_string($attributes['account_name']) ? $attributes['account_name'] : null;
            } else {
                $missing[] = $account->id;
            }
        }

        if ($missing !== [] && User::accountNameReady()) {
            foreach (User::query()->whereKey($missing)->pluck('account_name', 'id') as $id => $name) {
                $typed[(int) $id] = is_string($name) ? $name : null;
            }
        }

        return $typed;
    }
}
