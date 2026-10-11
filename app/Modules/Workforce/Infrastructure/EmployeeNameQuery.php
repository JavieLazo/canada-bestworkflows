<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\EmployeeNames;
use App\Support\EmployeeName;

/** Account attribution reads only official names, including inactive linked employees. */
final class EmployeeNameQuery implements EmployeeNames
{
    public function labels(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return EmployeeProfile::query()->whereKey(array_values(array_unique($employeeIds)))
            ->get(['id', 'first_name', 'last_name', 'suffix'])
            ->mapWithKeys(static fn (EmployeeProfile $employee): array => [$employee->id => EmployeeName::display($employee)])
            ->all();
    }
}
