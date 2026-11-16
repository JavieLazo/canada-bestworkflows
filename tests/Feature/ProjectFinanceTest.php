<?php

declare(strict_types=1);

use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashEntry;
use App\Models\ProjectCostCategory;
use App\Models\ProjectFinance;
use App\Models\ProjectFinanceMember;
use App\Modules\IdentityAccess\Application\AccountRetention;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\ApproveProjectBudget;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\RemoveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\RestoreBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SaveCostCategory;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectFinanceMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Modules\WorkManagement\Application\Commands\VoidCashEntry;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-03T12:00:00+08:00'));
});

it('starts finance explicitly and leaves missing budgets unknown without fabricating expenses', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $queries = app(ProjectFinancials::class);
    expect($queries->totals($actor, '2026-11-03'))->toBeEmpty();
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $total = $queries->totals($actor, '2026-11-03')->sole();
    expect($total->budget())->toBeNull()->and($total->remaining())->toBeNull()->and($total->paidMinor)->toBe(0)
        ->and($total->netCash())->toBe(0)->and($total->projects)->toBe(1)->and(ProjectCostCategory::query()->sole()->name)->toBe('General');
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'USD');
    expect(ProjectFinance::query()->sole()->currency)->toBe('USD');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('First cost', ProjectCostCategory::query()->sole()->id, '1'));
    expect(fn () => app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP'))->toThrow(ValidationException::class);
    expect(ProjectFinance::query()->sole()->currency)->toBe('USD');
});

it('keeps task editing project leadership portfolio ownership and broad unrelated access separate from financial rights', function () {
    $owner = cpaUser();
    $editor = cpaUser();
    $lead = cpaUser();
    $outsider = cpaUser(allData: true);
    $client = cpaClient($owner, [$editor, $lead]);
    $project = cpaProject($owner, $client);
    $project->update(['manager_id' => $lead->id]);
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $editor->id, 'EDITOR');
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    foreach ([$editor, $lead, $outsider] as $user) {
        $reader = Access::forUser($user);
        expect(app(ProjectFinancials::class)->permissions($reader, $project->id)['view'])->toBeFalse()
            ->and(app(ProjectFinancials::class)->totals($reader, '2026-11-03'))->toBeEmpty();
        expect(fn () => app(StartProjectBudget::class)->handle($reader, $project->id))->toThrow(HttpException::class);
    }
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $lead->id, 'VIEWER');
    expect(app(ProjectFinancials::class)->permissions(Access::forUser($lead), $project->id))->toBe(['view' => true, 'edit' => false, 'admin' => false]);
});

it('grants financial access only inside existing active project and client access', function () {
    $owner = cpaUser();
    $viewer = cpaUser();
    $tasksOnly = cpaUser(modules: ['TASKS']);
    $client = cpaClient($owner, [$viewer, $tasksOnly]);
    $project = cpaProject($owner, $client);
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'PARTICIPANT');
    app(SetProjectMember::class)->handle($actor, $project->id, $tasksOnly->id, 'PARTICIPANT');
    $set = app(SetProjectFinanceMember::class);
    expect(fn () => $set->handle($actor, $project->id, $tasksOnly->id, 'VIEWER'))->toThrow(ValidationException::class);
    $set->handle($actor, $project->id, $viewer->id, 'VIEWER');
    $reader = Access::forUser($viewer);
    expect(app(ProjectFinancials::class)->permissions($reader, $project->id)['view'])->toBeTrue()->and(WorkScope::tasks($reader)->count())->toBe(0);
    expect(fn () => $set->handle($reader, $project->id, $owner->id, 'EDITOR'))->toThrow(HttpException::class);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, null);
    expect(ProjectFinanceMember::query()->count())->toBe(0)->and(app(ProjectFinancials::class)->permissions($reader, $project->id)['view'])->toBeFalse();
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    expect(app(ProjectFinancials::class)->permissions($reader, $project->id)['view'])->toBeFalse();
});

it('revokes finance on client removal without deleting its retained history or grant records', function () {
    $owner = cpaUser();
    $viewer = cpaUser();
    $client = cpaClient($owner, [$viewer]);
    $project = cpaProject($owner, $client);
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $reader = Access::forUser($viewer);
    expect(app(ProjectFinancials::class)->settings($reader, $project->id))->not->toBeNull();
    app(SetClientMember::class)->handle($actor, $client->id, $viewer->id, false);
    expect(app(ProjectFinancials::class)->settings($reader, $project->id))->toBeNull()
        ->and(ProjectFinance::query()->count())->toBe(1)->and(ProjectFinanceMember::query()->count())->toBe(1);
});

it('preserves approved cost assumptions and requires a new revision for later planning changes', function () {
    $owner = cpaUser();
    $editor = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$editor]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $editor->id, 'EDITOR');
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $editor->id, 'EDITOR');
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $writer = Access::forUser($editor);
    $budgetId = app(StartProjectBudget::class)->handle($writer, $project->id);
    $save = app(SaveBudgetLine::class);
    $save->handle($writer, $project->id, $budgetId, 1, null, new BudgetLineDraft('Travel', $category->id, '25.00'));
    $save->handle($writer, $project->id, $budgetId, 2, null, new BudgetLineDraft('Materials', $category->id, '', 'CALCULATED', '2.5', 3, '12.34'));
    expect(fn () => app(ApproveProjectBudget::class)->handle($writer, $project->id, $budgetId, 3))->toThrow(HttpException::class);
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budgetId, 3);
    $budget = ProjectBudget::query()->findOrFail($budgetId);
    expect($budget->status)->toBe('APPROVED')->and($budget->total_minor)->toBe(11755);
    expect(fn () => $save->handle($writer, $project->id, $budgetId, 4, null, new BudgetLineDraft('Changed', $category->id, '1')))->toThrow(ValidationException::class);
    app(SaveCostCategory::class)->handle($writer, $project->id, $category->id, 'Delivery');
    expect(ProjectBudgetLine::query()->where('budget_id', $budgetId)->pluck('category_label')->unique()->all())->toBe(['General']);
    expect(fn () => app(StartProjectBudget::class)->handle($writer, $project->id))->toThrow(ValidationException::class);
    $revision = app(StartProjectBudget::class)->handle($writer, $project->id, 'Updated delivery assumptions');
    expect($revision)->not->toBe($budgetId)->and(ProjectBudgetLine::query()->where('budget_id', $revision)->count())->toBe(2);
    $first = ProjectBudgetLine::query()->where('budget_id', $revision)->firstOrFail();
    $save->handle($writer, $project->id, $revision, 1, $first->id, new BudgetLineDraft('Travel', $category->id, '30.00'));
    expect($budget->fresh()->total_minor)->toBe(11755)->and(app(ProjectFinancials::class)->totals($actor, '2026-11-03')->sole()->budget())->toBe(11755);
});

it('refuses stale budget writes and approvals and supports undo for draft rows', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    $category = ProjectCostCategory::query()->sole();
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Cost', $category->id, '10'));
    $line = ProjectBudgetLine::query()->sole();
    expect(fn () => app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 1))->toThrow(ValidationException::class);
    app(RemoveBudgetLine::class)->handle($actor, $project->id, $budget, 2, $line->id);
    expect(ProjectBudgetLine::query()->count())->toBe(0)->and(ProjectBudgetLine::withTrashed()->count())->toBe(1);
    app(RestoreBudgetLine::class)->handle($actor, $project->id, $budget, 3, $line->id);
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 4);
    expect(ProjectBudget::query()->sole()->total_minor)->toBe(1000);
});

it('records cash receipts and paid expenses with linked corrections instead of rewriting history', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $record = app(RecordCashEntry::class);
    $record->handle($actor, $project->id, new CashEntryDraft('RECEIPT', '2026-11-02', 'Received funding', '200', $category->id));
    $oldId = $record->handle($actor, $project->id, new CashEntryDraft('EXPENSE', '2026-11-03', 'Paid travel', '50', $category->id));
    $old = ProjectCashEntry::query()->findOrFail($oldId);
    $newId = $record->handle($actor, $project->id, new CashEntryDraft('EXPENSE', '2026-11-03', 'Corrected travel', '35', $category->id, replacesId: $oldId, correctionReason: 'Receipt amount corrected'));
    $totals = app(ProjectFinancials::class)->totals($actor, '2026-11-03')->sole();
    expect($totals->receivedMinor)->toBe(20000)->and($totals->paidMinor)->toBe(3500)->and($totals->netCash())->toBe(16500)
        ->and($totals->remaining())->toBeNull()->and(ProjectCashEntry::query()->count())->toBe(3)
        ->and($old->fresh()->amount_minor)->toBe(5000)->and($old->fresh()->void_reason)->toBe('Receipt amount corrected')
        ->and(ProjectCashEntry::query()->findOrFail($newId)->replaces_id)->toBe($oldId);
    expect(fn () => $record->handle($actor, $project->id, new CashEntryDraft('EXPENSE', '2026-11-03', 'Again', '1', $category->id, replacesId: $oldId, correctionReason: 'Duplicate')))->toThrow(ValidationException::class);
    app(VoidCashEntry::class)->handle($actor, $project->id, $newId, 'Payment cancelled');
    expect(app(ProjectFinancials::class)->totals($actor, '2026-11-03')->sole()->paidMinor)->toBe(0);
});

it('rejects cross-project categories and future cash actuals without changing records', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $first = cpaProject($owner, cpaClient($owner));
    $second = cpaProject($owner, cpaClient($owner), 'Other project');
    app(SetupProjectFinance::class)->handle($actor, $first->id, 'PHP');
    app(SetupProjectFinance::class)->handle($actor, $second->id, 'PHP');
    $foreign = ProjectCostCategory::query()->where('project_id', $second->id)->sole();
    expect(fn () => app(RecordCashEntry::class)->handle($actor, $first->id, new CashEntryDraft('EXPENSE', '2026-11-03', 'Wrong scope', '10', $foreign->id)))->toThrow(ValidationException::class);
    $category = ProjectCostCategory::query()->where('project_id', $first->id)->sole();
    expect(fn () => app(RecordCashEntry::class)->handle($actor, $first->id, new CashEntryDraft('EXPENSE', '2026-11-04', 'Future actual', '10', $category->id)))->toThrow(ValidationException::class);
    expect(ProjectCashEntry::query()->count())->toBe(0);
});

it('accepts an exact form retry once and refuses reusing its identity for a different amount', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $key = (string) Str::ulid();
    $input = new CashEntryDraft('EXPENSE', '2026-11-03', 'Paid once', '10', $category->id, requestId: $key);
    $record = app(RecordCashEntry::class);
    expect($record->handle($actor, $project->id, $input))->toBe($key)
        ->and($record->handle($actor, $project->id, $input))->toBe($key)->and(ProjectCashEntry::query()->count())->toBe(1);
    expect(fn () => $record->handle($actor, $project->id, new CashEntryDraft('EXPENSE', '2026-11-03', 'Paid once', '11', $category->id, requestId: $key)))->toThrow(ValidationException::class);
    expect(ProjectCashEntry::query()->sole()->amount_minor)->toBe(1000);
});

it('keeps zero approved budgets distinct from missing plans and protects finance on project deletion', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    expect(fn () => app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 1))->toThrow(ValidationException::class);
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Volunteer contribution', $category->id, '0'));
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 2);
    $totals = app(ProjectFinancials::class)->totals($actor, '2026-11-03')->sole();
    expect($totals->budget())->toBe(0)->and($totals->remaining())->toBe(0)->and($totals->utilized())->toBeNull();
    expect(fn () => app(DeleteProject::class)->handle($actor, $project->id))->toThrow(RuntimeException::class, 'financial records');
    expect(app(AccountRetention::class)->any($owner->id))->toBeTrue();
});

it('separates currencies and excludes unbudgeted costs from an approved-budget denominator', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    foreach (['Budgeted' => 'PHP', 'No budget' => 'PHP', 'Dollar' => 'USD'] as $name => $currency) {
        $project = cpaProject($owner, cpaClient($owner), $name);
        app(SetupProjectFinance::class)->handle($actor, $project->id, $currency);
        $category = ProjectCostCategory::query()->where('project_id', $project->id)->sole();
        if ($name === 'Budgeted') {
            $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
            app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Plan', $category->id, '100'));
            app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 2);
        }
        app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('EXPENSE', '2026-11-03', 'Paid cost', '10', $category->id));
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = app(ProjectFinancials::class)->totals($actor, '2026-11-03')->keyBy('currency');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($rows->keys()->all())->toBe(['PHP', 'USD'])->and($rows['PHP']->remaining())->toBe(9000)
        ->and($rows['PHP']->paidMinor)->toBe(2000)->and($rows['PHP']->budgetedPaidMinor)->toBe(1000)
        ->and($rows['USD']->budget())->toBeNull()->and($count)->toBe(1);
});

it('refuses a cost exceeding the project budget cap without advancing its draft revision', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Maximum', $category->id, '9999999999.99'));
    expect(fn () => app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 2, null, new BudgetLineDraft('Overflow', $category->id, '0.01')))->toThrow(ValidationException::class);
    expect(ProjectBudgetLine::query()->count())->toBe(1)->and(ProjectBudget::query()->sole()->revision)->toBe(2)
        ->and(app(ProjectFinancials::class)->draftTotal($actor, $project->id, $budget))->toBe(FinanceAmount::LIMIT);
});
