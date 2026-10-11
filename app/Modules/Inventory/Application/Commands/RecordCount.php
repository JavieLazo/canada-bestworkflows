<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryLocation;
use App\Models\InventoryReceipt;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** A count records observations and expected custody without changing either. */
final class RecordCount
{
    /** @param array<string, mixed> $input */
    public function save(Access $access, array $input): string
    {
        InventoryWrite::assertManager($access);
        $data = Validator::make($input, [
            'location_id' => ['required', 'string', 'max:26'],
            'counted_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'string', 'max:26', 'distinct'],
            'lines.*.result' => ['required', Rule::in(['FOUND', 'NOT_FOUND', 'UNEXPECTED'])],
            'lines.*.notes' => ['nullable', 'string', 'max:5000'],
            'lines.*.version' => ['required', 'string', 'size:64'],
        ])->validate();

        return DB::transaction(function () use ($access, $data): string {
            InventoryWrite::lock();
            $location = InventoryLocation::query()->where('id', $data['location_id'])->firstOrFail();
            $date = InventoryWrite::date($data['counted_on']);
            $items = InventoryWrite::items(array_column($data['lines'], 'item_id'))->keyBy('id');
            $employeeIds = [];
            foreach ($items as $item) {
                if ($item->employee_id !== null) {
                    $employeeIds[] = $item->employee_id;
                }
            }
            $employeeIds = array_values(array_unique($employeeIds));
            $employees = app(InventoryEmployees::class)->identities($access, $employeeIds, true);
            $receipts = InventoryReceipt::query()->whereKey($items->pluck('current_receipt_id')->filter()->unique()->all())->get(['id', 'code', 'mr_number'])->keyBy('id')->all();
            $count = new InventoryCount;
            $count->fill(['location_id' => $location->id, 'location_name' => $location->name, 'counted_on' => $date, 'reference' => $data['reference'], 'notes' => $data['notes'] ?? '', 'status' => 'OPEN']);
            $count->actor_id = (int) $access->userId;
            $count->actor_employee_id = $access->employeeId;
            $count->actor_name = mb_substr($access->fullName, 0, 200);
            $count->save();
            foreach ($data['lines'] as $line) {
                $item = $items->get($line['item_id']);
                if ($item === null || $item->status === 'DISPOSED') {
                    throw new \RuntimeException('Every counted item must still exist and must not be disposed.');
                }
                $version = hash('sha256', implode('|', [$item->id, $item->location_id, $item->employee_id, $item->current_receipt_id, $item->updated_at?->format('Y-m-d H:i:s.u')]));
                if (! hash_equals($version, $line['version'])) {
                    throw new \RuntimeException('An item changed after this count sheet was loaded. Reload the sheet and review it.');
                }
                $expected = $item->location_id === $location->id;
                if (($expected && $line['result'] === 'UNEXPECTED') || (! $expected && $line['result'] !== 'UNEXPECTED')) {
                    throw new \RuntimeException('Items outside the selected location must be recorded as unexpected.');
                }
                if ($line['result'] !== 'FOUND' && trim($line['notes'] ?? '') === '') {
                    throw new \RuntimeException('Explain every count discrepancy.');
                }
                InventoryWrite::itemDate($item, $date);
                InventoryCountLine::query()->create([
                    'count_id' => $count->id, 'item_id' => $item->id,
                    'employee_id' => $item->employee_id, 'receipt_id' => $item->current_receipt_id,
                    'item_snapshot' => $item->only(['code', 'name', 'serial_no', 'location_id', 'condition', 'status', 'employee_id', 'current_receipt_id']) + ['employee_name' => $employees[$item->employee_id]['name'] ?? '', 'receipt_code' => $receipts[$item->current_receipt_id]->code ?? '', 'receipt_mr_number' => $receipts[$item->current_receipt_id]->mr_number ?? ''],
                    'result' => $line['result'], 'notes' => $line['notes'] ?? '',
                ]);
            }
            AuditService::record($access, AuditEvent::INVENTORY_COUNT_RECORDED, 'inventory_counts', $count->id, "Count {$data['reference']} recorded; custody and conditions retained.");

            return $count->id;
        });
    }
}
