<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Modules\Inventory\Contracts\Views\CatalogView;
use App\Modules\Inventory\Contracts\Views\ItemView;
use App\Modules\Inventory\Contracts\Views\MovementView;
use App\Modules\Inventory\Contracts\Views\ReceiptLineView;
use App\Modules\Inventory\Contracts\Views\ReceiptView;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** One scoped projection serves tables, exports, paper and employee self-service. */
final class InventoryRecords
{
    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int, ItemView>
     */
    public function items(Access $actor, array $filters = [], string $sort = 'code', string $direction = 'asc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->itemQuery($actor);
        $this->itemFilters($query, $filters);
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $sort = in_array($sort, ['code', 'name', 'acquired_on', 'cost', 'status'], true) ? $sort : 'code';
        $sort === 'code' ? SqlText::order($query, 'code', $direction) : $query->orderBy($sort, $direction);
        $rows = QueryPage::get($query->orderBy('id'), $page, $perPage, ItemView::class);
        $rows->setCollection($this->itemRecords($actor, $rows->getCollection()));

        return $rows;
    }

    public function item(Access $actor, string $id): ?ItemView
    {
        $row = $this->itemQuery($actor)->whereKey($id)->first();

        return $row === null ? null : $this->itemViews($actor, collect([$row]))->first();
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int, ReceiptView>
     */
    public function receipts(Access $actor, array $filters = [], string $sort = 'code', string $direction = 'asc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->receiptQuery($actor);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('mr_number', 'like', '%'.$search.'%')->orWhere('employee_snapshot->name', 'like', '%'.$search.'%');
            });
        }
        foreach (['status', 'employee_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '' && $filters[$key] !== 'ALL') {
                $query->where($key, $filters[$key]);
            }
        }
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $sort = in_array($sort, ['code', 'mr_number', 'issued_on', 'status'], true) ? $sort : 'code';
        $sort === 'code' ? SqlText::orderNumeric($query, 'code', $direction) : $query->orderBy($sort, $direction);
        $rows = QueryPage::get($query->orderBy('id'), $page, $perPage, ReceiptView::class);
        $lines = InventoryReceiptLine::query()->whereIn('receipt_id', $rows->getCollection()->pluck('id'))->get()->groupBy('receipt_id');

        return $rows->through(fn (ReceiptView $row): ReceiptView => $this->receiptRecord($row, $lines->get($row->id, collect())));
    }

    public function receipt(Access $actor, string $id, bool $own = false): ?ReceiptView
    {
        $row = $this->receiptQuery($actor, $own)->with('lines')->whereKey($id)->first();

        return $row === null ? null : $this->receiptView($row, true);
    }

    /** @return Collection<int, MovementView> */
    public function movements(Access $actor, string $itemId): Collection
    {
        if ($this->itemQuery($actor)->whereKey($itemId)->doesntExist()) {
            return collect();
        }
        $query = InventoryMovement::query()->where('item_id', $itemId);
        if (! $actor->mayReadAllData(ModuleCode::INVENTORY)) {
            if (! $actor->hasEmployeeLink()) {
                return collect();
            }
            $employeeId = $actor->ownEmployeeId();
            $query->where(fn (Builder $q): Builder => $q->where('from_employee_id', $employeeId)->orWhere('to_employee_id', $employeeId));
        }

        $rows = $query->orderByDesc('occurred_on')->orderByDesc('created_at')->get();
        $employeeIds = array_values(array_filter($rows->pluck('from_employee_id')->merge($rows->pluck('to_employee_id'))->unique()->all(), static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $employees = $employeeIds === [] ? [] : app(InventoryEmployees::class)->identities($actor, $employeeIds);
        $receipts = InventoryReceipt::query()->whereKey($rows->pluck('receipt_id')->filter()->unique()->all())->get(['id', 'code', 'mr_number'])->keyBy('id');

        return $rows->map(static fn (InventoryMovement $row): MovementView => new MovementView([
            'id' => $row->id, 'item_id' => $row->item_id, 'receipt_id' => $row->receipt_id,
            'receipt_code' => $row->receipt_id === null ? null : $receipts->get($row->receipt_id)?->code,
            'receipt_mr_number' => $row->receipt_id === null ? null : $receipts->get($row->receipt_id)?->mr_number,
            'event_type' => $row->event_type, 'from_employee_id' => $row->from_employee_id, 'to_employee_id' => $row->to_employee_id,
            'from_employee_name' => $row->from_employee_id === null ? null : ($employees[$row->from_employee_id]['name'] ?? 'Employee'),
            'to_employee_name' => $row->to_employee_id === null ? null : ($employees[$row->to_employee_id]['name'] ?? 'Employee'),
            'occurred_on' => $row->occurred_on->format('Y-m-d'), 'actor_id' => $row->actor_id,
            'actor_name' => $row->actor_name, 'remarks' => $row->remarks, 'created_at' => $row->created_at,
        ]));
    }

    /** @return Collection<int, CatalogView> */
    public function catalogs(Access $actor, string $kind): Collection
    {
        $this->assertReader($actor);
        $model = match ($kind) {
            'category' => InventoryCategory::class,
            'location' => InventoryLocation::class,
            default => throw new \RuntimeException('Unknown inventory catalog.'),
        };
        $column = $kind.'_id';
        $counts = $this->itemQuery($actor)->reorder()->select($column)->selectRaw('COUNT(*) AS aggregate')->groupBy($column)->pluck('aggregate', $column);

        return $model::query()->orderBy('name')->get()->map(static fn (InventoryCategory|InventoryLocation $row): CatalogView => new CatalogView([
            'id' => $row->id, 'name' => $row->name, 'item_count' => (int) ($counts[$row->id] ?? 0),
        ]));
    }

    /** @return LengthAwarePaginator<int, ItemView> */
    public function employeeHoldingsPage(Access $actor, string $employeeId, bool $own = false, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->holdingsQuery($actor, $employeeId, $own);
        SqlText::order($query, 'code', 'asc');
        $rows = QueryPage::get($query->orderBy('id'), $page, $perPage, ItemView::class);
        $rows->setCollection($this->itemRecords($actor, $rows->getCollection()));

        return $rows;
    }

    public function holdingsStamp(Access $actor, string $employeeId, bool $own = false): string
    {
        $query = $this->holdingsQuery($actor, $employeeId, $own);
        $locations = InventoryLocation::query()->whereIn('id', (clone $query)->select('location_id'));

        return hash('sha256', json_encode([
            $query->count(), $query->max('updated_at'), $query->max('id'),
            $locations->count(), $locations->max('updated_at'),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return Builder<InventoryItem> */
    private function holdingsQuery(Access $actor, string $employeeId, bool $own): Builder
    {
        $query = InventoryItem::query()->where('employee_id', $employeeId)->where('status', 'ISSUED');
        if ($own) {
            abort_unless($actor->isSignedIn() && ($actor->hasModule(ModuleCode::PORTAL) || $actor->hasModule(ModuleCode::PROFILE)), 403);
            if (! $actor->hasEmployeeLink() || $actor->ownEmployeeId() !== $employeeId) {
                $query->whereRaw('1 = 0');
            }
        } else {
            abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::EMPLOYEES, ModuleCode::INVENTORY), 403);
            if (! $actor->mayReadAllData(ModuleCode::EMPLOYEES, ModuleCode::INVENTORY)
                && (! $actor->hasEmployeeLink() || $actor->ownEmployeeId() !== $employeeId)) {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    public function changeStamp(Access $actor): string
    {
        $items = $this->itemQuery($actor);
        $receipts = $this->receiptQuery($actor);

        return hash('sha256', json_encode([
            $items->count(), $items->max('updated_at'), $items->max('id'),
            app(InventoryEmployees::class)->changeStamp($actor),
            $receipts->count(), $receipts->max('updated_at'), $receipts->max('id'),
            InventoryCategory::query()->count(), InventoryCategory::query()->max('updated_at'),
            InventoryLocation::query()->count(), InventoryLocation::query()->max('updated_at'),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return Builder<InventoryItem> */
    private function itemQuery(Access $actor): Builder
    {
        $this->assertReader($actor);

        return InventoryItem::query()->toEmployee($actor, 'employee_id', ModuleCode::INVENTORY);
    }

    /** @return Builder<InventoryReceipt> */
    private function receiptQuery(Access $actor, bool $own = false): Builder
    {
        $query = InventoryReceipt::query();
        if ($own) {
            abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::PORTAL), 403);

            return $actor->hasEmployeeLink() ? $query->where('employee_id', $actor->ownEmployeeId()) : $query->whereRaw('1 = 0');
        }
        $this->assertReader($actor);

        return $query->toEmployee($actor, 'employee_id', ModuleCode::INVENTORY);
    }

    /** @param Builder<InventoryItem> $query
     * @param  array<string,mixed>  $filters
     */
    private function itemFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(fn (Builder $q): Builder => $q->where('code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')->orWhere('serial_no', 'like', '%'.$search.'%'));
        }
        foreach (['status', 'condition', 'category_id', 'location_id', 'employee_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '' && $filters[$key] !== 'ALL') {
                $query->where($key, $filters[$key]);
            }
        }
    }

    /** @param Collection<int, InventoryItem> $rows
     * @return Collection<int, ItemView>
     */
    private function itemViews(Access $actor, Collection $rows): Collection
    {
        return $this->itemRecords($actor, $rows->map(static fn (InventoryItem $row): ItemView => Snapshot::of($row, ItemView::class)));
    }

    /** @param Collection<int, ItemView> $rows
     * @return Collection<int, ItemView>
     */
    private function itemRecords(Access $actor, Collection $rows): Collection
    {
        $ids = array_values(array_filter($rows->pluck('employee_id')->unique()->all(), static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $employees = $ids === [] ? [] : app(InventoryEmployees::class)->identities($actor, $ids);
        $categories = InventoryCategory::query()->whereKey($rows->pluck('category_id')->filter()->unique()->all())->pluck('name', 'id');
        $locations = InventoryLocation::query()->whereKey($rows->pluck('location_id')->filter()->unique()->all())->pluck('name', 'id');
        $receipts = InventoryReceipt::query()->whereKey($rows->pluck('current_receipt_id')->filter()->unique()->all())->get(['id', 'code', 'mr_number', 'issued_on'])->keyBy('id');

        return $rows->map(static fn (ItemView $row): ItemView => new ItemView([
            ...$row->toArray(),
            'category_name' => $row->category_id === null ? null : ($categories[$row->category_id] ?? null),
            'location_name' => $row->location_id === null ? null : ($locations[$row->location_id] ?? null),
            'employee_name' => $row->employee_id === null ? null : ($employees[$row->employee_id]['name'] ?? null),
            'employee_position' => $row->employee_id === null ? null : ($employees[$row->employee_id]['position'] ?? null),
            'employee_department' => $row->employee_id === null ? null : ($employees[$row->employee_id]['department'] ?? null),
            'employee_unit' => $row->employee_id === null ? null : ($employees[$row->employee_id]['unit'] ?? null),
            'current_receipt_code' => $row->current_receipt_id === null ? null : $receipts->get($row->current_receipt_id)?->code,
            'current_receipt_mr_number' => $row->current_receipt_id === null ? null : $receipts->get($row->current_receipt_id)?->mr_number,
            'current_receipt_issued_on' => $row->current_receipt_id === null ? null : $receipts->get($row->current_receipt_id)?->issued_on->format('Y-m-d'),
        ]));
    }

    private function receiptView(InventoryReceipt $row, bool $detail = false): ReceiptView
    {
        $replacement = $detail ? InventoryReceipt::query()->where('corrected_from_id', $row->id)->first(['id', 'code', 'mr_number']) : null;

        return $this->receiptRecord(Snapshot::of($row, ReceiptView::class), $row->lines, $replacement?->id, $replacement?->code, $replacement?->mr_number);
    }

    /** @param Collection<int, InventoryReceiptLine> $receiptLines */
    private function receiptRecord(ReceiptView $row, Collection $receiptLines, ?string $replacementId = null, ?string $replacementCode = null, ?string $replacementMrNumber = null): ReceiptView
    {
        $lines = $receiptLines->sortBy('id')->values()->map(static fn (InventoryReceiptLine $line): ReceiptLineView => new ReceiptLineView([
            'id' => $line->id, 'receipt_id' => $line->receipt_id, 'item_id' => $line->item_id,
            'status' => $line->status, 'item_snapshot' => $line->item_snapshot,
            'ended_on' => $line->ended_on?->format('Y-m-d'),
        ]));
        $summary = $lines->take(3)->map(static fn (ReceiptLineView $line): string => (string) ($line->item_snapshot['name'] ?? ''))->implode(', ');
        if ($lines->count() > 3) {
            $summary .= ' +'.($lines->count() - 3).' more';
        }

        return new ReceiptView([
            ...$row->toArray(), 'employee_name' => (string) ($row->employee_snapshot['name'] ?? ''),
            'issuer_name' => (string) ($row->issuer_snapshot['name'] ?? ''),
            'replacement_id' => $replacementId, 'corrected_to_id' => $replacementId, 'corrected_to_code' => $replacementCode, 'corrected_to_mr_number' => $replacementMrNumber,
            'lines' => $lines,
            'item_count' => $lines->count(),
            'item_summary' => $summary,
            'total_cost' => $lines->sum(static fn (ReceiptLineView $line): float => (float) ($line->item_snapshot['cost'] ?? 0)),
        ]);
    }

    private function assertReader(Access $actor): void
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::INVENTORY), 403);
    }
}
