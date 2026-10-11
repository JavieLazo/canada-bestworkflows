<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Inventory\Application\Commands\RecordCount;
use App\Modules\Inventory\Application\Commands\ResolveCount;
use App\Modules\Inventory\Application\Queries\InventoryLifecycle;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Paginated count sheets capture observations without silently writing custody or condition. */
#[Layout('layouts.app')]
#[Title('Inventory physical counts')]
class CountsPage extends Component
{
    use WithTableFilters;

    public string $status = 'ALL';

    public bool $dialogOpen = false;

    public bool $reviewOpen = false;

    #[Locked]
    public ?string $reviewingId = null;

    /** Locked exception: location is a draft re-resolved by the command. */
    public string $locationId = '';

    public string $countedOn = '';

    public string $reference = '';

    public string $notes = '';

    public string $unexpectedCode = '';

    public string $resolution = '';

    public string $resolutionReference = '';

    public ?string $formError = null;

    #[Locked]
    public int $sheetPage = 1;

    #[Locked]
    public int $sheetLastPage = 1;

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $sheet = [];

    /** Locked exception: count drafts revalidate every item, version, result and location under the command lock.
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    public function mount(): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->countedOn = ManilaClock::dayKey(now());
    }

    public function openAdd(): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->reset('reference', 'notes', 'formError', 'sheet', 'lines', 'locationId', 'sheetPage');
        $this->dialogOpen = true;
    }

    public function updatedLocationId(): void
    {
        $this->loadSheet(1);
    }

    public function loadSheet(int $page): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $rows = app(InventoryLifecycle::class)->countSheet($access, $this->locationId, max(1, $page), 20);
        $this->sheetPage = $rows->currentPage();
        $this->sheetLastPage = $rows->lastPage();
        $this->sheet = [];
        $this->lines = [];
        foreach ($rows as $row) {
            $this->sheet[] = $row->toArray();
            $this->lines[] = ['item_id' => $row->id, 'version' => $row->version, 'result' => '', 'notes' => ''];
        }
    }

    public function addUnexpected(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        try {
            $row = app(InventoryLifecycle::class)->unexpectedItem($access, $this->unexpectedCode, $this->locationId);
            if (count($this->lines) >= 200 || in_array($row->id, array_column($this->lines, 'item_id'), true)) {
                throw new \RuntimeException('The item is already on this sheet or the sheet has reached 200 items.');
            }
            $this->sheet[] = $row->toArray();
            $this->lines[] = ['item_id' => $row->id, 'version' => $row->version, 'result' => 'UNEXPECTED', 'notes' => ''];
            $this->unexpectedCode = '';
        } catch (QueryException $error) {
            report($error);
            $this->formError = 'The item could not be added to the count sheet. Try again.';
        } catch (\RuntimeException $error) {
            $this->formError = $error->getMessage();
        }
    }

    public function save(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        try {
            app(RecordCount::class)->save($access, ['location_id' => $this->locationId, 'counted_on' => $this->countedOn, 'reference' => $this->reference, 'notes' => $this->notes, 'lines' => $this->lines]);
            $this->dialogOpen = false;
            Flux::toast(variant: 'success', text: 'Count batch recorded. Custody and conditions remain unchanged.');
        } catch (QueryException $error) {
            report($error);
            $this->formError = 'The count could not be saved. Try again.';
        } catch (\RuntimeException $error) {
            $this->formError = $error->getMessage();
        }
    }

    public function openReview(string $id): void
    {
        $row = app(InventoryLifecycle::class)->count(app(Access::class), $id);
        $this->reviewingId = $row->id;
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
            app(ResolveCount::class)->save($access, $this->reviewingId, ['resolution' => $this->resolution, 'resolution_reference' => $this->resolutionReference]);
            $this->reviewOpen = false;
            Flux::toast(variant: 'success', text: 'Count reviewed. Item changes require their own recorded operation.');
        } catch (QueryException $error) {
            report($error);
            $this->formError = 'The count review could not be saved. Try again.';
        } catch (\RuntimeException $error) {
            $this->formError = $error->getMessage();
        }
    }

    public function render(): View
    {
        $access = app(Access::class);

        return view('livewire.inventory.counts-page', [
            'rows' => $this->acceptPage(app(InventoryLifecycle::class)->counts($access, $this->search, $this->status, (int) $this->getPage(), $this->perPage)),
            'locations' => app(InventoryRecords::class)->catalogs($access, 'location'),
            'review' => $this->reviewingId ? app(InventoryLifecycle::class)->count($access, $this->reviewingId) : null,
            'reviewLines' => $this->reviewingId ? app(InventoryLifecycle::class)->lines($access, $this->reviewingId) : [],
        ]);
    }
}
