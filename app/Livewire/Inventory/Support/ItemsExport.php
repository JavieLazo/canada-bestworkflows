<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Domain\Enums\ModuleCode;
use App\Modules\Inventory\Application\Commands\ImportItems;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/** Exports the exact scoped register filters and sort, with custody clearly read-only. */
final class ItemsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::INVENTORY), 403);
        $rows = static function () use ($actor, $query): Generator {
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => InventoryRows::items($actor, $query, $page, $perPage)) as $row) {
                yield [$row->code, $row->name, $row->serial_no, $row->acquired_on, $row->cost, $row->category_name, $row->location_name, $row->remarks, $row->status, $row->condition, $row->employee_name, $row->current_receipt_mr_number];
            }
        };

        return new CsvExport('inventory-items-'.ManilaClock::todayKey().'.csv', [...ImportItems::COLUMNS, 'Status (read-only)', 'Condition (read-only)', 'Held by (read-only)', 'MR No. (read-only)'], $rows);
    }
}
