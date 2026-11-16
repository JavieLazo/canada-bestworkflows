<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectOutcome;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Contracts\Views\OutcomeView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveOutcome
{
    public function handle(Access $actor, string $projectId, ?string $id, int $revision, string $name, string $kind = 'OBJECTIVE', string $description = '', ?string $parentId = null, string $status = 'ACTIVE', string $reason = ''): OutcomeView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $name, $kind, $description, $parentId, $status, $reason): OutcomeView {
            $project = ReportingRules::locked($actor, $projectId, edit: true);
            Validator::make(['name' => trim($name), 'description' => trim($description), 'kind' => $kind, 'status' => $status], [
                'name' => 'required|string|max:200', 'description' => 'nullable|string|max:4000', 'kind' => 'required|in:OBJECTIVE,DELIVERABLE', 'status' => 'required|in:ACTIVE,DISCONTINUED',
            ])->validate();
            $row = $id === null ? null : ProjectOutcome::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            ReportingRules::revision($row, $revision);
            $parent = $parentId === null ? null : ProjectOutcome::query()->where('project_id', $project->id)->whereKey($parentId)->where('kind', 'OBJECTIVE')->first();
            if ($parentId !== null && ($kind !== 'DELIVERABLE' || $parent === null || $parent->id === $id)) {
                throw ValidationException::withMessages(['parentId' => 'Choose an objective in this project for the deliverable.']);
            }
            if ($row !== null && $row->kind !== $kind && ProjectOutcome::query()->where('parent_id', $row->id)->exists()) {
                throw ValidationException::withMessages(['kind' => 'This objective has deliverables. Keep its kind or move them first.']);
            }
            $row ??= new ProjectOutcome;
            ReportingRules::history($row, $actor, $reason);
            $row->forceFill(['project_id' => $project->id, 'parent_id' => $parent?->id, 'name' => trim($name), 'kind' => $kind,
                'description' => trim($description) ?: null, 'status' => $status, 'revision' => $revision + 1,
                'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_OUTCOME_SAVED, 'projects', $project->id, ucfirst(strtolower($kind)).' plan saved');

            return Snapshot::of($row, OutcomeView::class);
        });
    }
}
