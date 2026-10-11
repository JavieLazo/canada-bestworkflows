<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\InventoryAttachment;
use App\Models\InventoryReceipt;
use App\Modules\Inventory\Contracts\Views\AttachmentView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reads private evidence only after checking the receipt or manager's current access. */
final class InventoryAttachments
{
    /** @return Collection<int, AttachmentView> */
    public function receipt(Access $access, string $receiptId, bool $own = false): Collection
    {
        $this->authorizeReceipt($access, $receiptId, $own);

        return InventoryAttachment::query()->where('receipt_id', $receiptId)->orderByDesc('created_at')->get()->map(static fn (InventoryAttachment $row): AttachmentView => Snapshot::of($row, AttachmentView::class));
    }

    /** @return Collection<int, AttachmentView> */
    public function disposal(Access $access, string $disposalId): Collection
    {
        $this->assertManager($access);

        return InventoryAttachment::query()->where('disposal_id', $disposalId)->orderByDesc('created_at')->get()->map(static fn (InventoryAttachment $row): AttachmentView => Snapshot::of($row, AttachmentView::class));
    }

    public function download(Access $access, string $id, bool $own = false): StreamedResponse
    {
        $row = InventoryAttachment::query()->findOrFail($id);
        if ($row->receipt_id !== null) {
            $this->authorizeReceipt($access, $row->receipt_id, $own);
        } else {
            abort_if($own, 403);
            $this->assertManager($access);
        }
        $disk = Storage::disk('local');
        abort_unless($disk->exists($row->path), 404, 'The stored file is missing.');

        return $disk->download($row->path, $row->file_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }

    private function authorizeReceipt(Access $access, string $id, bool $own): void
    {
        $row = InventoryReceipt::query()->findOrFail($id);
        if ($own) {
            abort_unless($access->isSignedIn() && $access->hasModule(ModuleCode::PORTAL) && $access->hasEmployeeLink() && $row->employee_id === $access->ownEmployeeId(), 403);

            return;
        }
        abort_unless($access->isSignedIn() && $access->hasModule(ModuleCode::INVENTORY), 403);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY) || ($access->hasEmployeeLink() && $row->employee_id === $access->ownEmployeeId()), 403);
    }

    private function assertManager(Access $access): void
    {
        abort_unless($access->isSignedIn() && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
    }
}
