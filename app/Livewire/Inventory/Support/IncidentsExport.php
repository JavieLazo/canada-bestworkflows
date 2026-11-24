<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Domain\Enums\ModuleCode;
use App\Modules\Inventory\Application\Queries\InventoryLifecycle;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\LocalClock;
use Generator;

/** Exports the lifecycle table through the same scoped, filtered page query. */
final class IncidentsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->isSignedIn() && $actor->mayReadAllData(ModuleCode::INVENTORY), 403);
        $search = $query['search'] ?? '';
        $status = $query['status'] ?? 'ALL';
        $rows = static function () use ($actor, $search, $status): Generator {
            $records = app(InventoryLifecycle::class);
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => $records->incidents($actor, $search, $status, $page, $perPage)) as $row) {
                yield [$row->reference, $row->reported_on->format('Y-m-d'), $row->item_snapshot['code'], $row->item_snapshot['name'], $row->kind, $row->employee_snapshot['name'] ?? '', $row->status, $row->details, $row->resolution_reference ?? '', $row->resolution ?? ''];
            }
        };

        return new CsvExport('inventory-incidents-'.LocalClock::todayKey().'.csv', ['Reference', 'Date', 'Item code', 'Item', 'Type', 'Employee at report', 'Status', 'Details', 'Review reference', 'Resolution'], $rows);
    }
}
