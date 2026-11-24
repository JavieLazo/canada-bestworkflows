<?php

declare(strict_types=1);

namespace App\Livewire\Users\Support;

use App\Modules\IdentityAccess\Application\Queries\GetAccounts;
use App\Modules\Workforce\Application\Queries\AccountEmployees;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Generator;

/**
 * User Management's Accounts tab as CSV, in the table's order (by name):
 * name, email, role, linked employee and whether the account is active.
 * The same USERS read as the tab ({@see GetAccounts}), and never anything
 * the tab does not show — no password, token or 2FA material. Export only:
 * accounts are provisioned one at a time with a role and a password.
 */
final class AccountsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);

        $rows = static function () use ($actor): Generator {
            $listing = app(GetAccounts::class)->handle($actor);
            $roles = [];
            foreach ($listing['roles'] as $role) {
                $roles[(string) $role->getKey()] = $role->name;
            }
            $employees = app(AccountEmployees::class)->list($actor)->keyBy(static fn ($employee): string => (string) $employee->getKey());
            foreach ($listing['accounts'] as $account) {
                $employee = $account->employee_id === null ? null : $employees->get((string) $account->employee_id);
                yield [
                    $account->name, $account->email,
                    $account->role_id !== null ? ($roles[(string) $account->role_id] ?? 'Unassigned') : 'Unassigned',
                    $employee === null ? '' : EmployeeName::display($employee),
                    $account->is_active ? 'Active' : 'Inactive',
                ];
            }
        };

        return new CsvExport('accounts-'.LocalClock::todayKey().'.csv', ['Name', 'Email', 'Role', 'Linked employee', 'Active'], $rows);
    }
}
