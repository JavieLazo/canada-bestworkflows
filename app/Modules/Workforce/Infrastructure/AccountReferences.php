<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Models\EmployeeDocument;
use App\Models\EmployeeRecordChangeRequest;
use App\Platform\Retention\AccountReferenceSource;
use Illuminate\Support\Facades\Schema;

/** Keeps account attribution checks beside the records this owner persists. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        return EmployeeDocument::query()->where('uploaded_by', $accountId)->exists()
            || (Schema::hasTable('employee_record_change_requests') && EmployeeRecordChangeRequest::query()->where(static fn ($query) => $query->where('requested_by', $accountId)->orWhere('reviewed_by', $accountId)->orWhere('withdrawn_by', $accountId))->exists());
    }
}
