<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectTaskGroup;
use RuntimeException;

final class TaskGroups
{
    public const int NAME_LIMIT = 120;

    /** The caller holds the project lock, which also serializes group changes. */
    public static function resolve(string $projectId, ?string $groupId): ?string
    {
        if ($groupId !== null && ProjectTaskGroup::query()->where('project_id', $projectId)->whereKey($groupId)->lockForUpdate()->first() === null) {
            throw new RuntimeException('Choose an activity in this project.');
        }

        return $groupId;
    }
}
