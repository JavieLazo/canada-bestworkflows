<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectReport;
use App\Models\ProjectReportSubmission;
use App\Modules\WorkManagement\Application\ReportAccess;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReviewProjectReport
{
    public function handle(Access $actor, string $projectId, string $id, string $submissionId, int $revision, string $decision, string $notes = ''): void
    {
        DB::transaction(static function () use ($actor, $projectId, $id, $submissionId, $revision, $decision, $notes): void {
            $project = WorkRules::lockedProject($actor, $projectId);
            $report = ProjectReport::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            ReportAccess::locked($actor, $project->id, $report->work, $report->financial, false);
            ReportingRules::revision($report, $revision);
            $submission = ProjectReportSubmission::query()->where('report_id', $report->id)->whereKey($submissionId)->lockForUpdate()->firstOrFail();
            abort_unless($submission->reviewer_id === (int) $actor->userId && $submission->submitted_by !== (int) $actor->userId
                && ReportAccess::reviewers($actor, $report, true)->contains('id', (int) $actor->userId), 403);
            if ($report->status !== 'SUBMITTED' || $report->current_submission_id !== $submission->id || $submission->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['report' => 'This submission has already been reviewed or superseded. Refresh the report.']);
            }
            Validator::make(['decision' => $decision, 'notes' => trim($notes)], ['decision' => 'required|in:ACCEPTED,RETURNED', 'notes' => ($decision === 'RETURNED' ? 'required' : 'nullable').'|string|max:4000'])->validate();
            abort_unless(hash_equals($submission->sha256, hash('sha256', $submission->payload)), 409, 'The saved submission failed its integrity check.');
            $submission->forceFill(['status' => $decision, 'reviewed_by' => (int) $actor->userId, 'reviewed_at' => now(), 'review_notes' => trim($notes) ?: null])->save();
            $report->forceFill(['status' => $decision, 'revision' => $revision + 1, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_REPORT_REVIEWED, 'projects', $project->id, 'Report version '.$submission->version.' '.strtolower($decision));
            DB::afterCommit(static fn () => ReportAccess::notify($submission->submitted_by, $report, 'PROJECT_REPORT_REVIEWED', 'Report '.strtolower($decision)));
        }, 3);
    }
}
