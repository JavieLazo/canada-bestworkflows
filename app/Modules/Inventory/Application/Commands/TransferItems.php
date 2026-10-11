<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Moves a holder's selected items onto a new receipt while preserving every original line. */
final class TransferItems
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
            if ($items->pluck('employee_id')->unique()->count() !== 1) {
                throw new RuntimeException('A transfer batch must have one current holder.');
            }
            foreach ($items as $item) {
                if ($item->status !== 'ISSUED' || $item->condition !== 'SERVICEABLE' || $item->employee_id === $employeeId) {
                    throw new RuntimeException('Transfer serviceable issued items to a different employee.');
                }
                InventoryWrite::itemDate($item, $date);
            }
            $receipt = InventoryWrite::receipt($actor, $employee, $issuer, $date, $mrNumber, $remarks);
            foreach ($items as $item) {
                $from = $item->employee_id;
                $old = InventoryWrite::endLine($item, 'TRANSFERRED', $date);
                InventoryWrite::attach($item, $receipt);
                InventoryWrite::movement($actor, $item, 'TRANSFERRED', $date, $receipt->id, $from, $employeeId, $remarks);
                InventoryWrite::closeReceipt($old);
            }

            return $receipt;
        });
        AuditService::record($actor, AuditEvent::INVENTORY_TRANSFERRED, 'inventory_receipts', $receipt->id, "Items transferred onto MR {$receipt->mr_number}");

        return $receipt->id;
    }
}
