<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Platform\Retention\AccountReferenceSource;

/** Keeps account attribution checks beside the records this owner persists. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        return TaskComment::query()->where('author_id', $accountId)->exists()
            || ProjectTask::query()->where('assigned_by', $accountId)->exists()
            || ProjectTask::query()->where('reviewed_by', $accountId)->exists()
            || Project::query()->where('manager_id', $accountId)->exists()
            || ProjectTaskFile::query()->where('uploaded_by', $accountId)->exists()
            || ProjectTaskAssignee::query()->where('assigned_by', $accountId)->exists()
            || ProjectTaskSubmission::query()->where('reviewed_by', $accountId)->exists();
    }
}
