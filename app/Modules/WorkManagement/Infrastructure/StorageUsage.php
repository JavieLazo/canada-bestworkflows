<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Models\ProjectTaskFile;
use App\Platform\Files\StorageUsageSource;
use App\Platform\Files\StoredFiles;

/** This owner contributes metadata bytes to the shared storage budget. */
final class StorageUsage implements StorageUsageSource, StoredFiles
{
    public function bytes(): int
    {
        return (int) ProjectTaskFile::query()->sum('size');
    }

    public function references(): iterable
    {
        foreach (ProjectTaskFile::query()->select(['id', 'path'])->lazy() as $row) {
            yield ['disk' => 'local', 'path' => $row->path];
        }
    }
}
