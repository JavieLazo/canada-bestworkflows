<?php

declare(strict_types=1);

use App\Models\ProjectRecordValue;
use App\Models\ProjectReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SaveProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveRecordValues;
use App\Modules\WorkManagement\Application\Commands\SaveReportField;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\SaveReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\UseGeneralReportTemplates;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\ReportDefinitions;
use App\Modules\WorkManagement\Application\ReportFieldValues;
use App\Support\Access;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('creates general templates on demand without fixed grants periods or operational seed data', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(UseGeneralReportTemplates::class)->handle($actor, $project->id);
    app(UseGeneralReportTemplates::class)->handle($actor, $project->id);
    expect(ProjectReportTemplate::query()->count())->toBe(4);
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'Project-defined period', '2026-09-10', '2026-11-02', '2026-11-20');
    expect($period->starts_on)->toBe('2026-09-10')->and($period->ends_on)->toBe('2026-11-02');
});

it('stores typed fields once protects values and versions template labels without altering core records', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $number = app(SaveReportField::class)->handle($actor, $project->id, null, 0, 'Coverage', 'PROJECT', 'NUMBER', 'hectares', required: true, total: true);
    $money = app(SaveReportField::class)->handle($actor, $project->id, null, 0, 'Matching value', 'PROJECT', 'MONEY');
    expect($money->visibility)->toBe('FINANCE');
    $fields = ReportFieldValues::definitions($actor, $project->id, 'PROJECT');
    $signature = ReportFieldValues::signature($fields, new Collection);
    $before = $project->getAttributes();
    app(SaveRecordValues::class)->handle($actor, $project->id, 'PROJECT', $project->id, $signature, [$number->id => '12.3456', $money->id => '500.25']);
    expect(ProjectRecordValue::query()->where('field_id', $number->id)->sole()->value)->toBe(['value' => 123456])
        ->and($project->fresh()->getAttributes())->toBe($before);
    expect(fn () => app(SaveRecordValues::class)->handle($actor, $project->id, 'PROJECT', $project->id, $signature, [$number->id => '2']))->toThrow(ValidationException::class);
    expect(fn () => app(SaveReportField::class)->handle($actor, $project->id, $number->id, 1, 'Coverage', 'PROJECT', 'TEXT', reason: 'New meaning'))->toThrow(ValidationException::class);
    $template = app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, 'Custom period report', 'PERIOD_RESULTS', ['sections' => ['profile', 'results'], 'fields' => [$number->id]]);
    app(SaveReportTemplate::class)->handle($actor, $project->id, $template->id, 1, 'Renamed report', 'PERIOD_RESULTS', ['sections' => ['profile', 'results'], 'fields' => [$number->id]], reason: 'Clearer label');
    expect(ProjectReportTemplate::query()->sole()->history[0]['values']['name'])->toBe('Custom period report');
    app(SaveReportTemplate::class)->handle($actor, $project->id, $template->id, 2, 'Renamed report', 'PERIOD_RESULTS', ['sections' => ['profile', 'results'], 'fields' => [$money->id, $number->id]], reason: 'Put matching value first');
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'Year', '2026-01-01', '2026-12-31');
    $report = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'Ordered report', 'Progress');
    $preview = app(ProjectReports::class)->preview($actor, $project->id, $report->id);
    expect(array_column($preview['fields'], 'id'))->toBe([$money->id, $number->id]);
});

it('rejects cross-project fields and arbitrary section expressions instead of executing formulas', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $other = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $field = app(SaveReportField::class)->handle($actor, $other->id, null, 0, 'Foreign', 'PROJECT', 'TEXT');
    expect(fn () => ReportDefinitions::validate($project->id, ['sections' => ['profile'], 'fields' => [$field->id]]))->toThrow(ValidationException::class);
    expect(fn () => ReportDefinitions::validate($project->id, ['sections' => ['eval(1)'], 'fields' => []]))->toThrow(ValidationException::class);
});
