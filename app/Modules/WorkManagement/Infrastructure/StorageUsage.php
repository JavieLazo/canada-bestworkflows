<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Models\ProjectRecordFile;
use App\Models\ProjectTaskFile;
use App\Platform\Files\StorageUsageSource;
use App\Platform\Files\StoredFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** This owner contributes metadata bytes to the shared storage budget. */
final class StorageUsage implements StorageUsageSource, StoredFiles
{
    public function bytes(): int
    {
        $records = Schema::hasTable('project_record_files') ? (int) DB::query()->fromSub(ProjectRecordFile::query()->select('path')->selectRaw('MAX(file_size) AS bytes')->groupBy('path'), 'record_files')->sum('bytes') : 0;

        return (int) ProjectTaskFile::query()->sum('size') + $records;
    }

    public function references(): iterable
    {
        foreach (ProjectTaskFile::query()->select(['id', 'path'])->lazy() as $row) {
            yield ['disk' => 'local', 'path' => $row->path];
        }
        if (Schema::hasTable('project_record_files')) {
            foreach (ProjectRecordFile::query()->select('path')->distinct()->lazy() as $row) {
                yield ['disk' => 'local', 'path' => $row->path];
            }
        }
    }
}
