<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithChangeStamp;
use App\Modules\Inventory\Application\Commands\DeleteCatalog;
use App\Modules\Inventory\Application\Commands\SaveCatalog;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Inventory reference names are managed separately from receipt snapshots. */
#[Title('Inventory Categories and Locations')]
class CatalogsPage extends Component
{
    use WithChangeStamp;

    public bool $dialogOpen = false;

    #[Locked]
    public string $kind = 'category';

    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public bool $deleteDialog = false;

    #[Locked]
    public ?string $deletingId = null;

    #[Locked]
    public string $deletingKind = 'category';

    public function openCatalog(string $kind, ?string $id = null): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        abort_unless(in_array($kind, ['category', 'location'], true), 422);
        $this->resetValidation();
        $this->kind = $kind;
        $this->editingId = $id;
        $this->name = '';
        if ($id !== null) {
            $row = app(InventoryRecords::class)->catalogs($access, $kind)->firstWhere('id', $id);
            abort_if($row === null, 404);
            $this->name = $row->name;
        }
        $this->dialogOpen = true;
    }

    public function saveCatalog(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        try {
            app(SaveCatalog::class)->handle($access, $this->kind, $this->editingId, $this->name);
        } catch (QueryException $exception) {
            report($exception);
            $this->addError('inventory', 'The catalog entry could not be saved. Try again.');

            return;
        } catch (\RuntimeException $exception) {
            $this->addError('inventory', $exception->getMessage());

            return;
        }
        $this->dialogOpen = false;
        Flux::toast(variant: 'success', text: ucfirst($this->kind).' saved.');
    }

    public function confirmDelete(string $kind, string $id): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        abort_unless(in_array($kind, ['category', 'location'], true), 422);
        $this->resetValidation();
        $this->deletingKind = $kind;
        $this->deletingId = $id;
        $this->deleteDialog = true;
    }

    public function deleteCatalog(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        if ($this->deletingId === null) {
            return;
        }
        try {
            app(DeleteCatalog::class)->handle($access, $this->deletingKind, $this->deletingId);
        } catch (QueryException $exception) {
            report($exception);
            $this->addError('inventory', 'The catalog entry could not be deleted. Try again.');

            return;
        } catch (\RuntimeException $exception) {
            $this->addError('inventory', $exception->getMessage());

            return;
        }
        $this->deleteDialog = false;
        Flux::toast(variant: 'success', text: ucfirst($this->deletingKind).' deleted.');
    }

    /** The inventory stamp includes catalog edits and deletions. */
    protected function changeStamp(): string
    {
        return app(InventoryRecords::class)->changeStamp(app(Access::class));
    }

    public function render(): View
    {
        $this->markStampRendered();
        $access = app(Access::class);
        $query = app(InventoryRecords::class);

        return view('livewire.inventory.catalogs-page', [
            'categories' => $query->catalogs($access, 'category'),
            'locations' => $query->catalogs($access, 'location'),
            'canManage' => $access->mayReadAllData(ModuleCode::INVENTORY),
        ]);
    }
}
