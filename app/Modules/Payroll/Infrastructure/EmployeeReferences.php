<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Models\PayrollEmployeeBenefit;
use App\Models\PayrollGovDeductionPolicy;
use App\Models\PayrollRecurringLineEntry;
use App\Models\PayrollRunMember;
use App\Models\PayrollTaxBalance;
use App\Models\Payslip;
use App\Modules\Payroll\Application\BenefitAssignments;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\RecurringLineEntries;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Platform\Retention\EmployeeReferenceSource;

/** Preserves employee history owned by this module — payslips, draft membership, approved pay versions — before deletion is considered. */
final class EmployeeReferences implements EmployeeReferenceSource
{
    public function references(string $employeeId): bool
    {
        return Payslip::query()->where('employee_id', $employeeId)->exists()
            || PayrollRunMember::query()->where('employee_id', $employeeId)->exists()
            || (GovDeductionPolicyStore::ready() && PayrollGovDeductionPolicy::query()->where('employee_id', $employeeId)->exists())
            || (BenefitAssignments::ready() && PayrollEmployeeBenefit::query()->where('employee_id', $employeeId)->exists())
            || (TaxYearToDate::balancesReady() && PayrollTaxBalance::query()->where('employee_id', $employeeId)->exists())
            || (RecurringLineEntries::ready() && PayrollRecurringLineEntry::query()->where('employee_id', $employeeId)->exists());
    }
}
