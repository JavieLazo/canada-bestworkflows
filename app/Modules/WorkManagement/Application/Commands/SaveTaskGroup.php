<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectTaskGroup;
use App\Modules\WorkManagement\Application\TaskGroups;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\TaskGroupView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SaveTaskGroup
{
    public function handle(Access $actor, string $projectId, ?string $groupId, string $name): TaskGroupView
    {
        if (! WorkRules::editsWork($actor, WorkRules::project($actor, $projectId))) {
            throw new RuntimeException(WorkRules::forbiddenMessage());
        }
        try {
            return DB::transaction(static function () use ($actor, $projectId, $groupId, $name): TaskGroupView {
                $project = WorkRules::lockedProject($actor, $projectId);
                if (! WorkRules::editsWork($actor, $project, lock: true)) {
                    throw new RuntimeException(WorkRules::forbiddenMessage());
                }
                $name = trim($name);
                Validator::make(['groupName' => $name], ['groupName' => 'required|string|max:'.TaskGroups::NAME_LIMIT], [], ['groupName' => 'activity name'])->validate();
                $group = $groupId === null ? new ProjectTaskGroup : ProjectTaskGroup::query()->where('project_id', $projectId)->whereKey($groupId)->lockForUpdate()->first();
                if ($group === null) {
                    throw new RuntimeException('That activity no longer exists.');
                }
                $group->fill(['project_id' => $projectId, 'name' => $name]);
                if (! $group->exists || $group->isDirty()) {
                    $created = ! $group->exists;
                    $group->save();
                    AuditService::record($actor, $created ? AuditEvent::TASK_GROUP_CREATED : AuditEvent::TASK_GROUP_RENAMED, 'project_task_groups', $group->id, 'Activity '.$name.($created ? ' created in ' : ' renamed in ').$project->code);
                }

                return Snapshot::of($group, TaskGroupView::class);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['groupName' => 'This project already has an activity with this name.']);
        }
    }
}
