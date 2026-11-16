<?php

declare(strict_types=1);

use App\Livewire\Projects\ProjectFinanceWorkspace;
use App\Livewire\Projects\ProjectFundingWorkspace;
use App\Livewire\Projects\ProjectReportingWorkspace;
use App\Livewire\Projects\Show;
use App\Models\ProjectBudget;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\CommentOnProjectTask;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SaveFunding;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('sorts exact finance amounts without rewriting records and keeps the read scope closed', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $query = app(ProjectFinancials::class);
    $category = $query->categories($actor, $project->id)->first()->id;
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    foreach (['100.01', '2.02', '10.10'] as $index => $amount) {
        app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, $index + 1, null, new BudgetLineDraft('Item '.$index, $category, $amount));
        app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('EXPENSE', '2026-11-01', 'Entry '.$index, $amount, $category));
    }
    expect($query->lines($actor, $project->id, $budget, sort: 'amount')->pluck('total_minor')->all())->toBe([202, 1010, 10001]);
    expect($query->entries($actor, $project->id, '', 'ALL', false, 1, 20, sort: 'amount', direction: 'asc')->pluck('amount_minor')->all())->toBe([202, 1010, 10001]);
    $foreign = Access::forUser(cpaUser());
    expect($query->lines($foreign, $project->id, $budget, sort: 'amount')->total())->toBe(0);
    expect($query->entries($foreign, $project->id, '', 'ALL', true, 1, 20, sort: 'amount')->total())->toBe(0);
    cpaLogin($owner);
    $page = Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id]);
    $page->set('section', 'budget')->call('sortBy', 'amount')->assertViewHas('lines', fn ($rows): bool => $rows->pluck('total_minor')->all() === [202, 1010, 10001]);
    $page->set('sortKey', 'amount; DROP TABLE projects')->call('$refresh')->assertHasNoErrors();
    expect(ProjectBudget::query()->sole()->revision)->toBe(4)->and($project->fresh()->name)->toBe('Website');
});

it('filters and sorts funding records with an effective row-size control', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    foreach (range(1, 75) as $number) {
        app(SaveFunding::class)->handle($actor, $project->id, null, 0, sprintf('Funding %02d', $number), 'Partner', (string) $number);
    }
    cpaLogin($owner);
    $page = Livewire::test(ProjectFundingWorkspace::class, ['projectId' => $project->id]);
    $page->assertViewHas('fundings', fn ($rows): bool => $rows->count() === 20 && $rows->total() === 75);
    $page->call('gotoPage', 3, 'fundingPage')->assertSet('paginators.fundingPage', 3);
    $page->set('perPage', 50)->assertSet('paginators.fundingPage', 1)->call('sortBy', 'committed')->call('sortBy', 'committed')->assertViewHas('fundings', fn ($rows): bool => $rows->count() === 50 && $rows->first()->committed_minor === 7500);
    $page->set('search', 'Funding 02')->assertViewHas('fundings', fn ($rows): bool => $rows->total() === 1);
});

it('searches and orders activity only within accessible tasks', function () {
    $owner = cpaUser(cpaEmployee('Owner'));
    $viewer = cpaUser(cpaEmployee('Viewer'));
    $hidden = cpaUser(cpaEmployee('Hidden'));
    $project = cpaProject($owner, cpaClient($owner, [$viewer, $hidden]));
    $visible = cpaTask($owner, $project, [$viewer->employee]);
    $private = cpaTask($owner, $project, [$hidden->employee]);
    $actor = Access::forUser($owner);
    app(CommentOnProjectTask::class)->handle($actor, $project->id, $visible->id, 'Visible note');
    app(CommentOnProjectTask::class)->handle($actor, $project->id, $private->id, 'Hidden note');
    $query = app(WorkRecords::class);
    expect($query->projectActivity(Access::forUser($viewer), $project->id, search: 'Hidden')->total())->toBe(0);
    expect($query->projectActivity($actor, $project->id, search: 'Visible', event: 'COMMENTS')->total())->toBe(1);
    expect($query->projectActivity($actor, $project->id, event: 'CHANGES')->total())->toBe(0);
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->set('status', 'PENDING')->set('taskView', 'activity')->set('activitySearch', 'Visible')
        ->set('activityEvent', 'COMMENTS')->call('clearFilters')->assertSet('status', 'PENDING')->assertSet('activityEvent', 'ALL')
        ->assertViewHas('activity', fn ($rows): bool => $rows->total() === 1);

});

it('opens quick-create forms only for the same project with current editing permission', function () {
    $owner = cpaUser();
    $viewer = cpaUser(cpaEmployee('Assigned'));
    $project = cpaProject($owner, cpaClient($owner, [$viewer]));
    cpaTask($owner, $project, [$viewer->employee]);
    app(SetupProjectFinance::class)->handle(Access::forUser($owner), $project->id, 'PHP');
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('quickCreate', 'expense')->assertSet('taskView', 'finance')->assertDispatched('ppm-quick-expense');
    Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id])->dispatch('ppm-quick-expense', projectId: $project->id)->assertSet('entryOpen', true)->assertSet('entryKind', 'EXPENSE');
    $reporting = Livewire::test(ProjectReportingWorkspace::class, ['projectId' => $project->id]);
    $reporting->dispatch('ppm-quick-indicator', projectId: 'foreign')->assertSet('editorOpen', false);
    $reporting->dispatch('ppm-quick-indicator', projectId: $project->id)->assertSet('editorOpen', true)->assertSet('editingKind', 'indicator');
    cpaLogin($viewer);
    Livewire::test(Show::class, ['project' => $project->id])->call('quickCreate', 'expense')->assertSet('taskView', 'overview')->assertNotDispatched('ppm-quick-expense');
});
