<?php

declare(strict_types=1);

use App\Livewire\Projects\FinanceOverview;
use App\Livewire\Projects\ProjectFinanceWorkspace;
use App\Livewire\Projects\Show;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashEntry;
use App\Models\ProjectCostCategory;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\ApproveProjectBudget;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SetProjectFinanceMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Support\Access;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-03T12:00:00+08:00'));
});

it('builds and approves a budget then revises it without rewriting its accepted cost rows', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id]);
    $page->call('setupFinance')->assertHasNoErrors()->call('startBudget')->assertHasNoErrors();
    $budget = ProjectBudget::query()->sole();
    $page->call('openLine', $budget->id)->set('label', 'Field travel')->set('basis', 'CALCULATED')
        ->set('quantity', '2.5')->set('frequency', '3')->set('unitAmount', '12.34')->call('saveLine')->assertHasNoErrors()->assertSet('lineOpen', false)->assertSee('92.55');
    $page->call('openApproval', $budget->id, 2)->call('approveBudget')->assertHasNoErrors()->assertSet('approvalOpen', false);
    expect($budget->fresh()->status)->toBe('APPROVED')->and($budget->fresh()->total_minor)->toBe(9255);
    $page->set('revisionReason', 'Updated field plan')->call('startBudget')->assertHasNoErrors();
    expect(ProjectBudget::query()->count())->toBe(2)->and($budget->fresh()->total_minor)->toBe(9255);
});

it('keeps invalid or stale cost drafts open and supports undo before approval', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id])->call('setupFinance')->call('startBudget');
    $budget = ProjectBudget::query()->sole();
    $page->call('openLine', $budget->id)->set('label', 'Keep this label')->set('amount', '1.005')->call('saveLine')
        ->assertHasErrors('amount')->assertSet('lineOpen', true)->assertSet('label', 'Keep this label');
    $page->set('amount', '10.00')->call('saveLine')->assertHasNoErrors();
    $line = ProjectBudgetLine::query()->sole();
    $page->call('removeLine', $budget->id, 2, $line->id)->assertSee('Undo removal')->call('undoRemove')->assertHasNoErrors();
    expect(ProjectBudgetLine::query()->count())->toBe(1);
    $page->call('openLine', $budget->id, $line->id)->set('label', 'Unsaved local change');
    app(SaveBudgetLine::class)->handle(Access::forUser($owner), $project->id, $budget->id, 4, $line->id,
        new BudgetLineDraft('Someone else saved this', $line->category_id, '11'));
    $page->call('saveLine')->assertHasErrors('budget')->assertSet('lineOpen', true)->assertSet('label', 'Unsaved local change');
});

it('records cash forms once and preserves the original behind a correction and void', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id])->call('setupFinance');
    $page->call('openEntry', null, 'EXPENSE')->set('description', 'Paid transport')->set('amount', '100')->call('saveEntry')->assertHasNoErrors();
    $entry = ProjectCashEntry::query()->sole();
    $page->call('saveEntry')->assertHasNoErrors();
    expect(ProjectCashEntry::query()->count())->toBe(1);
    $page->call('openEntry', $entry->id)->set('amount', '80')->set('correctionReason', 'Receipt corrected')->call('saveEntry')->assertHasNoErrors();
    expect(ProjectCashEntry::query()->count())->toBe(2)->and($entry->fresh()->amount_minor)->toBe(10000)->and($entry->fresh()->voided_at)->not->toBeNull();
    $new = ProjectCashEntry::query()->whereNull('voided_at')->sole();
    $page->call('openEntryDetails', $new->id)->assertSee('Receipt corrected');
    $page->set('detailsOpen', false)->call('openVoid', $new->id)->set('voidReason', 'Payment reversed')->call('voidEntry')->assertHasNoErrors();
    expect(ProjectCashEntry::query()->whereNull('voided_at')->count())->toBe(0);
});

it('hides finance from ordinary task roles and exposes only granted financial actions', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $member->id, 'EDITOR');
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    cpaLogin($member);
    Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'finance')->assertSet('taskView', 'overview')
        ->assertDontSeeHtml("\$set('taskView', 'finance')");
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $member->id, 'VIEWER');
    $page = Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id]);
    $page->assertViewHas('permissions', ['view' => true, 'edit' => false, 'admin' => false]);
    $page->call('startBudget')->assertForbidden();
});

it('revokes financial data and open details on the next request without changing canonical work', function () {
    $owner = cpaUser();
    $viewer = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$viewer]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'PARTICIPANT');
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $entry = app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('RECEIPT', '2026-11-03', 'Financial-only receipt', '100', $category->id));
    cpaLogin($viewer);
    $page = Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id])->set('section', 'cash')->call('openEntryDetails', $entry);
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $viewer->id, null);
    $page->call('$refresh')->assertSee('Finance is no longer available to this account.')->assertDontSee('Financial-only receipt');
    $this->get(route('projects.finance.export', ['project' => $project->id, 'kind' => 'cash']))->assertForbidden();
    expect(ProjectCashEntry::query()->count())->toBe(1);
});

it('exports the selected approved version and filtered ledger through current financial access', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Travel plan', $category->id, '50'));
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 2);
    app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('EXPENSE', '2026-11-03', 'Travel paid', '10', $category->id));
    app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('RECEIPT', '2026-11-03', 'Received cash', '100', $category->id));
    cpaLogin($owner);
    $version = $this->get(route('projects.finance.export', ['project' => $project->id, 'budget' => $budget]))->assertOk()->streamedContent();
    expect($version)->toContain('APPROVED')->toContain('Travel plan')->toContain('50.00');
    $cash = $this->get(route('projects.finance.export', ['project' => $project->id, 'kind' => 'cash', 'type' => 'EXPENSE']))->assertOk()->streamedContent();
    expect($cash)->toContain('Travel paid')->not->toContain('Received cash');
    cpaLogin(cpaUser(allData: true));
    $this->get(route('projects.finance.export', ['project' => $project->id, 'budget' => $budget]))->assertForbidden();
});

it('keeps financial identity and concurrency tokens locked against hydration', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    foreach (['projectId' => 'other', 'entryRequestId' => 'forged', 'approvalRevision' => 999] as $property => $value) {
        expect(fn () => Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id])->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

it('rejects malformed export filters and missing finance before starting a download', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $this->getJson(route('projects.finance.export', ['project' => $project->id, 'budget' => ['bad']]))->assertUnprocessable();
    $this->get(route('projects.finance.export', ['project' => $project->id, 'kind' => 'cash']))->assertNotFound();
    $this->get(route('projects.finance.export', ['project' => $project->id]))->assertNotFound();
});

it('shows only authorized initialized financial projects on the module overview', function () {
    $owner = cpaUser();
    $other = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    app(SetupProjectFinance::class)->handle(Access::forUser($owner), $project->id, 'PHP');
    cpaLogin($other);
    Livewire::test(FinanceOverview::class)->assertViewHas('financialTotals', fn ($totals): bool => $totals->isEmpty());
    cpaLogin($owner);
    Livewire::test(FinanceOverview::class)->assertSee('Projects with financial access')->assertSee('No approved budget or cash records');
});
