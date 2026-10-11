<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\InventoryReceipt;
use App\Modules\Inventory\Contracts\Views\CountLineView;
use App\Modules\Inventory\Contracts\Views\CountSheetItemView;
use App\Modules\Inventory\Contracts\Views\CountView;
use App\Modules\Inventory\Contracts\Views\DisposalView;
use App\Modules\Inventory\Contracts\Views\IncidentView;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Bounded, detached lifecycle reads share the same manager permission as their commands. */
final class InventoryLifecycle
{
    /** @return LengthAwarePaginator<int, IncidentView> */
    public function incidents(Access $access, string $search = '', string $status = 'ALL', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $this->assertManager($access);
        $query = InventoryIncident::query();
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('details', 'like', '%'.$search.'%'));
        }
        if (in_array($status, ['OPEN', 'RESOLVED'], true)) {
            $query->where('status', $status);
        }

        return QueryPage::get($query->orderByDesc('created_at')->orderByDesc('id'), $page, $perPage, IncidentView::class);
    }

    public function incident(Access $access, string $id): IncidentView
    {
        $this->assertManager($access);

        return Snapshot::of(InventoryIncident::query()->findOrFail($id), IncidentView::class);
    }

    /** @return LengthAwarePaginator<int, DisposalView> */
    public function disposals(Access $access, string $search = '', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $this->assertManager($access);
        $query = InventoryDisposal::query();
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('authorization_reference', 'like', '%'.$search.'%')->orWhere('reason', 'like', '%'.$search.'%'));
        }

        return QueryPage::get($query->orderByDesc('created_at')->orderByDesc('id'), $page, $perPage, DisposalView::class);
    }

    /** @return LengthAwarePaginator<int, CountView> */
    public function counts(Access $access, string $search = '', string $status = 'ALL', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $this->assertManager($access);
        $query = InventoryCount::query();
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('location_name', 'like', '%'.$search.'%'));
        }
        if (in_array($status, ['OPEN', 'RESOLVED'], true)) {
            $query->where('status', $status);
        }

        return QueryPage::get($query->orderByDesc('created_at')->orderByDesc('id'), $page, $perPage, CountView::class);
    }

    public function count(Access $access, string $id): CountView
    {
        $this->assertManager($access);

        return Snapshot::of(InventoryCount::query()->findOrFail($id), CountView::class);
    }

    /** @return Collection<int, CountLineView> */
    public function lines(Access $access, string $id): Collection
    {
        $this->assertManager($access);
        InventoryCount::query()->findOrFail($id);

        return InventoryCountLine::query()->where('count_id', $id)->orderBy('id')->get()->map(static fn (InventoryCountLine $row): CountLineView => Snapshot::of($row, CountLineView::class));
    }

    /** @return LengthAwarePaginator<int, CountSheetItemView> */
    public function countSheet(Access $access, string $locationId, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $this->assertManager($access);
        $items = QueryPage::get(InventoryItem::query()->where('location_id', $locationId)->where('status', '!=', 'DISPOSED')->orderBy('code'), $page, $perPage, CountSheetItemView::class, 'sheetPage');

        $ids = [];
        foreach ($items as $item) {
            if ($item->employee_id !== null) {
                $ids[] = $item->employee_id;
            }
        }
        $ids = array_values(array_unique($ids));
        $employees = app(InventoryEmployees::class)->identities($access, $ids);
        $receipts = InventoryReceipt::query()->whereKey($items->getCollection()->pluck('current_receipt_id')->filter()->unique()->all())->get(['id', 'code', 'mr_number'])->keyBy('id')->all();

        return $items->through(fn (CountSheetItemView $item): CountSheetItemView => $this->sheetLine($item, $employees, $receipts));
    }

    public function unexpectedItem(Access $access, string $code, string $locationId): CountSheetItemView
    {
        $this->assertManager($access);
        $item = InventoryItem::query()->where('code', trim($code))->where('status', '!=', 'DISPOSED')->firstOrFail();
        if ($item->location_id === $locationId) {
            throw new \RuntimeException('This item belongs on the expected list; load its count-sheet page.');
        }

        $employees = app(InventoryEmployees::class)->identities($access, $item->employee_id ? [$item->employee_id] : []);
        $receipts = $item->current_receipt_id ? InventoryReceipt::query()->whereKey($item->current_receipt_id)->get(['id', 'code', 'mr_number'])->keyBy('id')->all() : [];

        return $this->sheetLine($item, $employees, $receipts);
    }

    public function employeeExists(Access $access, string $id): bool
    {
        $this->assertManager($access);

        return isset(app(InventoryEmployees::class)->identities($access, [$id])[$id]);
    }

    /** @param array<string, array<string, mixed>> $employees
     * @param  array<string, InventoryReceipt>  $receipts
     */
    private function sheetLine(InventoryItem|CountSheetItemView $item, array $employees, array $receipts): CountSheetItemView
    {
        return new CountSheetItemView([
            'id' => $item->id, 'code' => $item->code, 'name' => $item->name,
            'created_at' => $item->created_at, 'updated_at' => $item->updated_at,
            'location_id' => $item->location_id, 'current_receipt_id' => $item->current_receipt_id,
            'employee_id' => $item->employee_id, 'receipt_id' => $item->current_receipt_id,
            'condition' => $item->condition,
            'employee_name' => $employees[$item->employee_id]['name'] ?? '',
            'receipt_code' => $receipts[$item->current_receipt_id]->code ?? '', 'receipt_mr_number' => $receipts[$item->current_receipt_id]->mr_number ?? '',
            'version' => hash('sha256', implode('|', [$item->id, $item->location_id, $item->employee_id, $item->current_receipt_id, $item->updated_at?->format('Y-m-d H:i:s.u')])),
        ]);
    }

    private function assertManager(Access $access): void
    {
        abort_unless($access->isSignedIn() && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
    }
}
