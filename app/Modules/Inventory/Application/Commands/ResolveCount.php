<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\InventoryCount;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** A reviewer closes count evidence — any Inventory manager, the counter included; separate commands handle custody or condition. */
final class ResolveCount
{
    /** @param array<string, mixed> $input */
    public function save(Access $access, string $id, array $input): void
    {
        InventoryWrite::assertManager($access);
        $data = Validator::make($input, ['resolution' => ['required', 'string', 'max:5000'], 'resolution_reference' => ['required', 'string', 'max:160']])->validate();
        DB::transaction(function () use ($access, $id, $data): void {
            InventoryWrite::lock();
            $row = InventoryCount::query()->lockForUpdate()->findOrFail($id);
            if ($row->status !== 'OPEN') {
                throw new \RuntimeException('This count has already been reviewed.');
            }
            $row->fill($data + ['status' => 'RESOLVED', 'resolved_at' => now()->utc()]);
            $row->reviewed_by = (int) $access->userId;
            $row->reviewer_name = mb_substr($access->fullName, 0, 200);
            $row->save();
            AuditService::record($access, AuditEvent::INVENTORY_COUNT_RESOLVED, 'inventory_counts', $row->id, 'Count reviewed; custody and condition changes require their own recorded operations.');
        });
    }
}
