<?php

declare(strict_types=1);

use App\Livewire\Projects\FinanceOverview;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\NewClient;
use App\Livewire\Projects\ProjectFinanceWorkspace;
use App\Livewire\Projects\ReportingOverview;
use App\Livewire\Projects\Show;
use App\Models\ProjectBudget;
use App\Models\ProjectClient;
use App\Models\ProjectCostCategory;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\ApproveProjectBudget;
use App\Modules\WorkManagement\Application\Commands\ArchivePortfolio;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SaveCostCategory;
use App\Modules\WorkManagement\Application\Commands\SaveIndicator;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Modules\WorkManagement\Application\Commands\SaveProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\SaveReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Modules\WorkManagement\Application\IndicatorDraft;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-04T12:00:00+08:00'));
});

it('keeps creation choices appropriate to the project section', function () {
    $owner = cpaUser();
    cpaClient($owner);
    cpaLogin($owner);
    $page = Livewire::test(Index::class)->assertSeeHtml('aria-label="More creation actions"')->assertSeeHtml('wire:click="openCreateProject"');
    $page->set('section', 'projects')->assertSeeHtml('wire:click="openCreateProject"')->assertDontSeeHtml('aria-label="More creation actions"');
    $page->set('section', 'capacity')->assertDontSeeHtml('aria-label="Create project work"')
        ->assertDontSeeHtml('aria-label="Project tools"')->assertDontSeeHtml('aria-label="More creation actions"');
});

it('offers a clear first client action instead of an empty creation arrow', function () {
    $owner = cpaUser();
    cpaLogin($owner);
    Livewire::test(Index::class)->assertDontSeeHtml('wire:click="openCreateProject"')
        ->assertSeeHtml('wire:click="$dispatch(\'new-project-client\')"')->assertSeeHtml('aria-label="More creation actions"');
});

it('keeps client creation shortcuts out of a client project workspace', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    cpaLogin($owner);
    Livewire::test(Index::class)->set('client', $client->id)->assertSeeHtml('wire:click="openCreateProject"')
        ->assertDontSeeHtml('aria-label="More creation actions"');
});

it('keeps portfolio and client identity in the heading breadcrumbs and section links', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Coastal programmes');
    $client = app(SaveClient::class)->handle($actor, null, 'Greenfields', $portfolio->id);
    $project = cpaProject($owner, $client, 'Restoration');
    cpaLogin($owner);
    $this->get(route('projects.index', ['portfolio' => $portfolio->id]))->assertOk()
        ->assertSeeHtml('<h1 class="ppm-heading break-words">Coastal programmes</h1>');
    $this->get(route('projects.index', ['client' => $client->id]))->assertOk()
        ->assertSeeHtml('<h1 class="ppm-heading break-words">Greenfields</h1>')
        ->assertSee(route('projects.index', ['portfolio' => $portfolio->id]), false)
        ->assertSee(route('projects.index', ['portfolio' => $portfolio->id, 'client' => $client->id, 'section' => 'capacity']));
    $this->get(route('projects.show', $project->id))->assertOk()->assertSeeInOrder(['Coastal programmes', 'Greenfields', $project->code]);
    Livewire::test(Index::class)->set('portfolio', 'NONE')->set('client', $client->id)
        ->assertSee('This client is unavailable in this context.');
});

it('uses scoped breadcrumbs without a context selector and still refuses hidden destinations', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    $client = app(SaveClient::class)->handle($actor, null, 'Visible client', $portfolio->id);
    $hidden = cpaClient(cpaUser());
    cpaLogin($owner);
    $this->get(route('projects.index', ['portfolio' => $portfolio->id, 'client' => $client->id, 'section' => 'capacity']))
        ->assertOk()->assertSee('Visible client')->assertSee('Delivery')->assertDontSee('Switch work context');
    $this->get(route('projects.index', ['client' => $hidden->id]))->assertOk()
        ->assertSee('This client is unavailable in this context.')->assertDontSee($hidden->name);
});

it('creates a client in place with its current portfolio and redirects only after saving', function () {
    $owner = cpaUser();
    $portfolio = app(SavePortfolio::class)->handle(Access::forUser($owner), null, 'Delivery');
    cpaLogin($owner);
    $page = Livewire::test(NewClient::class, ['portfolio' => $portfolio->id])->dispatch('new-project-client')
        ->assertSet('editorOpen', true)->assertSet('draftPortfolioId', $portfolio->id)->assertNoRedirect();
    $page->set('name', 'New coastal client')->call('save')->assertHasNoErrors();
    $client = ProjectClient::query()->sole();
    $page->assertRedirect(route('projects.index', ['client' => $client->id, 'portfolio' => $portfolio->id]));
    expect($client->portfolio_id)->toBe($portfolio->id);
    app(ArchivePortfolio::class)->handle(Access::forUser($owner), $portfolio->id, true);
    Livewire::test(NewClient::class, ['portfolio' => $portfolio->id])->call('open')->assertForbidden();
});

it('retains deep linked task layouts and restores the last layout when returning to Tasks', function (string $layout) {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $this->get(route('projects.show', ['project' => $project->id, 'view' => $layout]))->assertOk()->assertSeeHtml('aria-label="Task view"');
    Livewire::test(Show::class, ['project' => $project->id])->set('taskView', $layout)
        ->set('taskView', 'overview')->call('openTasks')->assertSet('taskView', $layout)
        ->assertSeeHtml('aria-label="Task view"');
})->with(['board', 'list', 'gantt']);

it('shows each project summary under its own read permission without requiring finance access', function () {
    $owner = cpaUser();
    $employee = cpaEmployee('Planner');
    $planner = cpaUser($employee);
    $project = cpaProject($owner, cpaClient($owner, [$planner]));
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $planner->id, 'EDITOR');
    cpaTask($owner, $project, [$employee]);
    app(SetupProjectFinance::class)->handle(Access::forUser($owner), $project->id, 'PHP');
    app(SaveIndicator::class)->handle(Access::forUser($owner), $project->id, null, 0, new IndicatorDraft('Delivered sites', unit: 'sites', target: '10'));
    cpaLogin($planner);

    $this->get(route('projects.show', $project->id))->assertOk()
        ->assertSeeHtml('aria-label="Project summaries"')
        ->assertSeeHtml('aria-label="Availability summary"')->assertSeeHtml('aria-label="Results and reporting overview"')
        ->assertDontSeeHtml('aria-label="Visible project finance in PHP"');
});

it('opens filtered finance records and exports the same chart sources without rewriting history', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $general = ProjectCostCategory::query()->sole()->id;
    $travel = app(SaveCostCategory::class)->handle($actor, $project->id, null, 'Travel');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('General approved cost', $general, '100'));
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 2, null, new BudgetLineDraft('Travel approved cost', $travel, '40'));
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 3);
    app(StartProjectBudget::class)->handle($actor, $project->id, 'Later working plan');
    foreach ([['2026-10-15', 'October travel', $travel], ['2026-11-03', 'November travel', $travel], ['2026-11-03', 'November general', $general], ['2026-11-04', 'Today travel', $travel]] as [$date, $description, $category]) {
        app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('EXPENSE', $date, $description, '10', $category));
    }
    $before = ProjectBudget::query()->orderBy('id')->get()->toJson();
    cpaLogin($owner);
    $page = Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id]);
    $page->call('showBudget', $travel)->assertSet('section', 'budget')->assertSet('budgetChoice', $budget)
        ->assertViewHas('lines', fn ($lines): bool => $lines->total() === 1 && $lines->first()->label === 'Travel approved cost');
    $page->call('showCashMonth', '2026-10', 'EXPENSE')->assertSet('cashFrom', '2026-10-01')->assertSet('cashTo', '2026-10-31')
        ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 1 && $entries->first()->description === 'October travel');
    $page->call('showCashMonth', '2026-11', 'EXPENSE')->assertSet('cashTo', '2026-11-04')
        ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 3 && $entries->first()->description === 'Today travel');
    $page->call('showCashCategory', $travel)->assertSet('cashFrom', '')->assertSet('cashTo', '2026-11-04')
        ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 3);
    $cash = $this->get(route('projects.finance.export', ['project' => $project->id, 'kind' => 'cash', 'type' => 'EXPENSE', 'from' => '2026-10-01', 'to' => '2026-10-31', 'category' => $travel]))->assertOk()->streamedContent();
    expect($cash)->toContain('October travel')->not->toContain('November travel')->not->toContain('November general');
    $costs = $this->get(route('projects.finance.export', ['project' => $project->id, 'budget' => $budget, 'category' => $travel]))->assertOk()->streamedContent();
    expect($costs)->toContain('Travel approved cost')->not->toContain('General approved cost')
        ->and(ProjectBudget::query()->orderBy('id')->get()->toJson())->toBe($before);
    $page->call('showCashMonth', '2025-01', 'EXPENSE')->assertNotFound();
    $this->getJson(route('projects.finance.export', ['project' => $project->id, 'kind' => 'cash', 'from' => 'invalid']))->assertUnprocessable();
});

it('pages chart sources within current currency portfolio client and financial access', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Visible portfolio');
    $client = app(SaveClient::class)->handle($actor, null, 'Visible client', $portfolio->id);
    $visible = cpaProject($owner, $client, 'Visible PHP project');
    $other = cpaProject($owner, cpaClient($owner), 'Other context');
    app(SetupProjectFinance::class)->handle($actor, $visible->id, 'PHP');
    app(SetupProjectFinance::class)->handle($actor, $other->id, 'USD');
    foreach ([$visible, $other] as $project) {
        app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Delivered sites', unit: 'sites', target: '10'));
    }
    $period = app(SaveReportingPeriod::class)->handle($actor, $visible->id, null, 0, 'November', '2026-11-01', '2026-11-30');
    $template = app(SaveReportTemplate::class)->handle($actor, $visible->id, null, 0, 'Results', 'PERIOD_RESULTS', ['sections' => ['profile', 'results'], 'fields' => []]);
    app(SaveProjectReport::class)->handle($actor, $visible->id, null, 0, $template->id, $period->id, 'November results');
    cpaLogin($owner);
    foreach ([FinanceOverview::class, ReportingOverview::class] as $component) {
        $page = Livewire::test($component, ['portfolio' => $portfolio->id, 'client' => $client->id]);
        $page->call('openSource', ...($component === FinanceOverview::class ? ['PHP'] : ['funding', 'PHP']))
            ->assertSet('sourcesOpen', true)->assertViewHas('sourceProjects', fn ($projects): bool => $projects->pluck('id')->all() === [$visible->id]);
    }
    foreach (['results', 'reports'] as $kind) {
        Livewire::test(ReportingOverview::class, ['portfolio' => $portfolio->id, 'client' => $client->id])->call('openSource', $kind)
            ->assertViewHas('sourceProjects', fn ($projects): bool => $projects->pluck('id')->all() === [$visible->id]);
    }
    cpaLogin(cpaUser(allData: true));
    Livewire::test(FinanceOverview::class)->call('openSource', 'PHP')->assertViewHas('sourceProjects', fn ($projects): bool => $projects->total() === 0);
    Livewire::test(ReportingOverview::class)->call('openSource', 'reports')->assertViewHas('sourceProjects', fn ($projects): bool => $projects->total() === 0);
});
