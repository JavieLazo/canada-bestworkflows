<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectBudget;
use App\Models\ProjectReport;
use App\Models\ProjectReportingPeriod;
use App\Models\ProjectReportSubmission;
use App\Models\ProjectReportTemplate;
use App\Modules\WorkManagement\Application\ReportAccess;
use App\Modules\WorkManagement\Application\ReportDefinitions;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ReportView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveProjectReport
{
    public function handle(Access $actor, string $projectId, ?string $id, int $revision, string $templateId, string $periodId, string $title, string $summary = '', string $issues = '', string $correctiveAction = '', ?string $budgetId = null): ReportView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $templateId, $periodId, $title, $summary, $issues, $correctiveAction, $budgetId): ReportView {
            abort_unless($actor->hasModule(ModuleCode::PROJECTS), 403);
            $project = WorkRules::lockedProject($actor, $projectId);
            $row = $id === null ? null : ProjectReport::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            $template = ProjectReportTemplate::query()->where('project_id', $project->id)->whereKey($templateId)->lockForUpdate()->firstOrFail();
            $period = ProjectReportingPeriod::query()->where('project_id', $project->id)->whereKey($periodId)->lockForUpdate()->firstOrFail();
            $scope = ReportDefinitions::scope($template->definition);
            ReportAccess::locked($actor, $project->id, ($row->work ?? false) || $scope['work'], ($row->financial ?? false) || $scope['financial'], true);
            ReportingRules::revision($row, $revision);
            if (($row !== null && $row->status !== 'DRAFT') || ($row === null && ($template->archived || $period->archived))) {
                throw ValidationException::withMessages(['report' => 'Use an active template and period for a new draft, or start an amendment before editing a submitted report.']);
            }
            if ($row !== null && ProjectReportSubmission::query()->where('report_id', $row->id)->exists() && ($row->template_id !== $template->id || $row->period_id !== $period->id)) {
                throw ValidationException::withMessages(['report' => 'An amendment keeps the original template and period. Create a separate report for a different one.']);
            }
            Validator::make(['title' => trim($title), 'summary' => trim($summary), 'issues' => trim($issues), 'correctiveAction' => trim($correctiveAction)], [
                'title' => 'required|string|max:200', 'summary' => 'nullable|string|max:12000', 'issues' => 'nullable|string|max:12000', 'correctiveAction' => 'nullable|string|max:12000',
            ])->validate();
            $budget = $budgetId === null ? null : ProjectBudget::query()->where('project_id', $project->id)->whereIn('status', ['APPROVED', 'DRAFT'])->whereKey($budgetId)->first();
            if ($budgetId !== null && ($budget === null || ! $scope['financial'])) {
                throw ValidationException::withMessages(['budgetId' => 'Select a budget in this project for a financial report.']);
            }
            $row ??= new ProjectReport;
            $row->forceFill(['project_id' => $project->id, 'template_id' => $template->id, 'period_id' => $period->id, 'budget_id' => $budget?->id,
                'title' => trim($title), 'summary' => trim($summary) ?: null, 'issues' => trim($issues) ?: null, 'corrective_action' => trim($correctiveAction) ?: null,
                'work' => ($row->work ?? false) || $scope['work'], 'financial' => ($row->financial ?? false) || $scope['financial'], 'status' => 'DRAFT',
                'revision' => $revision + 1, 'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_REPORT_DRAFTED, 'projects', $project->id, 'Report draft saved');

            return Snapshot::of($row, ReportView::class);
        }, 3);
    }
}
