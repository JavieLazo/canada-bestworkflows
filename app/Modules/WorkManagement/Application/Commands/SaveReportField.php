<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReport;
use App\Models\ProjectReportField;
use App\Models\ProjectReportSubmission;
use App\Models\ProjectReportTemplate;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\ReportDefinitions;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Contracts\Views\ReportFieldView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveReportField
{
    /** @param list<string> $choices */
    public function handle(Access $actor, string $projectId, ?string $id, int $revision, string $name, string $entity, string $type,
        string $unit = '', string $visibility = 'PROJECT', bool $required = false, bool $total = false, array $choices = [], bool $archived = false, string $reason = ''): ReportFieldView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $name, $entity, $type, $unit, $visibility, $required, $total, $choices, $archived, $reason): ReportFieldView {
            $project = ReportingRules::locked($actor, $projectId, admin: true);
            Validator::make(['name' => trim($name), 'entity' => $entity, 'type' => $type, 'unit' => trim($unit), 'visibility' => $visibility, 'choices' => $choices], [
                'name' => 'required|string|max:120', 'entity' => ['required', Rule::in(array_keys(ReportDefinitions::ENTITIES))],
                'type' => ['required', Rule::in(array_keys(ReportDefinitions::TYPES))], 'unit' => 'nullable|string|max:60', 'visibility' => 'required|in:PROJECT,FINANCE',
                'choices' => [$type === 'CHOICE' ? 'required' : 'nullable', 'array', 'max:50'], 'choices.*' => 'required|string|max:120|distinct',
            ])->validate();
            $row = $id === null ? null : ProjectReportField::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            ReportingRules::revision($row, $revision);
            if ($row !== null && ProjectRecordValue::query()->where('field_id', $row->id)->exists()) {
                if ($row->type !== $type || $row->entity_type !== $entity || ($row->unit ?? '') !== trim($unit)) {
                    throw ValidationException::withMessages(['type' => 'Keep a field’s type, record kind and unit once answers exist. Add another field for a different value.']);
                }
                if ($type === 'CHOICE' && array_diff($row->options['choices'] ?? [], $choices) !== []) {
                    throw ValidationException::withMessages(['choices' => 'Keep existing choices once answers exist. You can add more choices.']);
                }
            }
            $financial = $type === 'MONEY' || in_array($entity, ['BUDGET_LINE', 'CASH_ENTRY', 'FUNDING', 'CONTRIBUTION', 'CONTRIBUTION_DELIVERY'], true) || $visibility === 'FINANCE';
            if ($type === 'MONEY') {
                FinanceRules::settings($project->id);
            }
            $row ??= new ProjectReportField;
            ReportingRules::history($row, $actor, $reason);
            $row->forceFill(['project_id' => $project->id, 'entity_type' => $entity, 'name' => trim($name), 'type' => $type, 'unit' => trim($unit) ?: null,
                'visibility' => $financial ? 'FINANCE' : 'PROJECT', 'required' => $required, 'total' => $total && in_array($type, ['NUMBER', 'MONEY'], true),
                'options' => $type === 'CHOICE' ? ['choices' => $choices] : null, 'archived' => $archived, 'revision' => $revision + 1,
                'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            foreach (ProjectReportTemplate::query()->where('project_id', $project->id)->whereJsonContains('definition->fields', $row->id)->get() as $template) {
                if ($archived) {
                    ReportingRules::history($template, $actor, 'Field archived: '.$row->name);
                    $definition = $template->definition;
                    $definition['fields'] = array_values(array_diff($definition['fields'], [$row->id]));
                    $template->definition = $definition;
                    $template->revision++;
                }
                $scope = ReportDefinitions::scope($template->definition);
                $template->forceFill(['work' => $scope['work'], 'financial' => $scope['financial']])->save();
                ReportDefinitions::protectDrafts($template);
            }
            if ($financial) {
                $submissions = ProjectReportSubmission::query()->where('project_id', $project->id)->whereJsonContains('field_ids', $row->id);
                ProjectReport::query()->whereIn('id', (clone $submissions)->select('report_id'))->update(['financial' => true]);
                $submissions->update(['financial' => true]);
            }
            AuditService::record($actor, AuditEvent::PROJECT_REPORT_CONFIGURED, 'projects', $project->id, 'Typed report field version '.$row->revision.' saved');

            return Snapshot::of($row, ReportFieldView::class);
        });
    }
}
