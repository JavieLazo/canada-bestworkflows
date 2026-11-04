<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Models\EmployeeLeavePolicy;
use App\Models\LeaveConversionTable;
use App\Models\LeaveCreditEntry;
use App\Models\LeavePolicyPreset;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveTravelRequest;
use App\Models\OvertimeApproval;
use App\Models\RosterDayOverride;
use App\Models\RosterScheduleChange;
use App\Platform\Retention\AccountReferenceSource;

/** Keeps account attribution checks beside the records this owner persists. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        // An account that recorded credits, reviewed or cancelled leave, decided overtime, made or
        // cancelled a schedule change, or prepared or approved a leave policy, a
        // conversion table or a preset authorised something on the record, so it
        // is kept.
        return LeaveTravelRequest::query()->where('reviewed_by', $accountId)->exists()
            || LeaveRequestCancellation::query()->where('cancelled_by', $accountId)->exists()
            || LeaveCreditEntry::query()->where('recorded_by', $accountId)->exists()
            || OvertimeApproval::query()->where('reviewed_by', $accountId)->exists()
            || RosterScheduleChange::query()->where('created_by', $accountId)->orWhere('cancelled_by', $accountId)->exists()
            || RosterDayOverride::query()->where('created_by', $accountId)->orWhere('cancelled_by', $accountId)->exists()
            || EmployeeLeavePolicy::query()->where('prepared_by', $accountId)->orWhere('approved_by', $accountId)->exists()
            || LeaveConversionTable::query()->where('created_by', $accountId)->orWhere('approved_by', $accountId)->exists()
            || LeavePolicyPreset::query()->where('created_by', $accountId)->exists();
    }
}
