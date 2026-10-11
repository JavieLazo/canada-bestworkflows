<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Platform\Files\StorageUsageSource;
use App\Platform\Files\StoredFiles;

/** This owner contributes metadata bytes to the shared storage budget. */
final class StorageUsage implements StorageUsageSource, StoredFiles
{
    public function bytes(): int
    {
        return (int) EmployeeDocument::query()->sum('size');
    }

    public function references(): iterable
    {
        foreach (EmployeeDocument::query()->select(['id', 'path'])->lazy() as $row) {
            yield ['disk' => 'local', 'path' => $row->path];
        }
        foreach (EmployeeProfile::query()->whereNotNull('photo_path')->select(['id', 'photo_path'])->lazy() as $row) {
            if ($row->photo_path !== null && $row->photo_path !== '') {
                yield ['disk' => 'public', 'path' => $row->photo_path];
            }
        }
    }
}
