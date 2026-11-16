<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectRecordFile;
use App\Models\ProjectReport;
use App\Models\ProjectReportSubmission;
use App\Modules\WorkManagement\Application\ReportAccess;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Application\ReportSnapshotData;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ReportSubmissionView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitProjectReport
{
    public function handle(Access $actor, string $projectId, string $id, int $revision, int $reviewerId): ReportSubmissionView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $reviewerId): ReportSubmissionView {
            $project = WorkRules::lockedProject($actor, $projectId);
            $report = ProjectReport::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            ReportAccess::locked($actor, $project->id, $report->work, $report->financial, true);
            ReportingRules::revision($report, $revision);
            if ($report->status !== 'DRAFT') {
                throw ValidationException::withMessages(['report' => 'This report has already left Draft. Refresh to see its current review.']);
            }
            $data = app(ReportSnapshotData::class)->build($actor, $report);
            if (trim($report->summary ?? '') === '' || $data['coverage']['blocking'] !== []) {
                throw ValidationException::withMessages(['report' => 'Add a summary and complete the required answers and evidence shown in the preview.']);
            }
            $report->forceFill(['work' => $data['scope']['work'], 'financial' => $data['scope']['financial']]);
            ReportAccess::reviewer($actor, $report, $reviewerId);
            $last = ProjectReportSubmission::query()->where('report_id', $report->id)->orderByDesc('version')->lockForUpdate()->first();
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $submission = new ProjectReportSubmission;
            $submission->forceFill(['project_id' => $project->id, 'report_id' => $report->id, 'version' => ($last->version ?? 0) + 1,
                'template_version' => $data['template']['revision'], 'work' => $report->work, 'financial' => $report->financial,
                'field_ids' => array_column($data['fields'], 'id'), 'payload' => $payload, 'sha256' => hash('sha256', $payload), 'status' => 'SUBMITTED',
                'submitted_by' => (int) $actor->userId, 'reviewer_id' => $reviewerId, 'reviewer_history' => []])->save();
            foreach (array_chunk(array_column($data['files'], 'id'), 500) as $ids) {
                ProjectRecordFile::query()->where('project_id', $project->id)->whereKey($ids)->whereNull('retained_at')->update(['retained_at' => now()]);
            }
            $report->forceFill(['status' => 'SUBMITTED', 'current_submission_id' => $submission->id, 'revision' => $revision + 1, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_REPORT_SUBMITTED, 'projects', $project->id, 'Report version '.$submission->version.' submitted');
            DB::afterCommit(static fn () => ReportAccess::notify($reviewerId, $report, 'PROJECT_REPORT_SUBMITTED', 'Report ready for review'));

            return Snapshot::of($submission, ReportSubmissionView::class, ['snapshot' => $data]);
        }, 3);
    }
}
