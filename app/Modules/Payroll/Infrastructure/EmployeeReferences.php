<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Platform\Retention\EmployeeReferenceSource;

/** Preserves employee history owned by this module before deletion is considered. */
final class EmployeeReferences implements EmployeeReferenceSource
{
    public function references(string $employeeId): bool
    {
        return Payslip::query()->where('employee_id', $employeeId)->exists()
            || PayrollRunMember::query()->where('employee_id', $employeeId)->exists();
    }
}
