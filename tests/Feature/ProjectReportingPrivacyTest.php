<?php

declare(strict_types=1);

use App\Models\ProjectReportSubmission;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\SaveProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveReportField;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\SaveReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SubmitProjectReport;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\Queries\ProjectReportWorkbooks;
use App\Platform\Transfer\XlsxReader;
use App\Support\Access;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('captures only shared work planning and never auto copies individual response notes or private evidence', function () {
    $owner = cpaUser();
    $viewer = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$viewer, $worker]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    $task = cpaTask($owner, $project, [$employee], 'INDIVIDUAL', 'Shared plan');
    $response = new ProjectTaskSubmission;
    $response->forceFill(['task_id' => $task->id, 'employee_id' => $employee->id, 'status' => 'SUBMITTED', 'remarks' => 'PRIVATE INDIVIDUAL RESPONSE', 'submitted_at' => now(), 'revision' => 1])->save();
    $privateFile = new ProjectTaskFile;
    $privateFile->forceFill(['task_id' => $task->id, 'employee_id' => $employee->id, 'kind' => 'SUBMISSION', 'file_name' => 'PRIVATE_RESPONSE.pdf', 'mime' => 'application/pdf', 'size' => 10, 'path' => 'synthetic/private-response.pdf', 'uploaded_by' => $worker->id])->save();
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'Year', '2026-01-01', '2026-12-31');
    $template = app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, 'Work plan', 'FINAL_RESULTS', ['sections' => ['profile', 'work'], 'fields' => []]);
    $report = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'Shared report', 'Progress');
    $submission = app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $viewer->id);
    expect($submission->payload)->toContain('Shared plan')->not->toContain('PRIVATE INDIVIDUAL RESPONSE', $response->id, 'PRIVATE_RESPONSE.pdf', $privateFile->id)
        ->and(app(ProjectReports::class)->report(Access::forUser($worker), $project->id, $report->id))->toBeNull();
});

it('tightens old submission access when a captured custom field becomes financial without rewriting its contents', function () {
    $owner = cpaUser();
    $viewer = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$viewer]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    $field = app(SaveReportField::class)->handle($actor, $project->id, null, 0, 'Classification', 'REPORT', 'TEXT');
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'Year', '2026-01-01', '2026-12-31');
    $template = app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, 'Custom report', 'PERIOD_RESULTS', ['sections' => ['profile'], 'fields' => [$field->id]]);
    $report = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'Report', 'Progress');
    $otherDraft = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'Another draft', 'Progress');
    $submission = app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $viewer->id);
    app(SaveReportField::class)->handle($actor, $project->id, $field->id, 1, 'Classification', 'REPORT', 'TEXT', visibility: 'FINANCE', reason: 'Financial classification');
    expect(app(ProjectReports::class)->report(Access::forUser($viewer), $project->id, $report->id))->toBeNull()
        ->and(app(ProjectReports::class)->report(Access::forUser($viewer), $project->id, $otherDraft->id))->toBeNull()
        ->and(ProjectReportSubmission::query()->whereKey($submission->id)->sole()->financial)->toBeTrue()
        ->and(ProjectReportSubmission::query()->whereKey($submission->id)->sole()->payload)->toBe($submission->payload);
});

it('exports the saved report version with typed rows and no operational data outside its scope', function () {
    $owner = cpaUser();
    $viewer = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$viewer]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'Year', '2026-01-01', '2026-12-31');
    $template = app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, 'Profile', 'PERIOD_RESULTS', ['sections' => ['profile'], 'fields' => []]);
    $report = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'Report', 'FROZEN SUMMARY');
    $submission = app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $viewer->id);
    $project->forceFill(['name' => 'LATER PROJECT NAME'])->save();
    app(SaveReportTemplate::class)->handle($actor, $project->id, $template->id, 1, 'Future financial template', 'FINANCIAL', ['sections' => ['profile', 'cash'], 'fields' => []], reason: 'Use financial sections in later versions');
    expect(app(ProjectReports::class)->report(Access::forUser($viewer), $project->id, $report->id))->not->toBeNull();
    $response = app(ProjectReportWorkbooks::class)->download($actor, $project->id, $report->id, $submission->id);
    $path = tempnam(sys_get_temp_dir(), 'report-export-test-');
    try {
        file_put_contents($path, $response->getContent());
        $reader = new XlsxReader;
        $sheet = $reader->read($path, 'Summary', 4);
        expect(array_column($sheet['sheet']->rows, 'cells'))->toContain(['Summary', 'FROZEN SUMMARY']);
        $profile = $reader->read($path, 'Profile', 4);
        expect($profile['sheet']->rows[0]['cells'][1])->not->toBe('LATER PROJECT NAME');
    } finally {
        unlink($path);
    }
});
