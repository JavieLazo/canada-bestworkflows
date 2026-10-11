<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\InventoryCategory;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Modules\Inventory\Contracts\Views\InventoryReportSourceRow;
use App\Modules\Inventory\Contracts\Views\ReportRow;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** SQL-bounded reports preserve one manager scope for screen, totals and CSV. */
final class InventoryReports
{
    /** @return list<string> */
    public function columns(string $kind): array
    {
        return match ($kind) {
            'movements' => ['Date', 'Item code', 'Item', 'Event', 'From employee', 'To employee', 'MR No.', 'Recorded by', 'Remarks'],
            'exceptions' => ['Item code', 'Item', 'Status', 'Condition', 'Employee', 'MR No.', 'Category', 'Location', 'Cost', 'Exception'],
            default => ['Item code', 'Item', 'Status', 'Condition', 'Employee', 'MR No.', 'Category', 'Location', 'Cost'],
        };
    }

    /** @return array{stock:int,issued:int,exceptions:int,open_incidents:int,open_counts:int} */
    public function totals(Access $access): array
    {
        abort_unless($access->isSignedIn() && $access->mayReadAllData(ModuleCode::INVENTORY), 403);

        return [
            'stock' => InventoryItem::query()->where('status', 'IN_STOCK')->count(),
            'issued' => InventoryItem::query()->where('status', 'ISSUED')->count(),
            'exceptions' => $this->exceptions()->count(),
            'open_incidents' => InventoryIncident::query()->where('status', 'OPEN')->count(),
            'open_counts' => InventoryCount::query()->where('status', 'OPEN')->count(),
        ];
    }

    /** @return LengthAwarePaginator<int, ReportRow> */
    public function rows(Access $access, string $kind = 'holdings', string $search = '', string $employeeId = '', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        abort_unless($access->isSignedIn() && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
        abort_unless(in_array($kind, ['holdings', 'stock', 'exceptions', 'movements'], true), 422, 'Choose an inventory report.');
        if ($kind === 'movements') {
            return $this->movements($access, $search, $employeeId, $page, $perPage);
        }
        $query = $kind === 'exceptions' ? $this->exceptions() : InventoryItem::query()->where('status', $kind === 'stock' ? 'IN_STOCK' : 'ISSUED');
        if ($employeeId !== '') {
            $query->where('employee_id', $employeeId);
        }
        if (trim($search) !== '') {
            $query->where(fn (Builder $q) => $q->where('code', 'like', '%'.trim($search).'%')->orWhere('name', 'like', '%'.trim($search).'%')->orWhere('serial_no', 'like', '%'.trim($search).'%'));
        }
        SqlText::order($query, 'code', 'asc');
        $rows = QueryPage::get($query->orderBy('id'), $page, $perPage, InventoryReportSourceRow::class);
        $collection = $rows->getCollection();
        $ids = array_values(array_filter($collection->pluck('employee_id')->unique()->all(), static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $employees = $ids === [] ? [] : app(InventoryEmployees::class)->identities($access, $ids);
        $categories = InventoryCategory::query()->whereKey($collection->pluck('category_id')->filter()->all())->pluck('name', 'id');
        $locations = InventoryLocation::query()->whereKey($collection->pluck('location_id')->filter()->all())->pluck('name', 'id');
        $receipts = InventoryReceipt::query()->whereKey($collection->pluck('current_receipt_id')->filter()->all())->pluck('mr_number', 'id');
        $discrepancies = $kind === 'exceptions'
            ? $this->discrepancies()->whereIn('item_id', $collection->pluck('id'))->get(['item_id', 'result'])->groupBy('item_id')
            : collect();

        $incidentItems = $kind === 'exceptions'
            ? InventoryIncident::query()->where('status', 'OPEN')->whereIn('item_id', $collection->pluck('id'))->pluck('item_id')->all()
            : [];

        return $rows->through(static function (InventoryReportSourceRow $row) use ($kind, $employees, $categories, $locations, $receipts, $discrepancies, $incidentItems): ReportRow {
            $cells = [(string) $row->code, $row->name, $row->status, $row->condition,
                $employees[$row->employee_id]['name'] ?? '', $receipts[$row->current_receipt_id] ?? '',
                $categories[$row->category_id] ?? '', $locations[$row->location_id] ?? '', number_format($row->cost, 2, '.', '')];
            if ($kind === 'exceptions') {
                $reasons = $row->condition === 'SERVICEABLE' ? [] : [ucfirst(strtolower($row->condition))];
                foreach ($discrepancies->get($row->id, collect())->pluck('result')->unique() as $result) {
                    $reasons[] = $result === 'NOT_FOUND' ? 'Open count: not found' : 'Open count: unexpected';
                }
                if (in_array($row->id, $incidentItems, true)) {
                    $reasons[] = 'Open incident report';
                }
                $cells[] = implode('; ', $reasons);
            }

            return new ReportRow(['id' => $row->id, 'cells' => $cells]);
        });
    }

    public function changeStamp(Access $access): string
    {
        abort_unless($access->isSignedIn() && $access->mayReadAllData(ModuleCode::INVENTORY), 403);

        return hash('sha256', json_encode([
            app(InventoryRecords::class)->changeStamp($access),
            InventoryIncident::query()->count(), InventoryIncident::query()->max('updated_at'),
            InventoryCount::query()->count(), InventoryCount::query()->max('updated_at'),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return Builder<InventoryItem> */
    private function exceptions(): Builder
    {
        return InventoryItem::query()->where('status', '!=', 'DISPOSED')->where(fn (Builder $query): Builder => $query->where('condition', '!=', 'SERVICEABLE')->orWhereIn('id', $this->discrepancies()->select('item_id'))->orWhereIn('id', InventoryIncident::query()->select('item_id')->where('status', 'OPEN')));
    }

    /** @return Builder<InventoryCountLine> */
    private function discrepancies(): Builder
    {
        return InventoryCountLine::query()->where('result', '!=', 'FOUND')->whereIn('count_id', InventoryCount::query()->select('id')->where('status', 'OPEN'));
    }

    /** @return LengthAwarePaginator<int, ReportRow> */
    private function movements(Access $access, string $search, string $employeeId, int $page, int $perPage): LengthAwarePaginator
    {
        $query = InventoryMovement::query();
        if ($employeeId !== '') {
            $query->where(fn (Builder $q) => $q->where('from_employee_id', $employeeId)->orWhere('to_employee_id', $employeeId));
        }
        if (trim($search) !== '') {
            $query->whereIn('item_id', InventoryItem::query()->select('id')->where(fn (Builder $q) => $q->where('code', 'like', '%'.trim($search).'%')->orWhere('name', 'like', '%'.trim($search).'%')));
        }
        $rows = QueryPage::get($query->orderByDesc('occurred_on')->orderByDesc('created_at')->orderByDesc('id'), $page, $perPage, InventoryReportSourceRow::class);
        $collection = $rows->getCollection();
        $ids = array_values(array_filter($collection->pluck('from_employee_id')->merge($collection->pluck('to_employee_id'))->unique()->all(), static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $employees = app(InventoryEmployees::class)->identities($access, $ids);
        $items = InventoryItem::query()->whereKey($collection->pluck('item_id')->all())->get(['id', 'code', 'name'])->keyBy('id');
        $receipts = InventoryReceipt::query()->whereKey($collection->pluck('receipt_id')->filter()->all())->pluck('mr_number', 'id');

        return $rows->through(static fn (InventoryReportSourceRow $row): ReportRow => new ReportRow([
            'id' => $row->id,
            'cells' => [$row->occurred_on->format('Y-m-d'), (string) ($items[$row->item_id]->code ?? ''), $items[$row->item_id]->name ?? '', $row->event_type,
                $employees[$row->from_employee_id]['name'] ?? '', $employees[$row->to_employee_id]['name'] ?? '',
                $receipts[$row->receipt_id] ?? '', $row->actor_name, $row->remarks ?? ''],
        ]));
    }
}
