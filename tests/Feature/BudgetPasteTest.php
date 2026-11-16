<?php

declare(strict_types=1);

use App\Livewire\Projects\ProjectFinanceWorkspace;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCostCategory;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\BudgetPaste;
use App\Modules\WorkManagement\Application\Commands\ApproveProjectBudget;
use App\Modules\WorkManagement\Application\Commands\PasteBudgetCosts;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SetProjectFinanceMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Support\Access;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('parses spreadsheet cells with quoted multiline labels and exact grouped amounts', function () {
    $rows = BudgetPaste::rows("\"Travel\nallowance\"\t1,250.05\r\nMaterials\t0\t\r\n\r\n");
    expect($rows)->toHaveCount(2)->and($rows[0])->toBe(['line' => 1, 'label' => "Travel\nallowance", 'amount' => 125005])
        ->and($rows[1]['amount'])->toBe(0);
});

it('refuses invalid pasted money formulas columns and oversized batches with a row error', function (string $text) {
    expect(fn () => BudgetPaste::rows($text))->toThrow(ValidationException::class);
})->with([
    'empty' => '', 'formula' => "Travel\t=1+2", 'fraction' => "Travel\t1.001", 'negative' => "Travel\t-1",
    'bad grouping' => "Travel\t1,25", 'currency' => "Travel\tPHP 25", 'extra column' => "Travel\t25\tnotes",
    'no item' => "\t25", 'long item' => str_repeat('a', 201)."\t25", 'too many' => str_repeat("Travel\t1\n", 101),
    'combined limit' => "Travel\t9999999999.99\nMaterials\t0.01", 'null byte' => "Travel\t1\0",
]);

it('adds pasted costs atomically and refuses stale retries and approved versions', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    $paste = app(PasteBudgetCosts::class);
    expect($paste->handle($actor, $project->id, $budget, 1, "Travel\t12.34\nMaterials\t56.78", $category->id))->toBe(3);
    expect((int) ProjectBudgetLine::query()->sum('total_minor'))->toBe(6912);
    expect(fn () => $paste->handle($actor, $project->id, $budget, 1, "Travel\t12.34", $category->id))->toThrow(ValidationException::class);
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 3);
    expect(fn () => $paste->handle($actor, $project->id, $budget, 4, "Travel\t1", $category->id))->toThrow(ValidationException::class);
    expect(ProjectBudgetLine::query()->count())->toBe(2)->and(ProjectBudget::query()->sole()->total_minor)->toBe(6912);
});

it('rolls back earlier pasted rows and revisions when the existing budget would overflow', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Existing cost', $category->id, '9999999999.98'));
    expect(fn () => app(PasteBudgetCosts::class)->handle($actor, $project->id, $budget, 2, "Travel\t0.01\nMaterials\t0.01", $category->id))->toThrow(ValidationException::class);
    expect(ProjectBudgetLine::query()->count())->toBe(1)->and(ProjectBudget::query()->sole()->revision)->toBe(2);
});

it('previews without writing and refuses changed rows or revoked financial access at save', function () {
    $owner = cpaUser();
    $editor = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$editor]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $editor->id, 'VIEWER');
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $editor->id, 'EDITOR');
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    cpaLogin($editor);
    $page = Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id])->call('openPaste', $budget)
        ->set('pasteText', "Travel\t12.34\nMaterials\t56.78")->call('previewPaste')->assertHasNoErrors()->assertSee('69.12');
    expect(ProjectBudgetLine::query()->count())->toBe(0);
    $page->set('pasteText', "Changed\t1")->call('pasteCosts')->assertHasErrors('pasteText');
    $page->call('previewPaste')->assertHasNoErrors();
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $editor->id, null);
    expect(fn () => app(PasteBudgetCosts::class)->handle(Access::forUser($editor), $project->id, $budget, 1, "Changed\t1", ProjectCostCategory::query()->sole()->id))->toThrow(HttpException::class);
    $page->call('pasteCosts')->assertForbidden();
    expect(ProjectBudgetLine::query()->count())->toBe(0);
});

it('saves previewed rows once and resets the budget filter to reveal the added items', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    cpaLogin($owner);
    Livewire::test(ProjectFinanceWorkspace::class, ['projectId' => $project->id])->set('section', 'budget')->set('search', 'old filter')
        ->call('openPaste', $budget)->set('pasteText', "Travel\t12.34\nMaterials\t56.78")->call('previewPaste')
        ->call('pasteCosts')->assertHasNoErrors()->assertSet('pasteOpen', false)->assertSet('search', '')->assertSee('Travel')->assertSee('Materials')
        ->call('pasteCosts')->assertHasErrors('pasteText');
    expect(ProjectBudgetLine::query()->count())->toBe(2)->and((int) ProjectBudgetLine::query()->sum('total_minor'))->toBe(6912);
});
