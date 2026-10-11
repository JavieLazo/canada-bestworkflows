<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\User;
use App\Support\Access;

/** Employee detail needs a count of linked accounts, not account credentials. */
final class AccountLinks
{
    public function count(Access $actor, string $employeeId): int
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)
            || ($actor->hasEmployeeLink() && $actor->ownEmployeeId() === $employeeId), 403);

        return User::query()->where('employee_id', $employeeId)->count();
    }
}
