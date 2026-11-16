<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Models\Project;
use App\Models\ProjectAvailability;
use App\Models\ProjectBudget;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Models\ProjectClient;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectFunding;
use App\Models\ProjectFundingInstallment;
use App\Models\ProjectIndicator;
use App\Models\ProjectOutcome;
use App\Models\ProjectPortfolio;
use App\Models\ProjectRecordFile;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReport;
use App\Models\ProjectReportField;
use App\Models\ProjectReportingPeriod;
use App\Models\ProjectReportSubmission;
use App\Models\ProjectReportTemplate;
use App\Models\ProjectResultEntry;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskEffort;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Platform\Retention\AccountReferenceSource;

/** Keeps account attribution checks beside the records this owner persists. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        return ProjectReportingPeriod::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectReportField::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectRecordValue::query()->where('updated_by', $accountId)->exists()
            || ProjectReportTemplate::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectReport::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectReportSubmission::query()->where('submitted_by', $accountId)->orWhere('reviewer_id', $accountId)->orWhere('reviewed_by', $accountId)->exists()
            || ProjectFunding::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectFundingInstallment::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectContribution::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectContributionDelivery::query()->where('created_by', $accountId)->orWhere('voided_by', $accountId)->exists()
            || ProjectCashAllocation::query()->where('created_by', $accountId)->exists()
            || ProjectOutcome::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectIndicator::query()->where('created_by', $accountId)->orWhere('updated_by', $accountId)->exists()
            || ProjectResultEntry::query()->where('created_by', $accountId)->orWhere('voided_by', $accountId)->exists()
            || ProjectRecordFile::query()->where('uploaded_by', $accountId)->exists()
            || TaskComment::query()->where('author_id', $accountId)->exists()
            || ProjectTask::query()->where('assigned_by', $accountId)->exists()
            || ProjectTask::query()->where('reviewed_by', $accountId)->exists()
            || Project::query()->where('manager_id', $accountId)->exists()
            || ProjectClient::query()->where('owner_id', $accountId)->exists()
            || ProjectPortfolio::query()->where('owner_id', $accountId)->exists()
            || ProjectAvailability::query()->where('updated_by', $accountId)->exists()
            || ProjectTaskEffort::query()->where('updated_by', $accountId)->exists()
            || ProjectBudget::query()->where('created_by', $accountId)->orWhere('approved_by', $accountId)->exists()
            || ProjectCashEntry::query()->where('created_by', $accountId)->orWhere('voided_by', $accountId)->exists()
            || ProjectTaskFile::query()->where('uploaded_by', $accountId)->exists()
            || ProjectTaskAssignee::query()->where('assigned_by', $accountId)->exists()
            || ProjectTaskSubmission::query()->where('reviewed_by', $accountId)->exists();
    }
}
