<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectIndicator;
use App\Models\ProjectOutcome;
use App\Models\ProjectResultEntry;
use App\Models\ProjectTask;
use App\Models\ProjectTaskGroup;
use App\Modules\WorkManagement\Application\IndicatorDraft;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Contracts\Views\IndicatorView;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveIndicator
{
    public function handle(Access $actor, string $projectId, ?string $id, int $revision, IndicatorDraft $input): IndicatorView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $input): IndicatorView {
            $project = ReportingRules::locked($actor, $projectId, edit: true);
            Validator::make(['name' => trim($input->name), 'kind' => $input->kind, 'unit' => trim($input->unit), 'aggregation' => $input->aggregation,
                'direction' => $input->direction, 'description' => trim($input->description), 'targetText' => trim($input->targetText), 'status' => $input->status], [
                    'name' => 'required|string|max:200', 'kind' => 'required|in:NUMERIC,QUALITATIVE', 'unit' => [$input->kind === 'NUMERIC' ? 'required' : 'nullable', 'string', 'max:60'],
                    'aggregation' => 'required|in:SUM,LATEST', 'direction' => 'required|in:AT_LEAST,AT_MOST,EXACT', 'description' => 'nullable|string|max:4000',
                    'targetText' => 'nullable|string|max:1000', 'status' => 'required|in:ACTIVE,DISCONTINUED',
                ])->validate();
            foreach (['startsOn' => $input->startsOn, 'endsOn' => $input->endsOn] as $field => $date) {
                if ($date !== null && ! TaskSchedule::isDateKey($date)) {
                    throw ValidationException::withMessages([$field => 'Choose a valid date.']);
                }
            }
            if ($input->startsOn !== null && $input->endsOn !== null && $input->startsOn > $input->endsOn) {
                throw ValidationException::withMessages(['endsOn' => 'The target end must be on or after its start.']);
            }
            $row = $id === null ? null : ReportingRules::indicator($project->id, $id);
            ReportingRules::revision($row, $revision);
            $aggregation = $input->kind === 'QUALITATIVE' ? 'LATEST' : $input->aggregation;
            $unit = $input->kind === 'NUMERIC' ? trim($input->unit) : null;
            if ($row !== null && ProjectResultEntry::query()->where('indicator_id', $row->id)->lockForUpdate()->first() !== null
                && ($row->kind !== $input->kind || $row->unit !== $unit || $row->aggregation !== $aggregation || $row->direction !== $input->direction)) {
                throw ValidationException::withMessages(['kind' => 'Keep the unit, value type and aggregation after accomplishments exist. Create another indicator for a different measure.']);
            }
            $outcome = $input->outcomeId === null ? null : ProjectOutcome::query()->where('project_id', $project->id)->whereKey($input->outcomeId)->first();
            $group = $input->groupId === null ? null : ProjectTaskGroup::query()->where('project_id', $project->id)->whereKey($input->groupId)->first();
            $task = $input->taskId === null ? null : ProjectTask::query()->where('project_id', $project->id)->whereKey($input->taskId)->first();
            if (($input->outcomeId !== null && $outcome === null) || ($input->groupId !== null && $group === null)
                || ($input->taskId !== null && $task === null) || ($group !== null && $task !== null)) {
                throw ValidationException::withMessages(['workLink' => 'Choose an outcome and one activity or task in this project.']);
            }
            $row ??= new ProjectIndicator;
            ReportingRules::history($row, $actor, $input->reason);
            $row->forceFill(['project_id' => $project->id, 'outcome_id' => $outcome?->id, 'outcome_label' => $outcome?->name,
                'group_id' => $group?->id, 'task_id' => $task?->id, 'work_label' => $group->name ?? $task?->title,
                'name' => trim($input->name), 'description' => trim($input->description) ?: null, 'kind' => $input->kind, 'unit' => $unit,
                'aggregation' => $aggregation, 'direction' => $input->direction, 'target_scaled' => $input->kind === 'NUMERIC' ? ResultValue::parse($input->target, 'target') : null,
                'target_text' => $input->kind === 'QUALITATIVE' ? (trim($input->targetText) ?: null) : null,
                'starts_on' => $input->startsOn, 'ends_on' => $input->endsOn, 'evidence_required' => $input->evidenceRequired,
                'status' => $input->status, 'revision' => $revision + 1, 'plan_version' => ($row->plan_version ?? 0) + 1,
                'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_INDICATOR_SAVED, 'projects', $project->id, 'Indicator plan version '.$row->plan_version.' saved');

            return Snapshot::of($row, IndicatorView::class);
        });
    }
}
