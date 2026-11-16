<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectIndicator;
use App\Modules\WorkManagement\Infrastructure\ProjectMembership;
use App\Support\Access;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class ReportingRules
{
    public const STATES = ['NOT_STARTED' => 'Not started', 'IN_PROGRESS' => 'In progress', 'PARTIAL' => 'Partially achieved', 'ACHIEVED' => 'Achieved', 'NOT_APPLICABLE' => 'Not applicable'];

    public static function locked(Access $actor, string $projectId, bool $edit = false, bool $admin = false): Project
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS), 403);
        $project = WorkRules::lockedProject($actor, $projectId);
        abort_if($project === null, 403);
        if (WorkRules::managesProject($actor, $project)) {
            return $project;
        }
        abort_if($admin, 403, 'Only project administrators can change reporting configuration.');
        if ($edit) {
            abort_unless(WorkRules::editsWork($actor, $project, lock: true), 403);
        } else {
            abort_unless(WorkRules::leadsProject($actor, $project)
                || ProjectMembership::forActor($actor, true)->where('project_id', $project->id)->lockForUpdate()->first() !== null, 403);
        }

        return $project;
    }

    public static function indicator(string $projectId, string $id): ProjectIndicator
    {
        $indicator = ProjectIndicator::query()->where('project_id', $projectId)->whereKey($id)->lockForUpdate()->first();
        abort_if($indicator === null, 404);

        return $indicator;
    }

    public static function revision(?Model $row, int $expected, string $field = 'result'): void
    {
        if ((int) ($row?->getAttribute('revision') ?? 0) !== $expected) {
            throw ValidationException::withMessages([$field => 'This record changed. Reopen it before saving.']);
        }
    }

    public static function history(Model $row, Access $actor, string $reason): void
    {
        if (! $row->exists) {
            return;
        }
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Explain the plan change in up to 1,000 characters.']);
        }
        $values = $row->getOriginal();
        unset($values['history'], $values['created_at'], $values['updated_at']);
        $row->setAttribute('history', [...($row->getAttribute('history') ?? []), ['values' => $values, 'reason' => trim($reason), 'by' => (int) $actor->userId, 'at' => now()->toIso8601String()]]);
    }
}
