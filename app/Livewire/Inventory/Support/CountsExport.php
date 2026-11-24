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
final class CountsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->isSignedIn() && $actor->mayReadAllData(ModuleCode::INVENTORY), 403);
        $search = $query['search'] ?? '';
        $status = $query['status'] ?? 'ALL';
        $rows = static function () use ($actor, $search, $status): Generator {
            $records = app(InventoryLifecycle::class);
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => $records->counts($actor, $search, $status, $page, $perPage)) as $row) {
                yield [$row->reference, $row->counted_on->format('Y-m-d'), $row->location_name, $row->actor_name, $row->status, $row->notes ?? '', $row->resolution_reference ?? '', $row->resolution ?? ''];
            }
        };

        return new CsvExport('inventory-counts-'.LocalClock::todayKey().'.csv', ['Reference', 'Date', 'Location', 'Recorded by', 'Status', 'Notes', 'Review reference', 'Resolution'], $rows);
    }
}
