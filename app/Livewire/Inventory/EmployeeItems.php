<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\TableDefaults;
use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Employee and Portal tabs share current holdings while preserving each entry point's row scope. */
class EmployeeItems extends Component
{
    use WithChangeStamp;
    use WithTableFilters;

    public const string PAGE_NAME = 'inventoryPage';

    #[Locked]
    public string $employeeId = '';

    #[Locked]
    public bool $own = false;

    public function mount(string $employeeId = '', bool $own = false): void
    {
        $this->own = $own;
        $this->employeeId = $own ? $this->ownEmployee() : $employeeId;
    }

    public function updatedPerPage(mixed $value): void
    {
        $size = (int) $value;
        $this->perPage = in_array($size, TableDefaults::PAGE_SIZE_OPTIONS, true) ? $size : TableDefaults::DEFAULT_PAGE_SIZE;
        $this->resetPage(self::PAGE_NAME);
    }

    /** Current custody is re-pinned on every request, including all-data Portal holders. */
    private function ownEmployee(): string
    {
        $access = app(Access::class);

        return $access->hasEmployeeLink() ? $access->ownEmployeeId() : '';
    }

    /** The scoped holdings stamp observes item and custody changes for this employee. */
    protected function changeStamp(): string
    {
        return app(InventoryRecords::class)->holdingsStamp(app(Access::class), $this->own ? $this->ownEmployee() : $this->employeeId, $this->own);
    }

    public function render(): View
    {
        $this->markStampRendered();
        $access = app(Access::class);
        $employeeId = $this->own ? $this->ownEmployee() : $this->employeeId;
        $rows = app(InventoryRecords::class)->employeeHoldingsPage($access, $employeeId, $this->own, (int) $this->getPage(self::PAGE_NAME), $this->perPage);
        $rows->setPageName(self::PAGE_NAME);

        return view('livewire.inventory.employee-items', [
            'rows' => $this->acceptPage($rows, self::PAGE_NAME),
            'hasEmployee' => $employeeId !== '',
            'canViewInventory' => $access->mayReadAllData(ModuleCode::INVENTORY),
            'canViewOwnReceipts' => $access->hasModule(ModuleCode::PORTAL) && $access->hasEmployeeLink() && $access->ownEmployeeId() === $employeeId,
        ]);
    }
}
