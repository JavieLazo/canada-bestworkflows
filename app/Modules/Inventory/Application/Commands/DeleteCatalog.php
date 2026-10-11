<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryCategory;
use App\Models\InventoryCount;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Retains catalog names while live items or physical-count evidence reference them. */
final class DeleteCatalog
{
    public function handle(Access $actor, string $kind, string $id): void
    {
        InventoryWrite::assertManager($actor);
        $model = match ($kind) {
            'category' => InventoryCategory::class,
            'location' => InventoryLocation::class,
            default => throw new RuntimeException('Unknown inventory catalog.'),
        };
        DB::transaction(function () use ($model, $kind, $id): void {
            InventoryWrite::lock();
            if (InventoryItem::query()->where($kind.'_id', $id)->exists()) {
                throw new RuntimeException('That entry is still used by inventory items.');
            }
            if ($kind === 'location' && InventoryCount::query()->where('location_id', $id)->exists()) {
                throw new RuntimeException('That location has physical-count history and cannot be deleted.');
            }
            $row = $model::query()->lockForUpdate()->find($id);
            if ($row === null) {
                throw new RuntimeException('That catalog entry no longer exists.');
            }
            $row->delete();
        });
        AuditService::record($actor, AuditEvent::INVENTORY_CATALOG_DELETED, 'inventory_'.$kind, $id, "Inventory {$kind} deleted");
    }
}
