<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

final class PlanningScope
{
    /** @return Builder<Project> */
    public static function projects(Access $actor): Builder
    {
        $query = WorkScope::projects($actor);
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::PROJECTS)) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->mayReadAllData(ModuleCode::PROJECTS)) {
            return $query;
        }

        return $query->where(static fn (Builder $query) => $query
            ->whereIn('projects.client_id', ClientScope::clients($actor)->where('owner_id', $actor->userId)->select('id'))
            ->orWhereIn('projects.id', ProjectMembership::forActor($actor, true)->where('role', 'EDITOR')->select('project_id')));
    }
}
