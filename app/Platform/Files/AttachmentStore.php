<?php

declare(strict_types=1);

namespace App\Platform\Files;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Compensates private file writes when their database metadata cannot be committed.
 *
 * A `$ceiling` is re-checked inside the transaction, under the
 * `copems:attachment-budget` lock, against the business budget
 * (AttachmentUsage: documents, task files and inventory evidence) — or against `$usage` for an
 * owner that keeps its own budget (Community and Chat). Every budget shares
 * that one lock row, which only makes concurrent uploads wait their turn.
 */
final class AttachmentStore
{
    /**
     * @template T
     *
     * @param  Closure(string): T  $persist
     * @param  (Closure(): int)|null  $usage  bytes already counted against `$ceiling`; the business budget when null
     * @param  string|null  $fullMessage  what a full budget says; the business budget's wording when null
     * @param  (Closure(): void)|null  $lockOwner  owner locks taken after the budget lock, before a consistent read can establish a stale snapshot
     * @return T
     */
    public function store(UploadedFile $file, string $directory, string $name, Closure $persist, ?int $ceiling = null, ?Closure $usage = null, ?string $fullMessage = null, ?Closure $lockOwner = null): mixed
    {
        $disk = Storage::disk('local');
        $expectedPath = trim($directory, '/').'/'.$name;
        if ($disk->exists($expectedPath)) {
            throw new RuntimeException('That storage path is already in use.');
        }
        try {
            return DB::transaction(function () use ($file, $directory, $name, $persist, $disk, $ceiling, $usage, $fullMessage, $lockOwner) {
                if ($ceiling !== null) {
                    $lock = DB::table('locks')->where('name', 'copems:attachment-budget')->lockForUpdate()->first();
                    if ($lock === null) {
                        throw new RuntimeException('The attachment budget lock is missing. Run the pending migrations.');
                    }
                }
                if ($lockOwner !== null) {
                    $lockOwner();
                }
                if ($ceiling !== null) {
                    $used = $usage !== null ? (int) $usage() : AttachmentUsage::bytes();
                    if ($used + (int) $file->getSize() > $ceiling) {
                        throw new RuntimeException($fullMessage ?? 'The file store is full. Delete old attachments before uploading more.');
                    }
                }
                $path = $file->storeAs($directory, $name, 'local');
                // Livewire's temporary file may return a path even when the disk refused the write.
                if ($path === false || ! $disk->exists($path)) {
                    throw new RuntimeException('The file could not be stored.');
                }
                // A caller can still roll back after this nested transaction
                // returns. Keep the bytes tied to the outermost outcome too.
                DB::afterRollBack(fn () => $this->discard($path));

                return $persist($path);
            });
        } catch (Throwable $error) {
            $this->discard($expectedPath);
            throw $error;
        }
    }

    private function discard(string $path): void
    {
        try {
            $disk = Storage::disk('local');
            if ($disk->exists($path) && ! $disk->delete($path)) {
                Log::error('[attachments] cleanup failed', ['path' => $path]);
            }
        } catch (Throwable $error) {
            Log::error('[attachments] cleanup failed', ['path' => $path, 'error' => $error->getMessage()]);
        }
    }
}
