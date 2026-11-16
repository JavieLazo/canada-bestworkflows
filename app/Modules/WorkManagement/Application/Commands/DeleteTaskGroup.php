<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectTask;
use App\Models\ProjectTaskGroup;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DeleteTaskGroup
{
    public function handle(Access $actor, string $projectId, string $groupId): bool
    {
        if (! WorkRules::editsWork($actor, WorkRules::project($actor, $projectId))) {
            throw new RuntimeException(WorkRules::forbiddenMessage());
        }

        return DB::transaction(static function () use ($actor, $projectId, $groupId): bool {
            $project = WorkRules::lockedProject($actor, $projectId);
            if (! WorkRules::editsWork($actor, $project, lock: true)) {
                throw new RuntimeException(WorkRules::forbiddenMessage());
            }
            $group = ProjectTaskGroup::query()->where('project_id', $projectId)->whereKey($groupId)->lockForUpdate()->first();
            if ($group === null) {
                return false;
            }
            if (ProjectTask::query()->where('group_id', $groupId)->exists()) {
                throw new RuntimeException('Move this activity\'s tasks to another activity or choose No activity before removing it.');
            }
            $group->delete();
            AuditService::record($actor, AuditEvent::TASK_GROUP_DELETED, 'project_task_groups', $group->id, 'Empty activity '.$group->name.' removed from '.$project->code);

            return true;
        });
    }
}
