<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ProjectRecordFile;
use App\Models\ProjectReport;
use App\Models\ProjectReportSubmission;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\AmendProjectReport;
use App\Modules\WorkManagement\Application\Commands\AttachRecordEvidence;
use App\Modules\WorkManagement\Application\Commands\RecordResultEntry;
use App\Modules\WorkManagement\Application\Commands\RemoveRecordEvidence;
use App\Modules\WorkManagement\Application\Commands\ReviewProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveIndicator;
use App\Modules\WorkManagement\Application\Commands\SaveProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\SaveReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SubmitProjectReport;
use App\Modules\WorkManagement\Application\IndicatorDraft;
use App\Modules\WorkManagement\Application\Queries\ProjectRecordFiles;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\ResultEntryDraft;
use App\Modules\WorkManagement\Contracts\Views\ReportView;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

/** @return array{User, User, Project, Access, ReportView} */
function reportingFixture(): array
{
    $owner = cpaUser();
    $reviewer = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$reviewer]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $reviewer->id, 'VIEWER');
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'November', '2026-11-01', '2026-11-30', '2026-12-05');
    $template = app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, 'Progress', 'PERIOD_RESULTS', ['sections' => ['profile', 'results'], 'fields' => []]);
    $report = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'November progress', 'Recorded progress');

    return [$owner, $reviewer, $project, $actor, $report];
}

it('preserves submitted values and their raw integrity through live edits review and an amendment', function () {
    [$owner, $reviewer, $project, $actor, $report] = reportingFixture();
    $indicator = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Sites restored', unit: 'sites', target: '12'));
    app(RecordResultEntry::class)->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-02', '3'));
    $submission = app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $reviewer->id);
    expect($submission->snapshot['sections']['results'][0]['actual_scaled'])->toBe(30000)
        ->and(hash('sha256', $submission->payload))->toBe($submission->sha256);
    app(RecordResultEntry::class)->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-03', '2'));
    expect(app(ProjectReports::class)->submission($actor, $project->id, $submission->id)->snapshot['sections']['results'][0]['actual_scaled'])->toBe(30000);
    app(ReviewProjectReport::class)->handle(Access::forUser($reviewer), $project->id, $report->id, $submission->id, 2, 'ACCEPTED', 'Checked');
    app(AmendProjectReport::class)->handle($actor, $project->id, $report->id, 3, 'Additional completed sites');
    $second = app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 4, $reviewer->id);
    expect($second->version)->toBe(2)->and($second->snapshot['sections']['results'][0]['actual_scaled'])->toBe(50000)
        ->and($second->snapshot['amendment_reason'])->toBe('Additional completed sites')
        ->and(ProjectReportSubmission::query()->findOrFail($submission->id)->payload)->toBe($submission->payload)
        ->and(ProjectReportSubmission::query()->findOrFail($submission->id)->status)->toBe('ACCEPTED');
});

it('requires another current reviewer prevents stale self review and retains reassignment history', function () {
    [$owner, $reviewer, $project, $actor, $report] = reportingFixture();
    expect(fn () => app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $owner->id))->toThrow(ValidationException::class);
    $submission = app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $reviewer->id);
    expect(fn () => app(ReviewProjectReport::class)->handle($actor, $project->id, $report->id, $submission->id, 2, 'ACCEPTED'))->toThrow(HttpException::class);
    expect(fn () => app(ReviewProjectReport::class)->handle(Access::forUser($reviewer), $project->id, $report->id, $submission->id, 1, 'ACCEPTED'))->toThrow(ValidationException::class);
    app(SetProjectMember::class)->handle($actor, $project->id, $reviewer->id, null);
    expect(fn () => app(ReviewProjectReport::class)->handle(Access::forUser($reviewer), $project->id, $report->id, $submission->id, 2, 'ACCEPTED'))->toThrow(HttpException::class);
    $next = cpaUser();
    app(SetClientMember::class)->handle($actor, $project->client_id, $next->id, true);
    app(SetProjectMember::class)->handle($actor, $project->id, $next->id, 'VIEWER');
    app(AmendProjectReport::class)->reassign($actor, $project->id, $report->id, 2, $next->id, 'Current reviewer changed');
    expect(ProjectReportSubmission::query()->findOrFail($submission->id)->reviewer_history[0]['reviewer_id'])->toBe($reviewer->id);
    expect(fn () => app(ReviewProjectReport::class)->handle(Access::forUser($next), $project->id, $report->id, $submission->id, 3, 'RETURNED'))->toThrow(ValidationException::class);
    app(ReviewProjectReport::class)->handle(Access::forUser($next), $project->id, $report->id, $submission->id, 3, 'RETURNED', 'Explain the result');
    expect(ProjectReport::query()->whereKey($report->id)->sole()->status)->toBe('RETURNED');
});

it('deduplicates evidence bytes keeps required proof and freezes its identity in submissions', function () {
    Storage::fake('local');
    [$owner, $reviewer, $project, $actor, $report] = reportingFixture();
    $indicator = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Verified result', unit: 'sites', evidenceRequired: true));
    $entry = app(RecordResultEntry::class)->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-02', '1'));
    expect(fn () => app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $reviewer->id))->toThrow(ValidationException::class);
    $image = UploadedFile::fake()->image('proof.png');
    $id = app(AttachRecordEvidence::class)->handle($actor, $project->id, 'RESULT_ENTRY', $entry, $image);
    $reportFile = app(AttachRecordEvidence::class)->handle($actor, $project->id, 'REPORT', $report->id, $image);
    expect(ProjectRecordFile::query()->whereKey($id)->sole()->path)->toBe(ProjectRecordFile::query()->whereKey($reportFile)->sole()->path);
    $submission = app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $reviewer->id);
    expect(ProjectRecordFile::query()->whereKey($id)->sole()->retained_at)->not->toBeNull();
    app(RemoveRecordEvidence::class)->handle($actor, $project->id, $id);
    expect(app(ProjectRecordFiles::class)->file($actor, $id))->toBeNull()
        ->and(array_column($submission->snapshot['files'], 'id'))->toContain($id);
    Storage::disk('local')->assertExists(ProjectRecordFile::query()->whereKey($id)->sole()->path);
});

it('detects tampered submission payloads and keeps report-private answers out of live task responses', function () {
    [$owner, $reviewer, $project, $actor, $report] = reportingFixture();
    $submission = app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $reviewer->id);
    ProjectReportSubmission::query()->whereKey($submission->id)->update(['payload' => '{}']);
    expect(fn () => app(ProjectReports::class)->submission($actor, $project->id, $submission->id))->toThrow(HttpException::class);
});
