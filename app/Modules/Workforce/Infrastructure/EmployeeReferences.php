<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Models\EmployeeDocument;
use App\Models\EmployeeRecordChangeRequest;
use App\Platform\Retention\EmployeeReferenceSource;
use Illuminate\Support\Facades\Schema;

/** Preserves employee history owned by this module before deletion is considered. */
final class EmployeeReferences implements EmployeeReferenceSource
{
    public function references(string $employeeId): bool
    {
        return EmployeeDocument::query()->where('employee_id', $employeeId)->exists()
            || (Schema::hasTable('employee_record_change_requests') && EmployeeRecordChangeRequest::query()->where('employee_id', $employeeId)->exists());
    }
}
