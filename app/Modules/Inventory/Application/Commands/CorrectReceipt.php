<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Supersedes an untouched receipt for a clerical correction, never inventing a physical return. */
final class CorrectReceipt
{
    public function handle(Access $actor, string $receiptId, string $employeeId, string $issuerId, string $date, string $mrNumber, string $reason): string
    {
        InventoryWrite::assertManager($actor);
        $date = InventoryWrite::date($date);
        $mrNumber = InventoryWrite::mrNumber($mrNumber);
        $reason = InventoryWrite::remarks($reason, true);
        $replacement = DB::transaction(function () use ($actor, $receiptId, $employeeId, $issuerId, $date, $mrNumber, $reason) {
            InventoryWrite::lock();
            $employee = InventoryWrite::employee($actor, $employeeId);
            $issuer = InventoryWrite::employee($actor, $issuerId);
            $original = InventoryReceipt::query()->lockForUpdate()->find($receiptId);
            if ($original === null || $original->status !== 'OPEN') {
                throw new RuntimeException('Only an open receipt whose original items are still held can be corrected.');
            }
            InventoryWrite::date($date, $original->issued_on->format('Y-m-d'));
            $lines = InventoryReceiptLine::query()->where('receipt_id', $receiptId)->lockForUpdate()->get();
            if ($lines->isEmpty() || $lines->contains(fn (InventoryReceiptLine $line): bool => $line->status !== 'ISSUED')) {
                throw new RuntimeException('This receipt already has a return or transfer. Its history cannot be replaced.');
            }
            $items = InventoryWrite::items($lines->pluck('item_id')->all());
            foreach ($items as $item) {
                if ($item->status !== 'ISSUED' || $item->current_receipt_id !== $receiptId) {
                    throw new RuntimeException('An original item is no longer held on this receipt.');
                }
                InventoryWrite::itemDate($item, $date);
            }
            $replacement = InventoryWrite::receipt($actor, $employee, $issuer, $date, $mrNumber, $reason, $receiptId);
            foreach ($items as $item) {
                $from = $item->employee_id;
                InventoryWrite::endLine($item, 'SUPERSEDED', $date);
                InventoryWrite::attach($item, $replacement);
                InventoryWrite::movement($actor, $item, 'CORRECTED', $date, $replacement->id, $from, $employeeId, $reason);
            }
            $original->fill(['status' => 'SUPERSEDED'])->save();

            return $replacement;
        });
        AuditService::record($actor, AuditEvent::INVENTORY_RECEIPT_CORRECTED, 'inventory_receipts', $replacement->id, "MR {$replacement->mr_number} replaces a receipt — {$reason}");

        return $replacement->id;
    }
}
