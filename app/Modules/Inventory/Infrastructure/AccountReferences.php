<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Models\InventoryAttachment;
use App\Models\InventoryCount;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Platform\Retention\AccountReferenceSource;
use Illuminate\Support\Facades\Schema;

/** Keeps the recording and reviewing accounts attributable without making them custodians. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        if (Schema::hasTable('inventory_items') && (
            InventoryReceipt::query()->where('recorded_by', $accountId)->exists()
            || InventoryMovement::query()->where('actor_id', $accountId)->exists()
        )) {
            return true;
        }

        return Schema::hasTable('inventory_incidents') && (
            InventoryIncident::query()->where('actor_id', $accountId)->orWhere('reviewed_by', $accountId)->exists()
            || InventoryCount::query()->where('actor_id', $accountId)->orWhere('reviewed_by', $accountId)->exists()
            || InventoryDisposal::query()->where('actor_id', $accountId)->exists()
            || InventoryAttachment::query()->where('uploaded_by', $accountId)->exists()
        );
    }
}
