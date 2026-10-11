<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryCategory;
use App\Models\InventoryLocation;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Maintains reusable category/location names while historical papers keep their copies. */
final class SaveCatalog
{
    public function handle(Access $actor, string $kind, ?string $id, string $name): string
    {
        InventoryWrite::assertManager($actor);
        $model = match ($kind) {
            'category' => InventoryCategory::class,
            'location' => InventoryLocation::class,
            default => throw new RuntimeException('Unknown inventory catalog.'),
        };
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new RuntimeException('A name of at most 160 characters is required.');
        }
        $saved = DB::transaction(function () use ($model, $id, $name): string {
            InventoryWrite::lock();
            $duplicates = $model::query()->where('name', $name);
            if ($id !== null) {
                $duplicates->whereKeyNot($id);
            }
            if ($duplicates->exists()) {
                throw new RuntimeException('That name is already in use.');
            }
            $row = $id === null ? new $model : $model::query()->lockForUpdate()->find($id);
            if ($row === null) {
                throw new RuntimeException('That catalog entry no longer exists.');
            }
            $row->fill(['name' => $name])->save();

            return $row->id;
        });
        AuditService::record($actor, AuditEvent::INVENTORY_CATALOG_SAVED, 'inventory_'.$kind, $saved, "Inventory {$kind} — {$name}");

        return $saved;
    }
}
