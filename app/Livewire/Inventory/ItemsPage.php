<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Inventory\Support\InventoryRows;
use App\Livewire\Inventory\Support\ManagesInventory;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Item register and custody drafts call the inventory owner for every read and write. */
#[Title('Inventory')]
class ItemsPage extends Component
{
    use ManagesInventory;
    use WithChangeStamp;
    use WithSorting;
    use WithTableFilters;

    public string $status = 'ALL';

    /** Scoped category filter, deliberately not Locked. */
    public string $categoryId = 'ALL';

    /** Scoped location filter, deliberately not Locked. */
    public string $locationId = 'ALL';

    public function mount(): void
    {
        $this->sortKey = 'code';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['code', 'name', 'acquired_on', 'cost', 'status'];
    }

    /** The inventory stamp watches item, custody, catalog and condition changes. */
    protected function changeStamp(): string
    {
        return app(InventoryRecords::class)->changeStamp(app(Access::class));
    }

    public function render(): View
    {
        $this->markStampRendered();
        $access = app(Access::class);
        $query = app(InventoryRecords::class);
        $filters = ['q' => $this->search, 'status' => $this->status, 'category_id' => $this->categoryId, 'location_id' => $this->locationId, 'sort' => $this->sortKey, 'dir' => $this->sortDir];

        return view('livewire.inventory.items-page', [
            'rows' => $this->acceptPage(InventoryRows::items($access, $filters, (int) $this->getPage(), $this->perPage)),
            'categories' => $query->catalogs($access, 'category'),
            'locations' => $query->catalogs($access, 'location'),
            'canManage' => $access->mayReadAllData(ModuleCode::INVENTORY),
        ]);
    }
}
