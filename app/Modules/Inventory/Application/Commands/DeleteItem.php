<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryCountLine;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReceiptLine;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Removes only unused stock; custody and lifecycle evidence are retained. */
final class DeleteItem
{
    public function handle(Access $actor, string $id): void
    {
        InventoryWrite::assertManager($actor);
        $code = DB::transaction(function () use ($id): string {
            InventoryWrite::lock();
            $item = InventoryItem::query()->lockForUpdate()->find($id);
            if ($item === null || $item->status !== 'IN_STOCK'
                || InventoryReceiptLine::query()->where('item_id', $id)->exists()
                || InventoryMovement::query()->where('item_id', $id)->exists()
                || InventoryCountLine::query()->where('item_id', $id)->exists()
                || InventoryIncident::query()->where('item_id', $id)->exists()
                || InventoryDisposal::query()->where('item_id', $id)->exists()) {
                throw new RuntimeException('Only unused stock without recorded history can be deleted.');
            }
            $code = $item->code;
            $item->delete();

            return $code;
        });
        AuditService::record($actor, AuditEvent::INVENTORY_ITEM_DELETED, 'inventory_items', $id, "Item {$code} deleted");
    }
}
