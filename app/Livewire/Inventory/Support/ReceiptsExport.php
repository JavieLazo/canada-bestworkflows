<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Domain\Enums\ModuleCode;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/** Exports frozen MR identities and original totals through the receipt table's query. */
final class ReceiptsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::INVENTORY), 403);
        $rows = static function () use ($actor, $query): Generator {
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => InventoryRows::receipts($actor, $query, $page, $perPage)) as $row) {
                yield [$row->mr_number, $row->issued_on, $row->employee_name, $row->issuer_name, $row->status, $row->total_cost, $row->office_name, $row->remarks];
            }
        };

        return new CsvExport('inventory-receipts-'.ManilaClock::todayKey().'.csv', ['MR No.', 'Issued on', 'Received by', 'Issued by', 'Status', 'Original cost', 'Office', 'Remarks'], $rows);
    }
}
