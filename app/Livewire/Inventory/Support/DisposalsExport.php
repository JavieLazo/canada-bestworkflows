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
final class DisposalsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->isSignedIn() && $actor->mayReadAllData(ModuleCode::INVENTORY), 403);
        $search = $query['search'] ?? '';
        $rows = static function () use ($actor, $search): Generator {
            $records = app(InventoryLifecycle::class);
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => $records->disposals($actor, $search, $page, $perPage)) as $row) {
                yield [$row->disposed_on->format('Y-m-d'), $row->item_snapshot['code'], $row->item_snapshot['name'], $row->method, $row->authorization_reference, $row->authorized_by_snapshot['name'], $row->actor_name, $row->reason];
            }
        };

        return new CsvExport('inventory-disposals-'.LocalClock::todayKey().'.csv', ['Date', 'Item code', 'Item', 'Method', 'Authorization reference', 'Approving employee', 'Recorded by', 'Reason'], $rows);
    }
}
