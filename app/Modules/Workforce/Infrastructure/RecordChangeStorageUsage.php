<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Models\EmployeeDocument;
use App\Models\EmployeeRecordChangeRequest;
use App\Platform\Files\StorageUsageSource;
use App\Platform\Files\StoredFiles;
use Illuminate\Support\Facades\Schema;

/** Counts retained proposal evidence once, including files no longer on the official record. */
final class RecordChangeStorageUsage implements StorageUsageSource, StoredFiles
{
    public function bytes(): int
    {
        $active = array_fill_keys(EmployeeDocument::query()->pluck('path')->all(), true);
        $total = 0;
        foreach ($this->files() as $path => $size) {
            if (! isset($active[$path])) {
                $total += $size;
            }
        }

        return $total;
    }

    public function references(): iterable
    {
        foreach ($this->files() as $path => $size) {
            yield ['disk' => 'local', 'path' => $path];
        }
    }

    public function retains(string $path): bool
    {
        return array_key_exists($path, $this->files());
    }

    /** @return array<string, int> */
    private function files(): array
    {
        if (! Schema::hasTable('employee_record_change_requests')) {
            return [];
        }
        $files = [];
        foreach (EmployeeRecordChangeRequest::query()->select(['id', 'form'])->lazy() as $request) {
            foreach (['document', 'photo_previous', 'photo_proposed'] as $key) {
                $path = $request->form[$key.'_path'] ?? null;
                if (! is_string($path) || $path === '' || isset($files[$path])) {
                    continue;
                }
                $size = $request->form[$key.'_size'] ?? 0;
                $files[$path] = is_numeric($size) ? (int) $size : 0;
            }
        }

        return $files;
    }
}
