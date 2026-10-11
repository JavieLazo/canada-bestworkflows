<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryAttachment;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Removes signed-MR metadata first and bytes only after the database commits. */
final class DeleteSignedReceipt
{
    public function delete(Access $access, string $id): void
    {
        InventoryWrite::assertManager($access);
        DB::transaction(function () use ($access, $id): void {
            InventoryWrite::lock();
            $row = InventoryAttachment::query()->lockForUpdate()->findOrFail($id);
            if ($row->receipt_id === null) {
                throw new \RuntimeException('Disposal authorization evidence must be retained.');
            }
            $path = $row->path;
            $row->delete();
            AuditService::record($access, AuditEvent::INVENTORY_SIGNED_MR_DELETED, 'inventory_attachments', $id, 'Signed MR attachment removed.');
            DB::afterCommit(static function () use ($path): void {
                try {
                    if (! Storage::disk('local')->delete($path)) {
                        Log::error('[inventory] attachment cleanup failed', ['path' => $path]);
                    }
                } catch (\Throwable $error) {
                    Log::error('[inventory] attachment cleanup failed', ['path' => $path, 'error' => $error->getMessage()]);
                }
            });
        });
    }
}
