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
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** SQL-bounded reports preserve one manager scope for screen, totals and CSV. */
final class InventoryReports
{
    /** @return list<string> */
    public function columns(string $kind): array
    {
        return match ($kind) {
            'movements' => ['Date', 'Item Code', 'Item', 'Event', 'From Employee', 'To Employee', 'MR No.', 'Recorded By', 'Remarks'],
            'exceptions' => ['Item Code', 'Item', 'Status', 'Condition', 'Employee', 'MR No.', 'Category', 'Location', 'Cost', 'Exception'],
            default => ['Item Code', 'Item', 'Status', 'Condition', 'Employee', 'MR No.', 'Category', 'Location', 'Cost'],
        };
    }

    /**
     * The register-wide counts above the report, and for the three item
     * reports the acquisition cost those items add up to (`values`).
     *
     * @return array{stock:int,issued:int,exceptions:int,open_incidents:int,open_counts:int,values:array{stock:array{items:int,value:float,without_cost:int},issued:array{items:int,value:float,without_cost:int},exceptions:array{items:int,value:float,without_cost:int}}}
     */
    public function totals(Access $access): array
    {
        abort_unless($access->isSignedIn() && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $values = [
            'stock' => self::valueOf($this->items('stock', '', '')),
            'issued' => self::valueOf($this->items('holdings', '', '')),
            'exceptions' => self::valueOf($this->items('exceptions', '', '')),
        ];

        return [
            'stock' => $values['stock']['items'],
            'issued' => $values['issued']['items'],
            'exceptions' => $values['exceptions']['items'],
            'open_incidents' => InventoryIncident::query()->where('status', 'OPEN')->count(),
            'open_counts' => InventoryCount::query()->where('status', 'OPEN')->count(),
            'values' => $values,
        ];
    }

    /**
     * What the items of one report add up to: how many, their total
     * acquisition cost, and how many of them have no cost recorded (a cost
     * of zero) and so add nothing to it — over EVERY row the report lists
     * under these filters, not the page on screen. The Stock on hand report
     * also splits it by category. Null for the movement history, which lists
     * events and has no Cost column.
     *
     * @return array{items:int,value:float,without_cost:int,categories:list<array{name:string,items:int,value:float,without_cost:int}>}|null
     */
    public function value(Access $access, string $kind = 'holdings', string $search = '', string $employeeId = ''): ?array
    {
        abort_unless($access->isSignedIn() && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
        abort_unless(in_array($kind, ['holdings', 'stock', 'exceptions', 'movements'], true), 422, 'Choose an inventory report.');
        if ($kind === 'movements') {
            return null;
        }
        $query = $this->items($kind, $search, $employeeId);

        return self::valueOf($query) + ['categories' => $kind === 'stock' ? self::valueByCategory($query) : []];
    }

    /** @return LengthAwarePaginator<int, ReportRow> */
    public function rows(Access $access, string $kind = 'holdings', string $search = '', string $employeeId = '', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        abort_unless($access->isSignedIn() && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
        abort_unless(in_array($kind, ['holdings', 'stock', 'exceptions', 'movements'], true), 422, 'Choose an inventory report.');
        if ($kind === 'movements') {
            return $this->movements($access, $search, $employeeId, $page, $perPage);
        }
        $query = $this->items($kind, $search, $employeeId);
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

    /**
     * The items one report lists — its status or exception rule, then the
     * employee and search filters — unordered. THE ROWS AND THEIR VALUE BOTH
     * START HERE, so a total can never cover an item the table does not list.
     *
     * @param  string  $kind  'holdings', 'stock' or 'exceptions'
     * @return Builder<InventoryItem>
     */
    private function items(string $kind, string $search, string $employeeId): Builder
    {
        $query = $kind === 'exceptions' ? $this->exceptions() : InventoryItem::query()->where('status', $kind === 'stock' ? 'IN_STOCK' : 'ISSUED');
        if ($employeeId !== '') {
            $query->where('employee_id', $employeeId);
        }
        if (trim($search) !== '') {
            $query->where(fn (Builder $q) => $q->where('code', 'like', '%'.trim($search).'%')->orWhere('name', 'like', '%'.trim($search).'%')->orWhere('serial_no', 'like', '%'.trim($search).'%'));
        }

        return $query;
    }

    /**
     * Count, total cost and the count with no cost for the items a query
     * selects. A cost is required and never negative, so "no cost recorded"
     * is a cost of zero.
     *
     * @param  Builder<InventoryItem>  $query
     * @return array{items:int,value:float,without_cost:int}
     */
    private static function valueOf(Builder $query): array
    {
        return [
            'items' => (clone $query)->count(),
            'value' => Money::round((clone $query)->sum('cost')),
            'without_cost' => (clone $query)->where('cost', '<=', 0)->count(),
        ];
    }

    /**
     * The same three figures per category, by category name, items with no
     * category last.
     *
     * @param  Builder<InventoryItem>  $query
     * @return list<array{name:string,items:int,value:float,without_cost:int}>
     */
    private static function valueByCategory(Builder $query): array
    {
        // A null category groups under the '' key.
        $items = (clone $query)->toBase()->groupBy('category_id')->selectRaw('category_id, count(*) as items')->pluck('items', 'category_id');
        $values = (clone $query)->toBase()->groupBy('category_id')->selectRaw('category_id, sum(cost) as value')->pluck('value', 'category_id');
        $withoutCost = (clone $query)->toBase()->where('cost', '<=', 0)->groupBy('category_id')->selectRaw('category_id, count(*) as items')->pluck('items', 'category_id');
        $names = InventoryCategory::query()->whereKey($items->keys()->filter()->all())->pluck('name', 'id');

        $rows = [];
        foreach ($items as $categoryId => $count) {
            $rows[] = [
                'name' => (string) $categoryId === '' ? '' : (string) ($names[$categoryId] ?? ''),
                'items' => (int) $count,
                'value' => Money::round($values[$categoryId] ?? 0),
                'without_cost' => (int) ($withoutCost[$categoryId] ?? 0),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['name'] === '' ? 1 : 0, mb_strtolower($a['name'])] <=> [$b['name'] === '' ? 1 : 0, mb_strtolower($b['name'])]);

        return $rows;
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
