<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use RuntimeException;

final class TaskHierarchy
{
    public static function canAdd(string $status, ?string $parentId, bool $scopeLocked): bool
    {
        return $parentId === null && in_array($status, ['PENDING', 'IN_PROGRESS'], true) && ! $scopeLocked;
    }

    /** The creator already holds the task-code scan and project locks. */
    public static function validateParent(string $projectId, string $parentId): void
    {
        $parent = ProjectTask::query()->where('project_id', $projectId)->whereKey($parentId)->lockForUpdate()->first();
        if ($parent === null) {
            throw new RuntimeException('Choose a parent task in this project.');
        }
        if ($parent->parent_id !== null) {
            throw new RuntimeException('Subtasks support one level. Add this work to the top-level task instead.');
        }
        $responses = ProjectTaskSubmission::query()->where('task_id', $parent->id)->lockForUpdate()->get(['id']);
        $files = ProjectTaskFile::query()->where('task_id', $parent->id)->where('kind', 'SUBMISSION')->lockForUpdate()->get(['id']);
        if (! self::canAdd($parent->status, $parent->parent_id, TaskSubmissionPolicy::modeLocked($parent->status,
            TaskSubmissionPolicy::groupHandedIn($parent->submitted_by, $parent->submitted_at, $parent->remarks), $responses->count(), $files->isNotEmpty()))) {
            throw new RuntimeException('This task has already been handed in or closed. Add new scope as a separate task.');
        }
    }
}
