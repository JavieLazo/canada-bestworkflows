<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Modules\WorkManagement\Application\Commands\SetCashAllocations;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectFunding;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CashAllocations extends Component
{
    #[Locked]
    public string $projectId;

    #[Locked]
    public string $entryId;

    #[Locked]
    public int $revision = 0;

    public string $historyRevision = '';

    public bool $editing = false;

    public string $reason = '';

    /** Editable split rows and picker ids, not #[Locked]; the command validates every current source and exact total. */
    /** @var list<array<string, string>> */
    public array $splits = [];

    public function edit(): void
    {
        $actor = app(Access::class);
        abort_unless(app(ProjectFinancials::class)->permissions($actor, $this->projectId)['edit'], 403);
        $entry = app(ProjectFinancials::class)->entry($actor, $this->projectId, $this->entryId);
        abort_if($entry === null || $entry->voided_at !== null, 404);
        $this->revision = $entry->allocation_revision;
        $this->splits = [];
        foreach (app(ProjectFunding::class)->allocations($actor, $this->projectId, $entry->id) as $row) {
            $this->splits[] = ['amount' => FinanceAmount::input($row->amount_minor), 'source' => $row->source_id === null ? '' : $row->source_kind.':'.$row->source_id, 'installmentId' => $row->installment_id ?? '', 'groupId' => $row->group_id ?? ''];
        }
        if ($this->splits === []) {
            $this->splits = [['amount' => FinanceAmount::input($entry->amount_minor), 'source' => '', 'installmentId' => '', 'groupId' => $entry->group_id ?? '']];
        }
        $this->reason = '';
        $this->resetValidation();
        $this->editing = true;
    }

    public function add(): void
    {
        if (count($this->splits) < 100) {
            $this->splits[] = ['amount' => '', 'source' => '', 'installmentId' => '', 'groupId' => ''];
        }
    }

    public function remove(int $index): void
    {
        $rows = $this->splits;
        unset($rows[$index]);
        $this->splits = array_values($rows);
    }

    public function save(): void
    {
        $this->validate(['splits' => 'required|array|min:1|max:100', 'splits.*' => 'required|array', 'splits.*.amount' => 'required|string', 'splits.*.source' => 'nullable|string', 'splits.*.installmentId' => 'nullable|string', 'splits.*.groupId' => 'nullable|string']);
        $rows = [];
        foreach ($this->splits as $row) {
            [$kind, $id] = array_pad(explode(':', $row['source'], 2), 2, '');
            $rows[] = ['amount' => $row['amount'], 'sourceKind' => $kind ?: 'NONE', 'sourceId' => $id ?: null, 'installmentId' => $row['installmentId'] ?: null, 'groupId' => $row['groupId'] ?: null];
        }
        try {
            app(SetCashAllocations::class)->handle(app(Access::class), $this->projectId, $this->entryId, $this->revision, $rows, $this->reason);
        } catch (ValidationException $e) {
            $this->addError('allocations', implode(' ', array_merge(...array_values($e->errors()))));

            return;
        }
        $this->editing = false;
        $this->historyRevision = '';
        Flux::toast('Allocation saved. The cash amount is unchanged.');
    }

    public function render(): View
    {
        $actor = app(Access::class);
        $query = app(ProjectFunding::class);
        $finance = app(ProjectFinancials::class);
        $entry = $finance->entry($actor, $this->projectId, $this->entryId);
        abort_if($entry === null, 404);
        $revision = ctype_digit($this->historyRevision) ? min($entry->allocation_revision, (int) $this->historyRevision) : $entry->allocation_revision;

        return view('livewire.projects.cash-allocations', ['entry' => $entry, 'rows' => $query->allocations($actor, $this->projectId, $entry->id, $revision), 'shownRevision' => $revision,
            'editable' => $entry->voided_at === null && $finance->permissions($actor, $this->projectId)['edit'],
            'sources' => $this->editing ? $query->choices($actor, $this->projectId) : [], 'groups' => $this->editing ? $finance->planningGroups($actor, $this->projectId) : collect(),
            'installments' => $this->editing ? $query->installmentChoices($actor, $this->projectId) : collect()]);
    }
}
