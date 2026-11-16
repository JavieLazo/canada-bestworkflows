<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Inventory\Support\InventoryRows;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Issued receipts remain discoverable after return, transfer or correction. */
#[Title('Memorandum Receipts')]
class ReceiptsPage extends Component
{
    use WithChangeStamp;
    use WithSorting;
    use WithTableFilters;

    public string $status = 'ALL';

    public function mount(): void
    {
        $this->sortKey = 'code';
        $this->sortDir = 'desc';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['code', 'mr_number', 'issued_on', 'status'];
    }

    /** The inventory stamp watches receipt creation and closure of issued lines. */
    protected function changeStamp(): string
    {
        return app(InventoryRecords::class)->changeStamp(app(Access::class));
    }

    public function render(): View
    {
        $this->markStampRendered();

        return view('livewire.inventory.receipts-page', [
            'canManage' => app(Access::class)->mayReadAllData(ModuleCode::INVENTORY),
            'rows' => $this->acceptPage(InventoryRows::receipts(app(Access::class), ['q' => $this->search, 'status' => $this->status, 'sort' => $this->sortKey, 'dir' => $this->sortDir], (int) $this->getPage(), $this->perPage)),
        ]);
    }
}
