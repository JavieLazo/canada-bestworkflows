<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectReport;
use App\Models\ProjectReportSubmission;
use App\Models\ProjectReportTemplate;
use App\Modules\WorkManagement\Application\ReportAccess;
use App\Modules\WorkManagement\Application\ReportDefinitions;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AmendProjectReport
{
    public function handle(Access $actor, string $projectId, string $id, int $revision, string $reason): void
    {
        DB::transaction(static function () use ($actor, $projectId, $id, $revision, $reason): void {
            $project = WorkRules::lockedProject($actor, $projectId);
            $report = ProjectReport::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $template = ProjectReportTemplate::query()->where('project_id', $project->id)->whereKey($report->template_id)->lockForUpdate()->firstOrFail();
            $scope = ReportDefinitions::scope($template->definition);
            ReportAccess::locked($actor, $project->id, $report->work || $scope['work'], $report->financial || $scope['financial'], true);
            ReportingRules::revision($report, $revision);
            if (! in_array($report->status, ['RETURNED', 'ACCEPTED'], true)) {
                throw ValidationException::withMessages(['report' => 'An amendment starts from a returned or accepted report.']);
            }
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:2000'])->validate();
            $report->forceFill(['status' => 'DRAFT', 'work' => $report->work || $scope['work'], 'financial' => $report->financial || $scope['financial'], 'amendment_reason' => trim($reason), 'revision' => $revision + 1, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_REPORT_DRAFTED, 'projects', $project->id, 'Report amendment started');
        }, 3);
    }

    public function reassign(Access $actor, string $projectId, string $id, int $revision, int $reviewerId, string $reason): void
    {
        DB::transaction(static function () use ($actor, $projectId, $id, $revision, $reviewerId, $reason): void {
            $project = ReportingRules::locked($actor, $projectId, admin: true);
            $report = ProjectReport::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            ReportAccess::locked($actor, $project->id, $report->work, $report->financial, false);
            ReportingRules::revision($report, $revision);
            if ($report->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['report' => 'Only a report awaiting review can change reviewer.']);
            }
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:2000'])->validate();
            $submission = ProjectReportSubmission::query()->where('report_id', $report->id)->whereKey($report->current_submission_id)->lockForUpdate()->firstOrFail();
            ReportAccess::reviewer($actor, $report, $reviewerId, $submission->submitted_by);
            $history = $submission->reviewer_history ?? [];
            $history[] = ['reviewer_id' => $submission->reviewer_id, 'by' => (int) $actor->userId, 'at' => now()->toIso8601String(), 'reason' => trim($reason)];
            $submission->forceFill(['reviewer_id' => $reviewerId, 'reviewer_history' => $history])->save();
            $report->forceFill(['revision' => $revision + 1, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_REPORT_REVIEWED, 'projects', $project->id, 'Report reviewer reassigned');
            DB::afterCommit(static fn () => ReportAccess::notify($reviewerId, $report, 'PROJECT_REPORT_SUBMITTED', 'Report ready for review'));
        }, 3);
    }
}
