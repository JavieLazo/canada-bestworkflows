<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Domain\Enums\ModuleCode;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/** Employee Records exports the same scoped holdings projection as its issued-items card. */
final class EmployeeItemsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::EMPLOYEES), 403);
        $employeeId = $query['employee'] ?? '';
        abort_if($employeeId === '', 404);
        $records = app(InventoryRecords::class);
        $first = $records->employeeHoldingsPage($actor, $employeeId, false, 1, 200);
        $rows = static function () use ($actor, $employeeId, $records, $first): Generator {
            $page = $first;
            do {
                foreach ($page as $item) {
                    yield [$item->code, $item->name, $item->serial_no ?? '', $item->condition, $item->location_name ?? '', $item->current_receipt_mr_number ?? ''];
                }
                if (! $page->hasMorePages()) {
                    break;
                }
                $page = $records->employeeHoldingsPage($actor, $employeeId, false, $page->currentPage() + 1, 200);
            } while (true);
        };

        return new CsvExport('employee-issued-items-'.ManilaClock::todayKey().'.csv', ['Item code', 'Item', 'Serial number', 'Condition', 'Location', 'MR No.'], $rows);
    }
}
