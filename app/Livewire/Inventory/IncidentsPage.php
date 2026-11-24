<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Inventory\Application\Commands\RecordIncident;
use App\Modules\Inventory\Application\Commands\ResolveIncident;
use App\Modules\Inventory\Application\Queries\InventoryLifecycle;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use App\Support\LocalClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Condition reports and independent reviews preserve each item's custody evidence. */
#[Layout('layouts.app')]
#[Title('Inventory Condition and Loss')]
class IncidentsPage extends Component
{
    use WithTableFilters;

    public string $status = 'ALL';

    public string $itemSearch = '';

    public bool $dialogOpen = false;

    public bool $reviewOpen = false;

    #[Locked]
    public ?string $reviewingId = null;

    /** The scoped item selected by an item-detail entry link stays fixed for this report. */
    #[Locked]
    public ?string $contextItemId = null;

    /** Locked exception: selection is a form draft re-resolved by the command. */
    public string $itemId = '';

    public string $kind = 'DAMAGE';

    public string $reportedOn = '';

    public string $reference = '';

    public string $details = '';

    public string $resolution = '';

    public string $resolutionReference = '';

    public string $condition = 'DAMAGED';

    public ?string $formError = null;

    public function mount(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->reportedOn = LocalClock::dayKey(now());
        $itemId = request()->query('item');
        if ($itemId !== null) {
            abort_unless(is_string($itemId) && $itemId !== '' && mb_strlen($itemId) <= 26, 404);
            $item = app(InventoryRecords::class)->item($access, $itemId);
            abort_if($item === null, 404);
            $this->contextItemId = $item->id;
            $this->itemId = $item->id;
            $this->dialogOpen = true;
        }
    }

    public function openAdd(): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->reset('contextItemId', 'itemId', 'itemSearch', 'reference', 'details', 'formError');
        $this->dialogOpen = true;
    }

    public function save(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        try {
            app(RecordIncident::class)->save($access, ['item_id' => $this->contextItemId ?? $this->itemId, 'kind' => $this->kind, 'reported_on' => $this->reportedOn, 'reference' => $this->reference, 'details' => $this->details]);
            $this->dialogOpen = false;
            Flux::toast(variant: 'success', text: 'Report recorded. Custody remains unchanged.');
        } catch (QueryException $error) {
            report($error);
            $this->formError = 'The report could not be saved. Try again.';
        } catch (\RuntimeException $error) {
            $this->formError = $error->getMessage();
        }
    }

    public function openReview(string $id): void
    {
        $access = app(Access::class);
        $row = app(InventoryLifecycle::class)->incident($access, $id);
        $this->reviewingId = $row->id;
        $this->condition = match ($row->kind) {
            'LOSS' => 'MISSING', 'INSPECTION' => 'UNSERVICEABLE', default => 'DAMAGED'
        };
        $this->reset('resolution', 'resolutionReference', 'formError');
        $this->reviewOpen = true;
    }

    public function resolve(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if ($this->reviewingId === null) {
            return;
        }
        try {
            app(ResolveIncident::class)->save($access, $this->reviewingId, ['resolution' => $this->resolution, 'resolution_reference' => $this->resolutionReference, 'condition' => $this->condition]);
            $this->reviewOpen = false;
            Flux::toast(variant: 'success', text: 'Report reviewed. Custody remains unchanged.');
        } catch (QueryException $error) {
            report($error);
            $this->formError = 'The report review could not be saved. Try again.';
        } catch (\RuntimeException $error) {
            $this->formError = $error->getMessage();
        }
    }

    public function render(): View
    {
        $access = app(Access::class);

        $selectedId = $this->contextItemId ?? $this->itemId;

        return view('livewire.inventory.incidents-page', [
            'rows' => $this->acceptPage(app(InventoryLifecycle::class)->incidents($access, $this->search, $this->status, (int) $this->getPage(), $this->perPage)),
            'items' => $this->dialogOpen && $this->contextItemId === null ? app(InventoryRecords::class)->items($access, ['search' => $this->itemSearch], 'code', 'asc', 1, 50) : [],
            'selectedItem' => $this->dialogOpen && $selectedId !== '' ? app(InventoryRecords::class)->item($access, $selectedId) : null,
            'review' => $this->reviewingId ? app(InventoryLifecycle::class)->incident($access, $this->reviewingId) : null,
        ]);
    }
}
