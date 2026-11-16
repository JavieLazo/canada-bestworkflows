<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReportField;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class StartProjectBudget
{
    public function handle(Access $actor, string $projectId, string $reason = ''): string
    {
        return DB::transaction(static function () use ($actor, $projectId, $reason): string {
            FinanceRules::locked($actor, $projectId, edit: true);
            FinanceRules::settings($projectId);
            $current = ProjectBudget::query()->where('project_id', $projectId)->where('status', 'DRAFT')->first();
            if ($current !== null) {
                return $current->id;
            }
            $previous = ProjectBudget::query()->where('project_id', $projectId)->where('status', 'APPROVED')->orderByDesc('version')->first();
            $reason = trim($reason);
            Validator::make(['revisionReason' => $reason], ['revisionReason' => [$previous === null ? 'nullable' : 'required', 'string', 'max:1000']])->validate();
            $budget = new ProjectBudget;
            $budget->forceFill(['project_id' => $projectId, 'version' => (int) ProjectBudget::query()->where('project_id', $projectId)->max('version') + 1,
                'revision' => 1, 'status' => 'DRAFT', 'reason' => $reason === '' ? null : $reason, 'created_by' => (int) $actor->userId])->save();
            if ($previous !== null) {
                $fieldIds = ProjectReportField::query()->where('project_id', $projectId)->where('entity_type', 'BUDGET_LINE')->pluck('id');
                $values = ProjectRecordValue::query()->where('project_id', $projectId)->whereIn('field_id', $fieldIds)
                    ->whereIn('record_id', ProjectBudgetLine::query()->where('budget_id', $previous->id)->select('id'))->get()->groupBy('record_id');
                foreach (ProjectBudgetLine::query()->where('budget_id', $previous->id)->orderBy('id')->lazyById(100) as $line) {
                    $copy = $line->replicate();
                    $copy->budget_id = $budget->id;
                    $copy->save();
                    foreach ($values->get($line->id, collect()) as $value) {
                        $answer = $value->replicate();
                        $answer->forceFill(['record_id' => $copy->id, 'revision' => 1, 'updated_by' => (int) $actor->userId])->save();
                    }
                }
            }
            AuditService::record($actor, AuditEvent::PROJECT_BUDGET_DRAFTED, 'projects', $projectId, 'Budget version '.$budget->version.' drafted');

            return $budget->id;
        });
    }
}
