<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryAttachment;
use App\Models\InventoryReceipt;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Platform\Files\AttachmentStore;
use App\Platform\Files\UploadLimits;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Stores signed receipt evidence privately after current authorization and budget checks. */
final class AttachSignedReceipt
{
    public function save(Access $access, string $receiptId, UploadedFile $file): string
    {
        InventoryWrite::assertManager($access);
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new \RuntimeException('Choose a PDF, JPEG or PNG file.');
        }
        if ((int) $file->getSize() < 1 || (int) $file->getSize() > UploadLimits::maxFileBytes()) {
            throw new \RuntimeException('The file exceeds the configured attachment size limit or is empty.');
        }
        InventoryReceipt::query()->findOrFail($receiptId);

        return app(AttachmentStore::class)->store($file, 'inventory-evidence', (string) Str::ulid(), function (string $path) use ($access, $receiptId, $file, $mime): string {
            InventoryReceipt::query()->lockForUpdate()->findOrFail($receiptId);
            $row = new InventoryAttachment;
            $row->fill(['receipt_id' => $receiptId, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 300), 'mime' => $mime, 'size' => (int) $file->getSize(), 'path' => $path]);
            $row->uploaded_by = (int) $access->userId;
            $row->save();
            AuditService::record($access, AuditEvent::INVENTORY_SIGNED_MR_ATTACHED, 'inventory_attachments', $row->id, "Signed evidence attached to MR {$receiptId}.");

            return $row->id;
        }, ceiling: UploadLimits::storageCeilingBytes(), lockOwner: static fn () => InventoryWrite::lock());
    }
}
