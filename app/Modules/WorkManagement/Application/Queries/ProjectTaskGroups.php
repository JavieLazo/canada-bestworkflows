<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Models\ProjectTaskGroup;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\TaskGroupView;
use App\Modules\WorkManagement\Infrastructure\ProjectMembership;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

final class ProjectTaskGroups
{
    /** @return Collection<int, TaskGroupView> */
    public function forProject(Access $actor, string $projectId): Collection
    {
        $project = app(WorkRecords::class)->project($actor, $projectId);
        if ($project === null) {
            return collect();
        }
        $query = ProjectTaskGroup::query()->where('project_id', $projectId);
        if (! WorkRules::runsProject($actor, $project) && ! ProjectMembership::forActor($actor, true)->where('project_id', $projectId)->exists()) {
            $query->whereIn('id', WorkScope::tasks($actor)->where('project_id', $projectId)->selectRaw(WorkScope::EFFECTIVE_GROUP_SQL));
        }

        return $query->orderBy('created_at')->orderBy('id')->get()
            ->map(static fn (ProjectTaskGroup $group): TaskGroupView => Snapshot::of($group, TaskGroupView::class));
    }
}
