<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** A condition report changes condition while preserving employee and MR custody. */
final class RecordIncident
{
    /** @param array<string, mixed> $input */
    public function save(Access $access, array $input): string
    {
        InventoryWrite::assertManager($access);
        $data = Validator::make($input, [
            'item_id' => ['required', 'string', 'max:26'],
            'kind' => ['required', Rule::in(['DAMAGE', 'LOSS', 'INSPECTION'])],
            'reported_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['required', 'string', 'max:160'],
            'details' => ['required', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($access, $data): string {
            InventoryWrite::lock();
            $item = InventoryItem::query()->where('id', $data['item_id'])->lockForUpdate()->firstOrFail();
            if ($item->status === 'DISPOSED') {
                throw new \RuntimeException('A disposed item cannot receive a condition report.');
            }
            if (InventoryIncident::query()->where('item_id', $item->id)->where('status', 'OPEN')->exists()) {
                throw new \RuntimeException('Review the open report for this item before recording another.');
            }
            $date = InventoryWrite::date($data['reported_on'], $item->acquired_on->format('Y-m-d'));
            InventoryWrite::itemDate($item, $date);
            $incident = new InventoryIncident;
            $incident->fill($data + [
                'employee_id' => $item->employee_id,
                'receipt_id' => $item->current_receipt_id,
                'item_snapshot' => $item->only(['code', 'name', 'serial_no', 'condition', 'status', 'location_id', 'employee_id', 'current_receipt_id']),
                'employee_snapshot' => $item->employee_id ? InventoryWrite::employee($access, $item->employee_id, false) : null,
                'status' => 'OPEN',
            ]);
            $incident->actor_id = (int) $access->userId;
            $incident->actor_employee_id = $access->employeeId;
            $incident->actor_name = mb_substr($access->fullName, 0, 200);
            $incident->save();
            $item->condition = match ($data['kind']) {
                'DAMAGE' => 'DAMAGED', 'LOSS' => 'MISSING', default => 'UNSERVICEABLE',
            };
            $item->save();
            InventoryWrite::movement($access, $item, 'CONDITION_REPORTED', $date, $item->current_receipt_id, $item->employee_id, $item->employee_id, $data['reference'].' — '.$data['details']);
            AuditService::record($access, AuditEvent::INVENTORY_INCIDENT_RECORDED, 'inventory_incidents', $incident->id, "{$data['kind']} report for {$item->code}; custody retained.");

            return $incident->id;
        });
    }
}
