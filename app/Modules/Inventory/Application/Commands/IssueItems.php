<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Issues one receipt for a locked batch of serviceable stock to an employee record. */
final class IssueItems
{
    /** @param array<mixed> $itemIds */
    public function handle(Access $actor, array $itemIds, string $employeeId, string $issuerId, string $date, string $mrNumber, string $remarks = ''): string
    {
        InventoryWrite::assertManager($actor);
        $date = InventoryWrite::date($date);
        $mrNumber = InventoryWrite::mrNumber($mrNumber);
        $remarks = InventoryWrite::remarks($remarks);
        $receipt = DB::transaction(function () use ($actor, $itemIds, $employeeId, $issuerId, $date, $mrNumber, $remarks) {
            InventoryWrite::lock();
            $employee = InventoryWrite::employee($actor, $employeeId);
            $issuer = InventoryWrite::employee($actor, $issuerId);
            $items = InventoryWrite::items($itemIds);
            foreach ($items as $item) {
                if ($item->status !== 'IN_STOCK' || $item->condition !== 'SERVICEABLE') {
                    throw new RuntimeException('Only serviceable stock can be issued. Refresh the selected items.');
                }
                InventoryWrite::itemDate($item, $date);
            }
            $receipt = InventoryWrite::receipt($actor, $employee, $issuer, $date, $mrNumber, $remarks);
            foreach ($items as $item) {
                InventoryWrite::attach($item, $receipt);
                InventoryWrite::movement($actor, $item, 'ISSUED', $date, $receipt->id, null, $employeeId, $remarks);
            }

            return $receipt;
        });
        AuditService::record($actor, AuditEvent::INVENTORY_ISSUED, 'inventory_receipts', $receipt->id, "MR {$receipt->mr_number} issued");

        return $receipt->id;
    }
}
