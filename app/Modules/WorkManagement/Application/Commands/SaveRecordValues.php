<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectBudget;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReport;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\ReportFieldValues;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveRecordValues
{
    /** @param array<string, mixed> $input */
    public function handle(Access $actor, string $projectId, string $entity, string $recordId, string $signature, array $input): void
    {
        DB::transaction(static function () use ($actor, $projectId, $entity, $recordId, $signature, $input): void {
            $record = ReportFieldValues::record($actor, $projectId, $entity, $recordId, true);
            $projectId = $entity === 'PROJECT' ? (string) $record->getKey() : (string) $record->getAttribute('project_id');
            $fields = ReportFieldValues::definitions($actor, $projectId, $entity, true, $entity !== 'REPORT');
            $values = ProjectRecordValue::query()->where('project_id', $projectId)->where('record_id', $record->getKey())->whereIn('field_id', $fields->pluck('id')->all())->lockForUpdate()->get();
            if (! hash_equals(ReportFieldValues::signature($fields, $values), $signature) || array_diff(array_keys($input), $fields->pluck('id')->all()) !== []) {
                throw ValidationException::withMessages(['fields' => 'The fields or answers changed. Reopen this form before saving.']);
            }
            $normalized = [];
            foreach ($fields as $field) {
                if ($field->visibility === 'FINANCE' && $entity !== 'REPORT') {
                    FinanceRules::locked($actor, $projectId, edit: true);
                }
                $normalized[$field->id] = ReportFieldValues::normalize($actor, $field, $input[$field->id] ?? null, $projectId, (string) $record->getKey());
            }
            foreach ($normalized as $fieldId => $value) {
                $row = $values->firstWhere('field_id', $fieldId) ?? new ProjectRecordValue;
                $row->forceFill(['project_id' => $projectId, 'field_id' => $fieldId, 'record_id' => $record->getKey(), 'value' => ['value' => $value],
                    'revision' => ($row->revision ?? 0) + 1, 'updated_by' => (int) $actor->userId])->save();
            }
            if ($entity === 'BUDGET_LINE') {
                ProjectBudget::query()->whereKey($record->getAttribute('budget_id'))->increment('revision');
            }
            if ($entity === 'REPORT') {
                ProjectReport::query()->whereKey($record->getKey())->increment('revision');
            }
            AuditService::record($actor, AuditEvent::PROJECT_RECORD_VALUES_SAVED, 'projects', $projectId, 'Typed record fields saved');
        });
    }
}
