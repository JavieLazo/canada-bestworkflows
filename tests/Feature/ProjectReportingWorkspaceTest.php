<?php

declare(strict_types=1);

use App\Livewire\Projects\ProjectReportingWorkspace;
use App\Livewire\Projects\RecordFields;
use App\Models\ProjectIndicator;
use App\Models\ProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveReportField;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\UseGeneralReportTemplates;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('creates and records a result from the shared reporting interface', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $ui = Livewire::test(ProjectReportingWorkspace::class, ['projectId' => $project->id])->assertSet('section', 'reports')->assertSee('New report')
        ->set('section', 'results')->assertSee('New indicator')->call('openIndicator')
        ->set('form.name', 'Restored sites')->set('form.unit', 'sites')->set('form.target', '10')->call('save')->assertHasNoErrors()->assertSee('Restored sites');
    $indicator = ProjectIndicator::query()->sole();
    $ui->call('openResult', $indicator->id)->set('form.value', '2')->set('form.narrative', 'Completed work')->call('save')->assertHasNoErrors()->assertSee('2 sites')->assertSee('Completed work');
    $ui->call('openIndicator', $indicator->id)->set('form.target', '12')->set('form.reason', 'Expanded delivery')->call('save')->assertHasNoErrors();
    expect($indicator->fresh()->plan_version)->toBe(2);
});

it('previews a generic report and opens its typed template and field forms', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(UseGeneralReportTemplates::class)->handle($actor, $project->id);
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'November', '2026-11-01', '2026-11-30');
    $template = app(ProjectReports::class)->templates($actor, $project->id)->firstWhere('kind', 'PERIOD_RESULTS');
    cpaLogin($owner);
    $ui = Livewire::test(ProjectReportingWorkspace::class, ['projectId' => $project->id])->set('section', 'reports')->call('openReport')
        ->set('form.title', 'Progress report')->set('form.template', $template->id)->set('form.period', $period->id)->set('form.summary', 'Completed this period')
        ->call('save')->assertHasNoErrors()->assertSee('Live draft')->assertSee('Completed this period');
    expect(ProjectReport::query()->count())->toBe(1);
    $ui->set('section', 'settings')->call('openField')->set('form.name', 'Approval reference')->call('save')->assertHasNoErrors()->assertSee('Approval reference')
        ->call('openTemplate', $template->id)->assertSee('Additional columns')->call('openPeriod', $period->id)->assertSee('Report due');
});

it('opens reports with choice fields and preserves their choices when editing', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(UseGeneralReportTemplates::class)->handle($actor, $project->id);
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'November', '2026-11-01', '2026-11-30');
    $template = app(ProjectReports::class)->templates($actor, $project->id)->firstWhere('kind', 'PERIOD_RESULTS');
    $field = app(SaveReportField::class)->handle($actor, $project->id, null, 0, 'Delivery confidence', 'REPORT', 'CHOICE', choices: ['On track', 'Needs attention']);
    cpaLogin($owner);
    $page = Livewire::test(ProjectReportingWorkspace::class, ['projectId' => $project->id])->call('openReport')
        ->set('form.title', 'Choice field report')->set('form.template', $template->id)->set('form.period', $period->id)
        ->call('save')->assertHasNoErrors()->assertSee('Delivery confidence');
    $report = ProjectReport::query()->sole();
    $page->call('openReport', $report->id)->assertSet('reportId', $report->id)
        ->call('openField', $field->id)->assertSet('form.options', "On track\nNeeds attention");
    Livewire::test(RecordFields::class, ['projectId' => $project->id, 'entity' => 'REPORT', 'recordId' => $report->id])
        ->assertSee('On track')->assertSee('Needs attention')->call('edit')->set('answers.'.$field->id, 'Needs attention')->call('save')->assertHasNoErrors()->assertSee('Needs attention')
        ->call('edit')->assertSet('answers.'.$field->id, 'Needs attention');
});
