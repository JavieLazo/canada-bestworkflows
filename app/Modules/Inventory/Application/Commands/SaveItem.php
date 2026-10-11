<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Edits the live register without rewriting receipt snapshots or custody. */
final class SaveItem
{
    /** @param array<string,mixed> $input */
    public function handle(Access $actor, ?string $id, array $input): string
    {
        InventoryWrite::assertManager($actor);
        if (is_string($input['code'] ?? null)) {
            $input['code'] = trim($input['code']);
        }
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:240'], 'serial_no' => ['nullable', 'string', 'max:160'],
            'acquired_on' => ['required', 'string'], 'cost' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'category_id' => ['nullable', 'string', 'max:26'], 'location_id' => ['nullable', 'string', 'max:26'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ], [], ['code' => 'item code'])->validate();
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw new RuntimeException('An item name is required.');
        }
        $data['acquired_on'] = InventoryWrite::date($data['acquired_on']);
        $data['cost'] = Money::round($data['cost']);
        $data['serial_no'] = trim((string) ($data['serial_no'] ?? '')) ?: null;
        $data['category_id'] = ($data['category_id'] ?? '') ?: null;
        $data['location_id'] = ($data['location_id'] ?? '') ?: null;
        $data['remarks'] = InventoryWrite::remarks((string) ($data['remarks'] ?? ''));
        $row = DB::transaction(function () use ($id, $data): InventoryItem {
            InventoryWrite::lock();
            foreach (['category_id' => InventoryCategory::class, 'location_id' => InventoryLocation::class] as $key => $model) {
                if ($data[$key] !== null && ! $model::query()->whereKey($data[$key])->exists()) {
                    throw new RuntimeException('Choose an existing category and location.');
                }
            }
            $row = $id === null ? new InventoryItem : InventoryItem::query()->lockForUpdate()->find($id);
            if ($row === null) {
                throw new RuntimeException('That inventory item no longer exists.');
            }
            $duplicate = InventoryItem::query()->where('code', $data['code']);
            if ($id !== null) {
                $duplicate->whereKeyNot($id);
            }
            if ($duplicate->exists()) {
                throw new RuntimeException('That item code is already in use. Enter a different code.');
            }
            if ($id !== null) {
                if ($row->status === 'DISPOSED') {
                    throw new RuntimeException('Disposed items retain their recorded details.');
                }
                $firstDate = InventoryMovement::query()->where('item_id', $id)->min('occurred_on');
                if (is_string($firstDate) && $data['acquired_on'] > $firstDate) {
                    throw new RuntimeException('Acquisition cannot be after this item’s first recorded movement.');
                }
            } else {
                $row->fill(['condition' => 'SERVICEABLE', 'status' => 'IN_STOCK']);
            }
            try {
                $row->fill($data)->save();
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('That item code is already in use. Enter a different code.');
            }

            return $row;
        });
        AuditService::record($actor, $id === null ? AuditEvent::INVENTORY_ITEM_CREATED : AuditEvent::INVENTORY_ITEM_UPDATED, 'inventory_items', $row->id, "Item {$row->code} — {$row->name}");

        return $row->id;
    }
}
