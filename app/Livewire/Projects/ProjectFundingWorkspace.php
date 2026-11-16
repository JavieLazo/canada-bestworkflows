<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\WorkManagement\Application\Commands\RecordContributionDelivery;
use App\Modules\WorkManagement\Application\Commands\SaveContribution;
use App\Modules\WorkManagement\Application\Commands\SaveFunding;
use App\Modules\WorkManagement\Application\Commands\SaveFundingInstallment;
use App\Modules\WorkManagement\Application\Commands\SaveOpeningCash;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectFunding;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProjectFundingWorkspace extends Component
{
    use WithSorting;
    use WithTableFilters;

    #[Locked]
    public string $projectId;

    public string $section = 'funding';

    public string $status = 'ALL';

    public string $detailSearch = '';

    public string $detailDirection = 'asc';

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['name', 'committed', 'received', 'date'];
    }

    public function clearFilters(): void
    {
        $this->status = 'ALL';
        $this->resetPage('fundingPage');
        $this->resetPage('contributionsPage');
    }

    /** Read filters, not #[Locked]; queries always resolve within the current project. */
    public string $periodChoice = '';

    public string $baselineChoice = '';

    #[Locked]
    public ?string $detailId = null;

    #[Locked]
    public string $detailKind = '';

    #[Locked]
    public ?string $deliveryId = null;

    #[Locked]
    public ?string $editingId = null;

    #[Locked]
    public string $editingKind = '';

    #[Locked]
    public ?string $parentId = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public string $requestId = '';

    public bool $editorOpen = false;

    /** Editable form values, not #[Locked]; finance commands recheck ownership, revisions, amounts and permissions. */
    /** @var array<string, mixed> */
    public array $form = [];

    /** @return list<string> */
    protected function pageSizePageNames(): array
    {
        return ['fundingPage', 'contributionsPage'];
    }

    public function show(string $kind, string $id): void
    {
        $query = app(ProjectFunding::class);
        $row = match ($kind) {
            'funding' => $query->funding(app(Access::class), $this->projectId, $id), 'contribution' => $query->contribution(app(Access::class), $this->projectId, $id), default => null
        };
        abort_if($row === null, 404);
        $this->detailKind = $kind;
        $this->detailId = $row->id;
        $this->detailSearch = '';
        $this->detailDirection = $kind === 'funding' ? 'asc' : 'desc';
        $this->deliveryId = null;
    }

    public function openFunding(?string $id = null): void
    {
        $row = $id === null ? null : app(ProjectFunding::class)->funding(app(Access::class), $this->projectId, $id);
        abort_if($id !== null && $row === null, 404);
        $this->begin('funding', $id, null, $row->revision ?? 0, ['name' => $row->name ?? '', 'party' => $row->provider ?? '', 'amount' => $row?->committed_minor === null ? '' : FinanceAmount::input($row->committed_minor), 'date' => $row->expected_on ?? '', 'notes' => $row->notes ?? '', 'status' => $row->status ?? 'ACTIVE', 'reason' => '']);
    }

    public function openContribution(?string $id = null): void
    {
        $row = $id === null ? null : app(ProjectFunding::class)->contribution(app(Access::class), $this->projectId, $id);
        abort_if($id !== null && $row === null, 404);
        $this->begin('contribution', $id, null, $row->revision ?? 0, ['name' => $row->name ?? '', 'party' => $row->contributor ?? '', 'kind' => $row->kind ?? 'CASH', 'amount' => $row?->committed_minor === null ? '' : FinanceAmount::input($row->committed_minor), 'quantity' => $row?->quantity_scaled === null ? '' : ResultValue::input($row->quantity_scaled), 'unit' => $row->unit ?? '', 'basis' => $row->valuation_basis ?? '', 'date' => $row->expected_on ?? '', 'notes' => $row->notes ?? '', 'status' => $row->status ?? 'ACTIVE', 'reason' => '']);
    }

    public function openInstallment(string $fundingId, ?string $id = null): void
    {
        $query = app(ProjectFunding::class);
        abort_if($query->funding(app(Access::class), $this->projectId, $fundingId) === null, 404);
        $row = $id === null ? null : $query->installment(app(Access::class), $this->projectId, $id);
        abort_if($id !== null && ($row === null || $row->funding_id !== $fundingId), 404);
        $this->begin('installment', $id, $fundingId, $row->revision ?? 0, ['name' => $row->name ?? '', 'amount' => $row === null ? '' : FinanceAmount::input($row->planned_minor), 'date' => $row->planned_on ?? '', 'notes' => $row->conditions ?? '', 'status' => $row->status ?? 'ACTIVE', 'reason' => '']);
    }

    public function openDelivery(string $contributionId, ?string $id = null): void
    {
        $query = app(ProjectFunding::class);
        $contribution = $query->contribution(app(Access::class), $this->projectId, $contributionId);
        abort_if($contribution === null || $contribution->kind !== 'IN_KIND', 404);
        $row = $id === null ? null : $query->delivery(app(Access::class), $this->projectId, $id);
        abort_if($id !== null && ($row === null || $row->contribution_id !== $contribution->id || $row->voided_at !== null), 404);
        $this->requestId = (string) Str::ulid();
        $this->begin('delivery', $id, $contribution->id, 0, ['date' => $row->occurred_on ?? ManilaClock::todayKey(), 'quantity' => $row?->quantity_scaled === null ? '' : ResultValue::input($row->quantity_scaled), 'amount' => $row?->value_minor === null ? '' : FinanceAmount::input($row->value_minor), 'basis' => $row->valuation_basis ?? '', 'reference' => $row->reference ?? '', 'notes' => $row->notes ?? '', 'reason' => '']);
    }

    public function showDelivery(string $id): void
    {
        $row = app(ProjectFunding::class)->delivery(app(Access::class), $this->projectId, $id);
        abort_if($row === null || $row->contribution_id !== $this->detailId, 404);
        $this->deliveryId = $row->id;
    }

    public function openVoidDelivery(string $id): void
    {
        $this->showDelivery($id);
        $this->begin('void', $id, $this->detailId, 0, ['reason' => '']);
    }

    public function openOpening(): void
    {
        $settings = app(ProjectFinancials::class)->settings(app(Access::class), $this->projectId);
        abort_if($settings === null, 404);
        $this->begin('opening', null, null, $settings->opening_revision, ['date' => $settings->opening_on ?? '', 'amount' => $settings->opening_minor === null ? '' : FinanceAmount::input($settings->opening_minor), 'reference' => $settings->opening_reference ?? '', 'notes' => $settings->opening_notes ?? '', 'reason' => '']);
    }

    public function save(): void
    {
        $actor = app(Access::class);
        $f = $this->form;
        $text = static fn (string $key): string => is_scalar($f[$key] ?? null) ? (string) $f[$key] : '';
        $date = $text('date') ?: null;
        try {
            switch ($this->editingKind) {
                case 'funding':
                    $row = app(SaveFunding::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, $text('name'), $text('party'), $text('amount'), $date, $text('notes'), $text('status'), $text('reason'));
                    $this->show('funding', $row->id);
                    break;
                case 'contribution':
                    $row = app(SaveContribution::class)->handle($actor, $this->projectId, $this->editingId, $this->revision, $text('name'), $text('party'), $text('kind'), $text('amount'), $text('quantity'), $text('unit'), $text('basis'), $date, $text('notes'), $text('status'), $text('reason'));
                    $this->show('contribution', $row->id);
                    break;
                case 'installment':
                    abort_if($this->parentId === null, 404);
                    app(SaveFundingInstallment::class)->handle($actor, $this->projectId, $this->parentId, $this->editingId, $this->revision, $text('name'), $text('amount'), $date, $text('notes'), $text('status'), $text('reason'));
                    break;
                case 'delivery':
                    abort_if($this->parentId === null, 404);
                    $this->deliveryId = app(RecordContributionDelivery::class)->handle($actor, $this->projectId, $this->parentId, $text('date'), $text('quantity'), $text('amount'), $text('basis'), $text('reference'), $text('notes'), $this->editingId, $text('reason'), $this->requestId);
                    break;
                case 'void':
                    abort_if($this->editingId === null, 404);
                    app(RecordContributionDelivery::class)->void($actor, $this->projectId, $this->editingId, $text('reason'));
                    break;
                case 'opening':
                    app(SaveOpeningCash::class)->handle($actor, $this->projectId, $this->revision, $date, $text('amount'), $text('reference'), $text('notes'), $text('reason'));
                    break;
                default: abort(404);
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->editorOpen = false;
        Flux::toast('Saved.');
    }

    public function render(): View
    {
        $actor = app(Access::class);
        $query = app(ProjectFunding::class);
        $finance = app(ProjectFinancials::class);
        $permissions = $finance->permissions($actor, $this->projectId);
        $settings = $permissions['view'] ? $finance->settings($actor, $this->projectId) : null;
        $detail = $this->detailId === null ? null : match ($this->detailKind) {
            'funding' => $query->funding($actor, $this->projectId, $this->detailId), 'contribution' => $query->contribution($actor, $this->projectId, $this->detailId), default => null
        };
        $periods = $settings === null ? collect() : app(ProjectReports::class)->periods($actor, $this->projectId);
        $period = $periods->firstWhere('id', $this->periodChoice);
        $budgets = $settings === null ? collect() : $finance->versions($actor, $this->projectId)->where('status', 'APPROVED');
        $budget = $budgets->firstWhere('id', $this->baselineChoice) ?? $budgets->first();

        return view('livewire.projects.project-funding-workspace', compact('permissions', 'settings', 'detail', 'periods', 'budgets', 'budget') + [
            'fundings' => $settings === null ? null : $this->acceptPage($query->fundings($actor, $this->projectId, (int) $this->getPage('fundingPage'), search: $this->search, status: $this->status, sort: $this->sortKey, direction: $this->sortDir, perPage: $this->perPage), 'fundingPage'),
            'contributions' => $settings === null ? null : $this->acceptPage($query->contributions($actor, $this->projectId, (int) $this->getPage('contributionsPage'), search: $this->search, status: $this->status, sort: $this->sortKey, direction: $this->sortDir, perPage: $this->perPage), 'contributionsPage'),
            'installments' => $detail === null || $this->detailKind !== 'funding' ? null : $this->acceptPage($query->installments($actor, $this->projectId, $detail->id, (int) $this->getPage('installmentsPage'), $this->detailSearch, $this->detailDirection), 'installmentsPage'),
            'deliveries' => $detail === null || $this->detailKind !== 'contribution' ? null : $this->acceptPage($query->deliveries($actor, $this->projectId, $detail->id, (int) $this->getPage('deliveriesPage'), true, $this->detailSearch, $this->detailDirection), 'deliveriesPage'),
            'delivery' => $this->deliveryId === null ? null : $query->delivery($actor, $this->projectId, $this->deliveryId),
            'cashPosition' => $settings === null ? null : $query->cashPosition($actor, $this->projectId),
            'variance' => $period === null ? null : $query->periodVariance($actor, $this->projectId, $period->id, $budget?->id),
        ]);
    }

    /** @param array<string, mixed> $form */
    private function begin(string $kind, ?string $id, ?string $parentId, int $revision, array $form): void
    {
        $this->editingKind = $kind;
        $this->editingId = $id;
        $this->parentId = $parentId;
        $this->revision = $revision;
        $this->form = $form;
        $this->resetValidation();
        $this->editorOpen = true;
    }
}
