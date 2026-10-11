<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\Workforce\Contracts\EmployeeNames;
use Illuminate\Support\Collection;

/** Batch official employee names so account lists never issue a query per account. */
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

        return $accounts->mapWithKeys(static fn (User $account): array => [
            $account->id => $names[$account->employee_id ?? ''] ?? AccountLabel::unlinked($account->id),
        ])->all();
    }
}
