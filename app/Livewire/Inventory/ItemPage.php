<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Inventory\Support\ManagesInventory;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** An item's current custody stays separate from its immutable movement history. */
#[Title('Inventory item')]
class ItemPage extends Component
{
    use ManagesInventory;
    use WithChangeStamp;

    #[Locked]
    public string $itemId;

    public function mount(string $id): void
    {
        $this->itemId = $id;
    }

    /** The inventory stamp watches the item, catalogs, incidents and its custody history. */
    protected function changeStamp(): string
    {
        return app(InventoryRecords::class)->changeStamp(app(Access::class));
    }

    public function render(): View
    {
        $this->markStampRendered();
        $access = app(Access::class);
        $query = app(InventoryRecords::class);
        $item = $query->item($access, $this->itemId);
        abort_if($item === null, 404);

        return view('livewire.inventory.item-page', [
            'item' => $item,
            'movements' => $query->movements($access, $this->itemId),
            'categories' => $query->catalogs($access, 'category'),
            'locations' => $query->catalogs($access, 'location'),
            'canManage' => $access->mayReadAllData(ModuleCode::INVENTORY),
        ]);
    }
}
