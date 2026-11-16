<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectResultEntry;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Application\ResultEntryDraft;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecordResultEntry
{
    public function handle(Access $actor, string $projectId, string $indicatorId, ResultEntryDraft $input): string
    {
        return DB::transaction(static function () use ($actor, $projectId, $indicatorId, $input): string {
            $project = ReportingRules::locked($actor, $projectId, edit: true);
            $indicator = ReportingRules::indicator($project->id, $indicatorId);
            $values = ['valueText' => trim($input->valueText), 'state' => $input->state, 'narrative' => trim($input->narrative),
                'issues' => trim($input->issues), 'correctiveAction' => trim($input->correctiveAction), 'reference' => trim($input->reference),
                'correctionReason' => trim($input->correctionReason), 'requestId' => $input->requestId];
            Validator::make($values, ['valueText' => [$indicator->kind === 'QUALITATIVE' ? 'required' : 'nullable', 'string', 'max:1000'],
                'state' => [$indicator->kind === 'QUALITATIVE' ? 'required' : 'nullable', Rule::in(array_keys(ReportingRules::STATES))],
                'narrative' => 'nullable|string|max:4000', 'issues' => 'nullable|string|max:4000', 'correctiveAction' => 'nullable|string|max:4000',
                'reference' => 'nullable|string|max:200', 'requestId' => 'nullable|ulid', 'correctionReason' => [$input->replacesId === null ? 'nullable' : 'required', 'string', 'max:1000']])->validate();
            if (! TaskSchedule::isDateKey($input->occurredOn) || $input->occurredOn > ManilaClock::todayKey()) {
                throw ValidationException::withMessages(['occurredOn' => 'Choose an actual date through today.']);
            }
            $value = $indicator->kind === 'NUMERIC' ? ResultValue::parse($input->value) : null;
            if ($indicator->kind === 'NUMERIC' && $value === null) {
                throw ValidationException::withMessages(['value' => 'Enter the actual value; zero is allowed.']);
            }
            $text = $indicator->kind === 'QUALITATIVE' ? $values['valueText'] : null;
            $state = $indicator->kind === 'QUALITATIVE' ? $input->state : null;
            $accepted = $input->requestId === null ? null : ProjectResultEntry::query()->where('project_id', $project->id)->whereKey($input->requestId)->lockForUpdate()->first();
            if ($accepted !== null) {
                if ($accepted->indicator_id !== $indicator->id || $accepted->created_by !== (int) $actor->userId || $accepted->occurred_on !== $input->occurredOn
                    || $accepted->value_scaled !== $value || $accepted->value_text !== $text || $accepted->state !== $state
                    || ($accepted->narrative ?? '') !== $values['narrative'] || ($accepted->issues ?? '') !== $values['issues']
                    || ($accepted->corrective_action ?? '') !== $values['correctiveAction'] || ($accepted->reference ?? '') !== $values['reference'] || $accepted->replaces_id !== $input->replacesId) {
                    throw ValidationException::withMessages(['result' => 'This form was already recorded. Open a new accomplishment form.']);
                }

                return $accepted->id;
            }
            if ($indicator->status !== 'ACTIVE' && $input->replacesId === null) {
                throw ValidationException::withMessages(['result' => 'This indicator is discontinued. Revise its plan before adding accomplishments.']);
            }
            $old = $input->replacesId === null ? null : ProjectResultEntry::query()->where('indicator_id', $indicator->id)->whereKey($input->replacesId)->lockForUpdate()->first();
            abort_if($input->replacesId !== null && $old === null, 404);
            if ($old !== null) {
                if ($old->voided_at !== null) {
                    throw ValidationException::withMessages(['result' => 'This entry was already corrected or voided. Reload the history.']);
                }
                $old->forceFill(['voided_at' => now(), 'voided_by' => (int) $actor->userId, 'void_reason' => $values['correctionReason']])->save();
            }
            if ($value !== null && $indicator->aggregation === 'SUM') {
                $total = (int) ProjectResultEntry::query()->where('indicator_id', $indicator->id)->whereNull('voided_at')->lockForUpdate()->sum('value_scaled');
                if (abs($total + $value) > ResultValue::LIMIT) {
                    throw ValidationException::withMessages(['value' => 'The cumulative result is too large.']);
                }
            }
            $entry = new ProjectResultEntry;
            if ($input->requestId !== null) {
                $entry->id = $input->requestId;
            }
            $entry->forceFill(['project_id' => $project->id, 'indicator_id' => $indicator->id, 'plan_version' => $indicator->plan_version,
                'evidence_required' => $indicator->evidence_required, 'occurred_on' => $input->occurredOn, 'value_scaled' => $value, 'value_text' => $text, 'state' => $state,
                'narrative' => $values['narrative'] ?: null, 'issues' => $values['issues'] ?: null, 'corrective_action' => $values['correctiveAction'] ?: null,
                'reference' => $values['reference'] ?: null, 'created_by' => (int) $actor->userId, 'replaces_id' => $old?->id])->save();
            AuditService::record($actor, AuditEvent::PROJECT_RESULT_RECORDED, 'projects', $project->id, 'Accomplishment recorded'.($old === null ? '' : ' as a correction'));

            return $entry->id;
        }, 3);
    }
}
