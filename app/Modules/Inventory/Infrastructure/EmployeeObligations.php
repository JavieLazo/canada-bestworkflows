<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Models\InventoryItem;
use App\Models\InventoryReceipt;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Platform\Retention\EmployeeObligationSource;
use Illuminate\Support\Facades\Schema;

/** Keeps issued property visible during offboarding and serializes it with new issues. */
final class EmployeeObligations implements EmployeeObligationSource
{
    public function lock(): void
    {
        if (Schema::hasTable('inventory_items')) {
            InventoryWrite::lock();
        }
    }

    public function outstanding(string $employeeId): array
    {
        if (! Schema::hasTable('inventory_items')) {
            return [];
        }
        $items = InventoryItem::query()->where('employee_id', $employeeId)->where('status', 'ISSUED')->orderBy('name')->get();
        $receipts = InventoryReceipt::query()->whereKey($items->pluck('current_receipt_id')->filter()->unique()->all())->pluck('mr_number', 'id');

        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'label' => $item->code.' · '.$item->name,
                'reference' => 'MR '.($receipts[$item->current_receipt_id] ?? '—'),
            ];
        }

        return $rows;
    }
}
