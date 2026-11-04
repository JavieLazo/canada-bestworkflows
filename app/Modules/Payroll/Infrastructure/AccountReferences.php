<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Models\PayrollBenefitCeiling;
use App\Models\PayrollEmployeeBenefit;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollGovDeductionPolicy;
use App\Models\PayrollPayRule;
use App\Models\PayrollPaySchedule;
use App\Models\PayrollRecurringLineEntry;
use App\Models\PayrollRun;
use App\Models\PayrollSignatory;
use App\Models\PayrollStatutoryTable;
use App\Models\PayrollTaxBalance;
use App\Modules\Payroll\Application\BenefitAssignments;
use App\Modules\Payroll\Application\BenefitCeilings;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Application\Queries\SignatoryRecords;
use App\Modules\Payroll\Application\RecurringLineEntries;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Platform\Retention\AccountReferenceSource;

/** Keeps account attribution checks beside the records this owner persists. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        return PayrollRun::query()->where('finalized_by', $accountId)->when(PaySchedules::ready(), static fn ($q) => $q->orWhere('released_by', $accountId))->exists()
            || (PaySchedules::ready() && PayrollPaySchedule::query()->where('created_by', $accountId)->exists())
            || PayrollFinalizationSnapshot::query()->where('finalized_by', $accountId)->exists()
            || (StatutoryTables::ready() && PayrollStatutoryTable::query()->where('created_by', $accountId)->exists())
            || (SignatoryRecords::ready() && PayrollSignatory::query()->where('updated_by', $accountId)->exists())
            || (BenefitCeilings::ready() && PayrollBenefitCeiling::query()->where('created_by', $accountId)->exists())
            || (BenefitAssignments::ready() && PayrollEmployeeBenefit::query()->where(static fn ($q) => $q->where('prepared_by', $accountId)->orWhere('approved_by', $accountId))->exists())
            || (TaxYearToDate::balancesReady() && PayrollTaxBalance::query()->where(static fn ($q) => $q->where('prepared_by', $accountId)->orWhere('approved_by', $accountId))->exists())
            || (RecurringLineEntries::ready() && PayrollRecurringLineEntry::query()->where(static fn ($q) => $q->where('prepared_by', $accountId)->orWhere('approved_by', $accountId))->exists())
            || (PayRulesStore::ready() && PayrollPayRule::query()->where('created_by', $accountId)->when(PayRulesStore::approvalReady(), static fn ($q) => $q->orWhere('approved_by', $accountId))->exists())
            || (GovDeductionPolicyStore::ready() && PayrollGovDeductionPolicy::query()->where(static fn ($q) => $q->where('prepared_by', $accountId)->orWhere('approved_by', $accountId))->exists());
    }
}
