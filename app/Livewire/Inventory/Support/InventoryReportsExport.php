<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Modules\Inventory\Application\Queries\InventoryReports;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;

/** Exports the same report selection through bounded owner-query pages. */
final class InventoryReportsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        InventoryReportRows::arrange($actor, $query, 1, 1);

        return new CsvExport('inventory-report.csv', app(InventoryReports::class)->columns($query['report'] ?? 'holdings'), static function () use ($actor, $query): iterable {
            foreach (CsvExport::pages(static fn (int $page, int $size) => InventoryReportRows::arrange($actor, $query, $page, $size)) as $row) {
                yield $row->cells;
            }
        });
    }
}
