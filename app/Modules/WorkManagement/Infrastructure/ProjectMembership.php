<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\ProjectMember;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

final class ProjectMembership
{
    public const ROLES = ['PARTICIPANT' => 'Participant', 'VIEWER' => 'Viewer', 'EDITOR' => 'Task editor'];

    /** @return Builder<ProjectMember> */
    public static function forActor(Access $actor, bool $allTasks = false): Builder
    {
        $query = ProjectMember::query();
        if (! $actor->isSignedIn() || ! ($actor->hasModule(ModuleCode::PROJECTS) || (! $allTasks && $actor->hasModule(ModuleCode::TASKS)))) {
            return $query->whereRaw('1 = 0');
        }
        $query->where('user_id', (int) $actor->userId);

        return $allTasks ? $query->whereIn('role', ['VIEWER', 'EDITOR']) : $query;
    }

    public static function edits(Access $actor, string $projectId, bool $lock = false): bool
    {
        $query = self::forActor($actor, true)->where('project_id', $projectId)->where('role', 'EDITOR');

        return ($lock ? $query->lockForUpdate() : $query)->first() !== null;
    }
}
