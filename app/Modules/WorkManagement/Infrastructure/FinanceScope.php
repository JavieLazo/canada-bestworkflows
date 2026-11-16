<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectFinanceMember;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

final class FinanceScope
{
    public const ROLES = ['VIEWER' => 'Financial viewer', 'EDITOR' => 'Financial editor'];

    /** @return Builder<Project> */
    public static function projects(Access $actor, bool $edit = false, string $portfolio = 'ALL', string $client = 'ALL'): Builder
    {
        $projects = WorkScope::projects($actor, $portfolio);
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::PROJECTS)) {
            return $projects->whereRaw('1 = 0');
        }
        if ($client !== 'ALL') {
            $projects->where('projects.client_id', $client);
        }
        if ($actor->mayReadAllData(ModuleCode::PROJECTS)) {
            return $projects;
        }

        return $projects->where(static function (Builder $visible) use ($actor, $edit): void {
            $visible->whereIn('projects.client_id', ClientScope::clients($actor)->where('owner_id', $actor->userId)->select('id'))
                ->orWhere(static function (Builder $granted) use ($actor, $edit): void {
                    $granted->whereIn('projects.id', ProjectFinanceMember::query()->where('user_id', $actor->userId)
                        ->whereIn('role', $edit ? ['EDITOR'] : array_keys(self::ROLES))->select('project_id'))
                        ->where(static fn (Builder $team) => $team->where('projects.manager_id', $actor->userId)
                            ->orWhereIn('projects.id', ProjectMembership::forActor($actor)->select('project_id')));
                });
        });
    }
}
