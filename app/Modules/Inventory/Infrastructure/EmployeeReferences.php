<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Platform\Retention\EmployeeReferenceSource;
use Illuminate\Support\Facades\Schema;

/** Preserves the employee identities referenced by custody and lifecycle evidence. */
final class EmployeeReferences implements EmployeeReferenceSource
{
    public function references(string $employeeId): bool
    {
        if (Schema::hasTable('inventory_items') && (
            InventoryItem::query()->where('employee_id', $employeeId)->exists()
            || InventoryReceipt::query()->where('employee_id', $employeeId)->orWhere('issuer_employee_id', $employeeId)->exists()
            || InventoryMovement::query()->where('from_employee_id', $employeeId)->orWhere('to_employee_id', $employeeId)->exists()
        )) {
            return true;
        }

        return Schema::hasTable('inventory_incidents') && (
            InventoryIncident::query()->where('employee_id', $employeeId)->orWhere('actor_employee_id', $employeeId)->exists()
            || InventoryCount::query()->where('actor_employee_id', $employeeId)->exists()
            || InventoryCountLine::query()->where('employee_id', $employeeId)->exists()
            || InventoryDisposal::query()->where('authorized_by_employee_id', $employeeId)->exists()
        );
    }
}
