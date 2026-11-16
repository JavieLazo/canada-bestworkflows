<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectReportTemplate;
use App\Modules\WorkManagement\Application\ReportDefinitions;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Contracts\Views\ReportTemplateView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveReportTemplate
{
    /** @param array<string, mixed> $definition */
    public function handle(Access $actor, string $projectId, ?string $id, int $revision, string $name, string $kind, array $definition, string $description = '', bool $archived = false, string $reason = '', ?string $code = null): ReportTemplateView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $name, $kind, $definition, $description, $archived, $reason, $code): ReportTemplateView {
            $project = ReportingRules::locked($actor, $projectId, admin: true);
            Validator::make(['name' => trim($name), 'kind' => $kind, 'description' => trim($description), 'code' => $code], [
                'name' => 'required|string|max:160', 'kind' => ['required', Rule::in(array_keys(ReportDefinitions::KINDS))], 'description' => 'nullable|string|max:1000', 'code' => 'nullable|string|max:50',
            ])->validate();
            $definition = ReportDefinitions::validate($project->id, $definition);
            $scope = ReportDefinitions::scope($definition);
            $row = $id === null ? null : ProjectReportTemplate::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            ReportingRules::revision($row, $revision);
            $row ??= new ProjectReportTemplate;
            ReportingRules::history($row, $actor, $reason);
            $row->forceFill(['project_id' => $project->id, 'code' => $row->code ?? $code, 'name' => trim($name), 'kind' => $kind,
                'description' => trim($description) ?: null, 'definition' => $definition, 'work' => $scope['work'], 'financial' => $scope['financial'],
                'archived' => $archived, 'revision' => $revision + 1, 'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            ReportDefinitions::protectDrafts($row);
            AuditService::record($actor, AuditEvent::PROJECT_REPORT_CONFIGURED, 'projects', $project->id, 'Report template version '.$row->revision.' saved');

            return Snapshot::of($row, ReportTemplateView::class);
        });
    }
}
