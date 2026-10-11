<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Models\InventoryAttachment;
use App\Platform\Files\StorageUsageSource;
use App\Platform\Files\StoredFiles;
use Illuminate\Support\Facades\Schema;

/** Inventory evidence shares the private document budget and file-integrity checks. */
final class StorageUsage implements StorageUsageSource, StoredFiles
{
    public function bytes(): int
    {
        return Schema::hasTable('inventory_attachments') ? (int) InventoryAttachment::query()->sum('size') : 0;
    }

    public function references(): iterable
    {
        if (! Schema::hasTable('inventory_attachments')) {
            return;
        }
        foreach (InventoryAttachment::query()->select(['id', 'path'])->lazy() as $row) {
            yield ['disk' => 'local', 'path' => $row->path];
        }
    }
}
