<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use App\Models\User;
use App\Platform\Retention\EmployeeReferenceSource;

/** Preserves employee history owned by this module before deletion is considered. */
final class EmployeeReferences implements EmployeeReferenceSource
{
    public function references(string $employeeId): bool
    {
        return User::query()->where('employee_id', $employeeId)->exists();
    }
}
