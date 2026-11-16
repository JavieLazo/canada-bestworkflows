<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\WithChangeStamp;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** A self-service receipt is always restricted to the account's own employee record. */
#[Title('My Memorandum Receipt')]
class PortalReceiptPage extends Component
{
    use WithChangeStamp;

    #[Locked]
    public string $receiptId;

    public function mount(string $id): void
    {
        $this->receiptId = $id;
    }

    /** Own custody changes refresh line statuses; the attachment component watches its own files. */
    protected function changeStamp(): string
    {
        $access = app(Access::class);

        return app(InventoryRecords::class)->holdingsStamp($access, $access->hasEmployeeLink() ? $access->ownEmployeeId() : '', true);
    }

    public function render(): View
    {
        $this->markStampRendered();
        $receipt = app(InventoryRecords::class)->receipt(app(Access::class), $this->receiptId, true);
        abort_if($receipt === null, 404);

        return view('livewire.inventory.portal-receipt-page', ['receipt' => $receipt]);
    }
}
