<?php

declare(strict_types=1);

namespace App\Platform\Files;

use Illuminate\Support\Facades\Storage;

/**
 * Observational only. A grace period keeps an in-flight upload out of orphan reports.
 *
 * It reads `stored-file-sources`, every owner that keeps files on the disk,
 * not `attachment-usage-sources`: Community and Chat keep their own storage
 * budget, so they are in the first list and not the second.
 */
final class FileConsistency
{
    /** @return array{missing: list<array{disk: string, path: string}>, unreferenced: list<array{disk: string, path: string}>} */
    public function inspect(int $graceSeconds = 86400): array
    {
        $known = [];
        $missing = [];
        $unreferenced = [];
        foreach (app()->tagged('stored-file-sources') as $source) {
            if (! $source instanceof StoredFiles) {
                continue;
            }
            foreach ($source->references() as $file) {
                $known[$file['disk']][$file['path']] = true;
                if (! Storage::disk($file['disk'])->exists($file['path'])) {
                    $missing[] = $file;
                }
            }
        }
        $before = time() - max(0, $graceSeconds);
        foreach (['local' => ['employee-documents', 'employee-record-changes', 'record-change-documents', 'task-files', 'community-photos', 'community-files', 'chat-photos', 'chat-files'], 'public' => ['portraits']] as $name => $directories) {
            $disk = Storage::disk($name);
            foreach ($directories as $directory) {
                foreach ($disk->allFiles($directory) as $path) {
                    if (! isset($known[$name][$path]) && $disk->lastModified($path) < $before) {
                        $unreferenced[] = ['disk' => $name, 'path' => $path];
                    }
                }
            }
        }

        return ['missing' => $missing, 'unreferenced' => $unreferenced];
    }
}
