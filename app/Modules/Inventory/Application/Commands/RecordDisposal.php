<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryAttachment;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Platform\Files\AttachmentStore;
use App\Platform\Files\UploadLimits;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Records existing office authority and evidence; software does not grant disposal approval. */
final class RecordDisposal
{
    /** @param array<string, mixed> $input */
    public function save(Access $access, array $input, UploadedFile $file): string
    {
        InventoryWrite::assertManager($access);
        $data = Validator::make($input, [
            'item_id' => ['required', 'string', 'max:26'],
            'disposed_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:5000'],
            'authorization_reference' => ['required', 'string', 'max:160'],
            'authorized_by_employee_id' => ['required', 'string', 'max:26'],
        ])->validate();
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new \RuntimeException('Authorization evidence must be PDF, JPEG or PNG.');
        }
        if ((int) $file->getSize() < 1 || (int) $file->getSize() > UploadLimits::maxFileBytes()) {
            throw new \RuntimeException('The file exceeds the configured attachment size limit or is empty.');
        }

        return app(AttachmentStore::class)->store($file, 'inventory-evidence', (string) Str::ulid(), function (string $path) use ($access, $data, $file, $mime): string {
            $item = InventoryItem::query()->where('id', $data['item_id'])->lockForUpdate()->firstOrFail();
            if ($item->status !== 'IN_STOCK' || $item->employee_id !== null || $item->current_receipt_id !== null || $item->condition !== 'UNSERVICEABLE') {
                throw new \RuntimeException('Only an unassigned, unserviceable item in stock can be disposed.');
            }
            if (InventoryIncident::query()->where('item_id', $item->id)->where('status', 'OPEN')->exists()) {
                throw new \RuntimeException('Review this item’s open condition report before disposal.');
            }
            if ($access->hasEmployeeLink() && $access->ownEmployeeId() === $data['authorized_by_employee_id']) {
                throw new \RuntimeException('The recorded approving employee must differ from the recorder.');
            }
            $date = InventoryWrite::date($data['disposed_on'], $item->acquired_on->format('Y-m-d'));
            InventoryWrite::itemDate($item, $date);
            $authority = InventoryWrite::employee($access, $data['authorized_by_employee_id'], false);
            $row = new InventoryDisposal;
            $row->fill($data + ['authorized_by_snapshot' => $authority, 'item_snapshot' => $item->only(['code', 'name', 'serial_no', 'condition', 'status', 'location_id', 'cost'])]);
            $row->actor_id = (int) $access->userId;
            $row->actor_name = mb_substr($access->fullName, 0, 200);
            $row->save();
            $attachment = new InventoryAttachment;
            $attachment->fill(['disposal_id' => $row->id, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 300), 'mime' => $mime, 'size' => (int) $file->getSize(), 'path' => $path]);
            $attachment->uploaded_by = (int) $access->userId;
            $attachment->save();
            $item->status = 'DISPOSED';
            $item->save();
            InventoryWrite::movement($access, $item, 'DISPOSED', $date, null, null, null, $data['authorization_reference'].' — '.$data['reason']);
            AuditService::record($access, AuditEvent::INVENTORY_DISPOSAL_RECORDED, 'inventory_disposals', $row->id, "{$item->code} disposed under {$data['authorization_reference']}.");

            return $row->id;
        }, ceiling: UploadLimits::storageCeilingBytes(), lockOwner: static fn () => InventoryWrite::lock());
    }
}
