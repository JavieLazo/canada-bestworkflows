<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Models\LeaveConversionTable;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveRule;
use App\Models\LeaveSignatory;
use App\Models\LeaveTravelRequest;
use App\Models\OvertimeApproval;
use App\Models\RosterDayOverride;
use App\Models\RosterScheduleChange;
use App\Modules\Timekeeping\Application\LeaveRecommendation;
use App\Modules\Timekeeping\Application\LeaveRules;
use App\Modules\Timekeeping\Application\Queries\LeaveSignatoryRecords;
use App\Platform\Retention\AccountReferenceSource;

/** Keeps account attribution checks beside the records this owner persists. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        // An account that recorded credits, recommended, reviewed or cancelled leave, decided
        // overtime, made or cancelled a schedule change, or prepared or approved a leave rule, a
        // conversion table, or set an officer of the leave form authorised something on the record, so it is kept.
        return LeaveTravelRequest::query()->where('reviewed_by', $accountId)->exists()
            || (LeaveRecommendation::ready() && LeaveTravelRequest::query()->where('recommended_by', $accountId)->exists())
            || LeaveRequestCancellation::query()->where('cancelled_by', $accountId)->exists()
            || LeaveCreditEntry::query()->where('recorded_by', $accountId)->exists()
            || OvertimeApproval::query()->where('reviewed_by', $accountId)->exists()
            || RosterScheduleChange::query()->where('created_by', $accountId)->orWhere('cancelled_by', $accountId)->exists()
            || RosterDayOverride::query()->where('created_by', $accountId)->orWhere('cancelled_by', $accountId)->exists()
            || LeaveConversionTable::query()->where('created_by', $accountId)->orWhere('approved_by', $accountId)->exists()
            || (LeaveRules::ready() && LeaveRule::query()->where('prepared_by', $accountId)->orWhere('approved_by', $accountId)->exists())
            || (LeaveSignatoryRecords::ready() && LeaveSignatory::query()->where('updated_by', $accountId)->exists());
    }
}
