<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Modules\WorkManagement\Application\TaskGroups;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MoveTaskToGroup
{
    public function handle(Access $actor, string $projectId, string $taskId, ?string $groupId, ?string $expectedGroupId): bool
    {
        $seen = WorkRules::task($actor, $projectId, $taskId);
        if ($seen === null || ! WorkRules::editsWork($actor, $seen->project)) {
            throw new RuntimeException(WorkRules::forbiddenMessage());
        }

        return DB::transaction(static function () use ($actor, $projectId, $taskId, $groupId, $expectedGroupId): bool {
            $task = WorkRules::lockedTask($actor, $taskId, $projectId);
            $project = $task === null ? null : WorkRules::lockedProject($actor, $projectId);
            if ($task === null || ! WorkRules::editsWork($actor, $project, lock: true)) {
                throw new RuntimeException(WorkRules::forbiddenMessage());
            }
            if ($task->parent_id !== null) {
                throw new RuntimeException('Subtasks follow their parent\'s activity. Move the parent task instead.');
            }
            if ($task->group_id !== $expectedGroupId) {
                throw new RuntimeException('This task moved to another activity while you were editing. Reopen it to see its current activity.');
            }
            $target = TaskGroups::resolve($projectId, $groupId);
            if ($task->group_id === $target) {
                return false;
            }
            $task->group_id = $target;
            $task->save();
            AuditService::record($actor, AuditEvent::TASK_UPDATED, 'project_tasks', $task->id, 'Task '.$task->code.' activity changed');

            return true;
        });
    }
}
