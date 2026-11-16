<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Modules\WorkManagement\Application\ReportWorkbook;
use App\Support\Access;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class ProjectReportWorkbooks
{
    public function download(Access $actor, string $projectId, string $reportId, ?string $submissionId = null): Response
    {
        $query = app(ProjectReports::class);
        $report = $query->report($actor, $projectId, $reportId);
        abort_if($report === null, 404);
        if ($submissionId === null && $report->status !== 'DRAFT') {
            $submissionId = $report->current_submission_id;
        }
        $submission = $submissionId === null ? null : $query->submission($actor, $projectId, $submissionId);
        abort_unless($submission === null || $submission->report_id === $report->id, 404);
        $data = $submission->snapshot ?? $query->preview($actor, $projectId, $reportId);
        $version = $submission === null ? 'DRAFT' : 'Version '.$submission->version;
        $bytes = app(ReportWorkbook::class)->bytes($data, $version, $submission?->sha256);
        $name = (Str::slug($data['title']) ?: 'project-report').'-'.Str::slug($version).'.xlsx';

        return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
