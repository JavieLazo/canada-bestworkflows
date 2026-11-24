<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Review records the evidence decision without deciding financial liability; any Inventory manager, the reporter included, may review. */
final class ResolveIncident
{
    /** @param array<string, mixed> $input */
    public function save(Access $access, string $id, array $input): void
    {
        InventoryWrite::assertManager($access);
        $data = Validator::make($input, [
            'resolution' => ['required', 'string', 'max:5000'],
            'resolution_reference' => ['required', 'string', 'max:160'],
            'condition' => ['required', Rule::in(['SERVICEABLE', 'DAMAGED', 'UNSERVICEABLE', 'MISSING'])],
        ])->validate();
        DB::transaction(function () use ($access, $id, $data): void {
            InventoryWrite::lock();
            $row = InventoryIncident::query()->lockForUpdate()->findOrFail($id);
            if ($row->status !== 'OPEN') {
                throw new \RuntimeException('This report has already been reviewed.');
            }
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($row->item_id);
            if ($item->status === 'DISPOSED') {
                throw new \RuntimeException('A disposed item cannot change condition.');
            }
            $item->condition = $data['condition'];
            $item->save();
            $row->fill(['status' => 'RESOLVED', 'resolution' => $data['resolution'], 'resolution_reference' => $data['resolution_reference'], 'resolved_at' => now()->utc()]);
            $row->reviewed_by = (int) $access->userId;
            $row->reviewer_name = mb_substr($access->fullName, 0, 200);
            $row->save();
            InventoryWrite::movement($access, $item, 'CONDITION_REVIEWED', LocalClock::dayKey(now()), $item->current_receipt_id, $item->employee_id, $item->employee_id, $data['resolution_reference'].' — '.$data['resolution']);
            AuditService::record($access, AuditEvent::INVENTORY_INCIDENT_RESOLVED, 'inventory_incidents', $row->id, "Condition reviewed for {$item->code}; custody retained.");
        });
    }
}
