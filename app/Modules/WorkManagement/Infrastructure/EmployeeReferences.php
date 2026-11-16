<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Models\ProjectAvailability;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskEffort;
use App\Platform\Retention\EmployeeReferenceSource;

/** Preserves employee history owned by this module before deletion is considered. */
final class EmployeeReferences implements EmployeeReferenceSource
{
    public function references(string $employeeId): bool
    {
        return ProjectTaskAssignee::query()->where('employee_id', $employeeId)->exists()
            || ProjectAvailability::query()->where('employee_id', $employeeId)->exists()
            || ProjectTaskEffort::query()->where('employee_id', $employeeId)->exists();
    }
}
