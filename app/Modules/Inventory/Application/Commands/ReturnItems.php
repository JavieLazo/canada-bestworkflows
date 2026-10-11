<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Records actual returns without erasing condition issues or the original receipt. */
final class ReturnItems
{
    /** @param array<mixed> $itemIds */
    public function handle(Access $actor, array $itemIds, string $date, string $remarks = ''): void
    {
        InventoryWrite::assertManager($actor);
        $date = InventoryWrite::date($date);
        $remarks = InventoryWrite::remarks($remarks);
        DB::transaction(function () use ($actor, $itemIds, $date, $remarks): void {
            InventoryWrite::lock();
            $items = InventoryWrite::items($itemIds);
            foreach ($items as $item) {
                if ($item->condition === 'MISSING') {
                    throw new RuntimeException('A missing item cannot be returned until it is found and reviewed.');
                }
                InventoryWrite::itemDate($item, $date);
                $from = $item->employee_id;
                $receipt = InventoryWrite::endLine($item, 'RETURNED', $date);
                $item->fill(['status' => 'IN_STOCK', 'employee_id' => null, 'current_receipt_id' => null])->save();
                InventoryWrite::movement($actor, $item, 'RETURNED', $date, $receipt, $from, null, $remarks);
                InventoryWrite::closeReceipt($receipt);
            }
            AuditService::record($actor, AuditEvent::INVENTORY_RETURNED, 'inventory_items', null, $items->count().' inventory items returned');
        });
    }
}
