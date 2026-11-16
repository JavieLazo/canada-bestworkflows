<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\BudgetPaste;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\ApproveProjectBudget;
use App\Modules\WorkManagement\Application\Commands\PasteBudgetCosts;
use App\Modules\WorkManagement\Application\Commands\RecordAllocatedCash;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\RemoveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\RestoreBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SaveCostCategory;
use App\Modules\WorkManagement\Application\Commands\SetProjectFinanceMember;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Modules\WorkManagement\Application\Commands\VoidCashEntry;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectFunding;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ProjectFinanceWorkspace extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['created', 'date', 'name', 'basis', 'reference', 'amount'];
    }

    #[Locked]
    public string $projectId;

    public string $section = 'overview';

    public string $currency = 'PHP';

    /** A view filter, rechecked against this project; not #[Locked]. */
    public string $budgetChoice = '';

    public string $kind = 'ALL';

    /** Read filters, not #[Locked]; each query stays within current financial scope. */
    public string $cashFrom = '';

    public string $cashTo = '';

    public string $cashCategory = '';

    public string $budgetCategory = '';

    public bool $showVoided = false;

    public bool $lineOpen = false;

    public bool $entryOpen = false;

    public bool $categoryOpen = false;

    public bool $revisionOpen = false;

    public bool $approvalOpen = false;

    public bool $voidOpen = false;

    public bool $accessOpen = false;

    public bool $detailsOpen = false;

    public bool $currencyOpen = false;

    public bool $pasteOpen = false;

    public string $pasteText = '';

    #[Locked]
    public string $pasteBudgetId = '';

    #[Locked]
    public int $pasteRevision = 0;

    #[Locked]
    public string $pasteDigest = '';

    /** @var list<array{line: int, label: string, amount: int}> */
    #[Locked]
    public array $pasteRows = [];

    #[Locked]
    public ?string $detailsEntryId = null;

    #[Locked]
    public ?string $lineId = null;

    #[Locked]
    public string $lineBudgetId = '';

    #[Locked]
    public int $lineRevision = 0;

    #[Locked]
    public ?string $editingCategoryId = null;

    #[Locked]
    public ?string $replacesId = null;

    #[Locked]
    public string $entryRequestId = '';

    #[Locked]
    public ?string $voidEntryId = null;

    #[Locked]
    public string $approvalBudgetId = '';

    #[Locked]
    public int $approvalRevision = 0;

    #[Locked]
    public int $approvalVersion = 0;

    #[Locked]
    public int $approvalTotal = 0;

    #[Locked]
    public ?string $undoLineId = null;

    #[Locked]
    public string $undoBudgetId = '';

    #[Locked]
    public int $undoRevision = 0;

    public string $label = '';

    /** Cost/entry draft, validated against this project by its command; not #[Locked]. */
    public string $categoryId = '';

    /** Optional activity draft, revalidated by its command; not #[Locked]. */
    public string $groupId = 'NONE';

    /** Editable choices, not #[Locked]; budget and allocation commands revalidate their current project. */
    public string $linePeriod = '';

    public string $lineSource = '';

    public string $cashSource = '';

    public string $cashInstallment = '';

    public string $amount = '';

    public string $basis = 'FLAT';

    public string $quantity = '1';

    public string $frequency = '1';

    public string $unitAmount = '';

    public string $notes = '';

    public string $categoryName = '';

    public string $revisionReason = '';

    public string $entryKind = 'EXPENSE';

    public string $occurredOn = '';

    public string $description = '';

    public string $party = '';

    public string $reference = '';

    public string $correctionReason = '';

    public string $voidReason = '';

    /** Financial-access picker draft, revalidated by SetProjectFinanceMember; not #[Locked]. */
    public string $financeMember = '';

    public string $financeRole = 'VIEWER';

    #[On('ppm-quick-expense')]
    public function quickCreate(string $projectId): void
    {
        if ($projectId !== $this->projectId || ! app(ProjectFinancials::class)->permissions(app(Access::class), $this->projectId)['edit']) {
            return;
        }
        $this->section = 'cash';
        $this->openEntry();
    }

    /** @return list<string> */
    protected function pageSizePageNames(): array
    {
        return ['budgetPage', 'entriesPage'];
    }

    public function mount(string $projectId): void
    {
        $this->projectId = $projectId;
        abort_unless(app(ProjectFinancials::class)->permissions($this->access(), $projectId)['view'], 403);
    }

    public function updatedSection(): void
    {
        $this->search = '';
        $this->sortKey = $this->section === 'cash' ? 'date' : 'created';
        $this->sortDir = $this->section === 'cash' ? 'desc' : 'asc';
    }

    public function clearFilters(): void
    {
        if ($this->section === 'budget') {
            $this->budgetCategory = '';
        } else {
            $this->kind = 'ALL';
            $this->showVoided = false;
            $this->clearCashFilters();
        }
    }

    public function updatedBudgetChoice(): void
    {
        $this->resetPage('budgetPage');
        $this->undoLineId = null;
    }

    public function setupFinance(): void
    {
        $actor = $this->access();
        if ($this->run(fn () => app(SetupProjectFinance::class)->handle($actor, $this->projectId, $this->currency), $this->currencyOpen ? 'Currency saved.' : 'Finance enabled.') !== null) {
            $this->section = 'budget';
            $this->currencyOpen = false;
        }
    }

    public function openCurrency(): void
    {
        $actor = $this->access();
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->permissions($actor, $this->projectId)['admin'], 403);
        $settings = $queries->settings($actor, $this->projectId);
        abort_unless($settings !== null && $settings->can_change_currency, 403);
        $this->resetValidation();
        $this->currency = $settings->currency;
        $this->currencyOpen = true;
    }

    public function startBudget(): void
    {
        $actor = $this->access();
        $id = $this->run(fn () => app(StartProjectBudget::class)->handle($actor, $this->projectId, $this->revisionReason), 'Budget draft ready.');
        if ($id !== null) {
            $this->budgetChoice = $id;
            $this->revisionOpen = false;
            $this->revisionReason = '';
            $this->section = 'budget';
            $this->resetPage('budgetPage');
        }
    }

    public function openLine(string $budgetId, ?string $lineId = null): void
    {
        $actor = $this->access();
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->permissions($actor, $this->projectId)['edit'], 403);
        $budget = $queries->budget($actor, $this->projectId, $budgetId);
        abort_unless($budget !== null && $budget->status === 'DRAFT', 403);
        $line = $lineId === null ? null : $queries->line($actor, $this->projectId, $lineId);
        abort_if($lineId !== null && ($line === null || $line->budget_id !== $budgetId), 404);
        $this->resetValidation();
        $this->lineId = $lineId;
        $this->lineBudgetId = $budgetId;
        $this->lineRevision = $budget->revision;
        $this->label = $line->label ?? '';
        $this->categoryId = $line->category_id ?? ($queries->categories($actor, $this->projectId)->first()->id ?? '');
        $this->groupId = $line->group_id ?? 'NONE';
        $this->basis = $line->basis ?? 'FLAT';
        $this->amount = $line === null ? '' : FinanceAmount::input($line->total_minor);
        $this->quantity = $line?->quantity_scaled === null ? '1' : FinanceAmount::quantityInput($line->quantity_scaled);
        $this->frequency = (string) ($line->frequency ?? 1);
        $this->unitAmount = $line?->unit_minor === null ? '' : FinanceAmount::input($line->unit_minor);
        $this->notes = $line->notes ?? '';
        $this->linePeriod = $line->period_id ?? '';
        $this->lineSource = $line?->funding_id !== null ? 'FUNDING:'.$line->funding_id : ($line?->contribution_id !== null ? 'CONTRIBUTION:'.$line->contribution_id : '');
        $this->entryOpen = false;
        $this->lineOpen = true;
    }

    public function saveLine(): void
    {
        $actor = $this->access();
        if ($this->basis === 'CALCULATED') {
            $this->validate(['frequency' => 'required|integer|min:1|max:1000']);
        }
        $input = new BudgetLineDraft($this->label, $this->categoryId, $this->amount, $this->basis, $this->quantity,
            $this->basis === 'CALCULATED' ? (int) $this->frequency : 1, $this->unitAmount, $this->groupId === 'NONE' ? null : $this->groupId, $this->notes, $this->lineReporting());
        if ($this->run(fn () => app(SaveBudgetLine::class)->handle($actor, $this->projectId, $this->lineBudgetId, $this->lineRevision, $this->lineId, $input), 'Cost row saved.') !== null) {
            $this->lineOpen = false;
            $this->undoLineId = null;
        }
    }

    public function openPaste(string $budgetId): void
    {
        $actor = $this->access();
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->permissions($actor, $this->projectId)['edit'], 403);
        $budget = $queries->budget($actor, $this->projectId, $budgetId);
        abort_unless($budget !== null && $budget->status === 'DRAFT', 403);
        $this->resetValidation();
        $this->pasteBudgetId = $budgetId;
        $this->pasteRevision = $budget->revision;
        $this->pasteText = '';
        $this->pasteDigest = '';
        $this->pasteRows = [];
        $this->categoryId = $queries->categories($actor, $this->projectId)->first()->id ?? '';
        $this->groupId = 'NONE';
        $this->pasteOpen = true;
    }

    public function updatedPasteText(): void
    {
        $this->pasteRows = [];
        $this->pasteDigest = '';
    }

    public function previewPaste(): void
    {
        abort_unless(app(ProjectFinancials::class)->permissions($this->access(), $this->projectId)['edit'], 403);
        $this->resetValidation();
        $this->pasteRows = BudgetPaste::rows($this->pasteText);
        $this->pasteDigest = hash('sha256', $this->pasteText);
    }

    public function pasteCosts(): void
    {
        if ($this->pasteRows === [] || ! hash_equals($this->pasteDigest, hash('sha256', $this->pasteText))) {
            throw ValidationException::withMessages(['pasteText' => 'Preview the current rows before adding them.']);
        }
        $actor = $this->access();
        if ($this->run(fn () => app(PasteBudgetCosts::class)->handle($actor, $this->projectId, $this->pasteBudgetId,
            $this->pasteRevision, $this->pasteText, $this->categoryId, $this->groupId === 'NONE' ? null : $this->groupId), count($this->pasteRows).' cost items added.') !== null) {
            $this->pasteOpen = false;
            $this->pasteRows = [];
            $this->pasteText = '';
            $this->pasteDigest = '';
            $this->undoLineId = null;
            $this->search = '';
            $this->resetPage('budgetPage');
        }
    }

    public function removeLine(string $budgetId, int $revision, string $lineId): void
    {
        $actor = $this->access();
        $next = $this->run(fn () => app(RemoveBudgetLine::class)->handle($actor, $this->projectId, $budgetId, $revision, $lineId), 'Cost row removed.');
        if ($next !== null) {
            $this->undoLineId = $lineId;
            $this->undoBudgetId = $budgetId;
            $this->undoRevision = $next;
        }
    }

    public function undoRemove(): void
    {
        $actor = $this->access();
        abort_if($this->undoLineId === null, 404);
        if ($this->run(fn () => app(RestoreBudgetLine::class)->handle($actor, $this->projectId, $this->undoBudgetId, $this->undoRevision, $this->undoLineId), 'Cost row restored.') !== null) {
            $this->undoLineId = null;
        }
    }

    public function openApproval(string $budgetId, int $revision): void
    {
        $actor = $this->access();
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->permissions($actor, $this->projectId)['admin'], 403);
        $budget = $queries->budget($actor, $this->projectId, $budgetId);
        abort_unless($budget !== null && $budget->status === 'DRAFT', 403);
        if ($budget->revision !== $revision) {
            throw ValidationException::withMessages(['budget' => 'This draft changed. Review its current rows before approving.']);
        }
        $this->resetValidation();
        $this->approvalBudgetId = $budgetId;
        $this->approvalRevision = $revision;
        $this->approvalVersion = $budget->version;
        $this->approvalTotal = $queries->draftTotal($actor, $this->projectId, $budgetId);
        $this->approvalOpen = true;
    }

    public function approveBudget(): void
    {
        $actor = $this->access();
        if ($this->run(fn () => app(ApproveProjectBudget::class)->handle($actor, $this->projectId, $this->approvalBudgetId, $this->approvalRevision), 'Budget approved. This version is kept as history.') !== null) {
            $this->approvalOpen = false;
            $this->undoLineId = null;
        }
    }

    public function openCategory(?string $id = null): void
    {
        $actor = $this->access();
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->permissions($actor, $this->projectId)['edit'], 403);
        $category = $id === null ? null : $queries->categories($actor, $this->projectId)->firstWhere('id', $id);
        abort_if($id !== null && $category === null, 404);
        $this->resetValidation();
        $this->editingCategoryId = $id;
        $this->categoryName = $category->name ?? '';
        $this->categoryOpen = true;
    }

    public function saveCategory(): void
    {
        $actor = $this->access();
        $id = $this->run(fn () => app(SaveCostCategory::class)->handle($actor, $this->projectId, $this->editingCategoryId, $this->categoryName), 'Category saved.');
        if ($id !== null) {
            $this->categoryId = $id;
            $this->categoryOpen = false;
        }
    }

    public function openEntry(?string $id = null, string $kind = 'EXPENSE'): void
    {
        $actor = $this->access();
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->permissions($actor, $this->projectId)['edit'], 403);
        $entry = $id === null ? null : $queries->entry($actor, $this->projectId, $id);
        abort_if($id !== null && ($entry === null || $entry->voided_at !== null), 404);
        $this->resetValidation();
        $this->replacesId = $id;
        $this->entryRequestId = (string) Str::ulid();
        $this->entryKind = $entry->kind ?? (in_array($kind, ['RECEIPT', 'EXPENSE'], true) ? $kind : 'EXPENSE');
        $this->occurredOn = $entry?->occurred_on->format('Y-m-d') ?? ManilaClock::todayKey();
        $this->description = $entry->description ?? '';
        $this->amount = $entry === null ? '' : FinanceAmount::input($entry->amount_minor);
        $this->categoryId = $entry->category_id ?? ($queries->categories($actor, $this->projectId)->first()->id ?? '');
        $this->groupId = $entry->group_id ?? 'NONE';
        $this->party = $entry->party ?? '';
        $this->reference = $entry->reference ?? '';
        $this->notes = $entry->notes ?? '';
        $this->correctionReason = '';
        $this->cashSource = '';
        $this->cashInstallment = '';
        $this->lineOpen = false;
        $this->entryOpen = true;
    }

    public function saveEntry(): void
    {
        $actor = $this->access();
        $input = new CashEntryDraft($this->entryKind, $this->occurredOn, $this->description, $this->amount, $this->categoryId,
            $this->groupId === 'NONE' ? null : $this->groupId, $this->party, $this->reference, $this->notes, $this->replacesId, $this->correctionReason, $this->entryRequestId);
        [$sourceKind, $sourceId] = array_pad(explode(':', $this->cashSource, 2), 2, null);
        if ($this->run(fn () => $this->cashSource === '' ? app(RecordCashEntry::class)->handle($actor, $this->projectId, $input)
            : app(RecordAllocatedCash::class)->handle($actor, $this->projectId, $input, $sourceKind, $sourceId, $this->cashInstallment ?: null), $this->replacesId === null ? 'Cash entry recorded.' : 'Correction recorded. The original is kept.') !== null) {
            $this->entryOpen = false;
            $this->section = 'cash';
            $this->resetPage('entriesPage');
        }
    }

    public function openVoid(string $entryId): void
    {
        $actor = $this->access();
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->permissions($actor, $this->projectId)['edit'], 403);
        $entry = $queries->entry($actor, $this->projectId, $entryId);
        abort_unless($entry !== null && $entry->voided_at === null, 404);
        $this->resetValidation();
        $this->voidEntryId = $entryId;
        $this->voidReason = '';
        $this->voidOpen = true;
    }

    #[On('record-source-receipt')]
    public function openSourceReceipt(string $kind, string $sourceId, ?string $installment = null): void
    {
        $choice = $kind.':'.$sourceId;
        $query = app(ProjectFunding::class);
        if ($kind === 'CONTRIBUTION') {
            $source = $query->contribution($this->access(), $this->projectId, $sourceId);
            abort_if($source === null || $source->kind !== 'CASH', 404);
        } else {
            abort_unless($kind === 'FUNDING' && $query->funding($this->access(), $this->projectId, $sourceId) !== null, 404);
        }
        $this->openEntry(null, 'RECEIPT');
        $this->cashSource = $choice;
        $this->cashInstallment = $installment ?? '';
    }

    /** @return array{periodId: string|null, sourceKind: string, sourceId: string|null} */
    private function lineReporting(): array
    {
        [$kind, $id] = array_pad(explode(':', $this->lineSource, 2), 2, '');

        return ['periodId' => $this->linePeriod ?: null, 'sourceKind' => $kind ?: 'NONE', 'sourceId' => $id ?: null];
    }

    public function openEntryDetails(string $entryId): void
    {
        abort_if(app(ProjectFinancials::class)->entry($this->access(), $this->projectId, $entryId) === null, 404);
        $this->detailsEntryId = $entryId;
        $this->detailsOpen = true;
    }

    public function voidEntry(): void
    {
        $actor = $this->access();
        abort_if($this->voidEntryId === null, 404);
        if ($this->run(fn () => app(VoidCashEntry::class)->handle($actor, $this->projectId, $this->voidEntryId, $this->voidReason), 'Entry voided. Its history is kept.') !== null) {
            $this->voidOpen = false;
        }
    }

    public function openAccess(): void
    {
        abort_unless(app(ProjectFinancials::class)->permissions($this->access(), $this->projectId)['admin'], 403);
        $this->resetValidation();
        $this->financeMember = '';
        $this->financeRole = 'VIEWER';
        $this->accessOpen = true;
    }

    public function saveAccess(): void
    {
        $actor = $this->access();
        $this->validate(['financeMember' => 'required|integer|min:1']);
        if ($this->run(fn () => app(SetProjectFinanceMember::class)->handle($actor, $this->projectId, (int) $this->financeMember, $this->financeRole), 'Financial access saved.') !== null) {
            $this->financeMember = '';
        }
    }

    public function removeAccess(int $userId): void
    {
        $actor = $this->access();
        $this->run(fn () => app(SetProjectFinanceMember::class)->handle($actor, $this->projectId, $userId, null), 'Financial access removed.');
    }

    public function showBudget(?string $category = null): void
    {
        $query = app(ProjectFinancials::class);
        $actor = $this->access();
        abort_unless($query->canView($actor, $this->projectId), 403);
        if ($category !== null) {
            abort_unless($query->categories($actor, $this->projectId)->contains('id', $category), 404);
        }
        $this->budgetChoice = $query->versions($actor, $this->projectId)->firstWhere('status', 'APPROVED')->id ?? '';
        $this->budgetCategory = $category ?? '';
        $this->search = '';
        $this->section = 'budget';
        $this->resetPage('budgetPage');
    }

    public function showCashKind(string $kind): void
    {
        abort_unless(app(ProjectFinancials::class)->canView($this->access(), $this->projectId), 403);
        abort_unless(in_array($kind, ['ALL', 'RECEIPT', 'EXPENSE'], true), 404);
        $this->kind = $kind;
        $this->cashFrom = $this->cashTo = $this->cashCategory = $this->search = '';
        $this->cashTo = ManilaClock::todayKey();
        $this->showVoided = false;
        $this->section = 'cash';
        $this->resetPage('entriesPage');
    }

    public function showCashMonth(string $month, string $kind): void
    {
        $this->showCashKind($kind);
        $months = app(ProjectFinancials::class)->cashTrend($this->access(), $this->projectId, ManilaClock::todayKey());
        abort_unless(in_array($month, array_column($months, 'key'), true), 404);
        $date = CarbonImmutable::parse($month.'-01');
        $this->cashFrom = $date->format('Y-m-d');
        $this->cashTo = min(ManilaClock::todayKey(), $date->endOfMonth()->format('Y-m-d'));
    }

    public function showCashCategory(string $category): void
    {
        $this->showCashKind('EXPENSE');
        abort_unless(app(ProjectFinancials::class)->categories($this->access(), $this->projectId)->contains('id', $category), 404);
        $this->cashCategory = $category;
    }

    public function clearCashFilters(): void
    {
        $this->cashFrom = $this->cashTo = $this->cashCategory = '';
        $this->resetPage('entriesPage');
    }

    public function render(): View
    {
        $actor = $this->access();
        $queries = app(ProjectFinancials::class);
        $permissions = $queries->permissions($actor, $this->projectId);
        $this->section = in_array($this->section, ['overview', 'budget', 'cash', 'funding'], true) ? $this->section : 'overview';
        $settings = $permissions['view'] ? $queries->settings($actor, $this->projectId) : null;
        $versions = $settings === null ? collect() : $queries->versions($actor, $this->projectId);
        $budget = $versions->firstWhere('id', $this->budgetChoice) ?? $versions->first();
        $lines = $budget === null ? null : $this->acceptPage($queries->lines($actor, $this->projectId, $budget->id, (int) $this->getPage('budgetPage'), $this->perPage, $this->search, $this->budgetCategory, $this->sortKey === '' ? 'created' : $this->sortKey, $this->sortDir), 'budgetPage');
        $asOf = ManilaClock::todayKey();
        $members = $this->accessOpen && $permissions['admin']
            ? $this->acceptPage($queries->members($actor, $this->projectId, (int) $this->getPage('financePeoplePage'), $this->perPage), 'financePeoplePage') : null;

        return view('livewire.projects.project-finance-workspace', [
            'permissions' => $permissions, 'settings' => $settings, 'versions' => $versions, 'budget' => $budget, 'lines' => $lines,
            'budgetTotal' => $budget === null ? null : ($budget->status === 'APPROVED' ? $budget->total_minor : $queries->draftTotal($actor, $this->projectId, $budget->id)),
            'categories' => $settings === null ? collect() : $queries->categories($actor, $this->projectId),
            'groups' => $settings === null || ! $permissions['edit'] ? collect() : $queries->planningGroups($actor, $this->projectId),
            'totals' => $settings === null ? null : $queries->totals($actor, $asOf, $this->projectId)->first(),
            'categoryTotals' => $settings === null ? collect() : $queries->categoryTotals($actor, $this->projectId, $asOf),
            'cashTrend' => $settings === null ? [] : $queries->cashTrend($actor, $this->projectId, $asOf),
            'entries' => $settings === null ? null : $this->acceptPage($queries->entries($actor, $this->projectId, $this->search, $this->kind, $this->showVoided, (int) $this->getPage('entriesPage'), $this->perPage, ['from' => $this->cashFrom, 'to' => $this->cashTo, 'category' => $this->cashCategory], $this->sortKey === '' ? 'date' : $this->sortKey, $this->sortKey === '' ? 'desc' : $this->sortDir), 'entriesPage'),
            'financePeople' => $members,
            'entryDetails' => ! $this->detailsOpen || $this->detailsEntryId === null ? null : $queries->entry($actor, $this->projectId, $this->detailsEntryId),
            'financeCandidates' => $this->accessOpen && $permissions['admin'] ? $queries->candidates($actor, $this->projectId) : collect(),
            'reportingPeriods' => $settings === null || ! $permissions['edit'] ? collect() : app(ProjectReports::class)->periods($actor, $this->projectId),
            'fundingChoices' => $settings === null || ! $permissions['edit'] ? [] : app(ProjectFunding::class)->choices($actor, $this->projectId),
            'sourceInstallments' => $settings === null || ! $permissions['edit'] || ! str_starts_with($this->cashSource, 'FUNDING:') ? null : app(ProjectFunding::class)->installments($actor, $this->projectId, substr($this->cashSource, 8)),
            'asOf' => $asOf,
        ]);
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T|null
     */
    private function run(Closure $operation, string $success): mixed
    {
        try {
            $result = $operation();
        } catch (QueryException $error) {
            report($error);
            Flux::toast(variant: 'danger', text: 'Finance could not be saved. Please try again.');

            return null;
        }
        $this->resetValidation();
        if ($result !== false) {
            Flux::toast(variant: 'success', text: $success);
        }

        return $result;
    }

    private function access(): Access
    {
        return Access::forActor(auth()->user());
    }
}
