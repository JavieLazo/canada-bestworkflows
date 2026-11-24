<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Domain\Enums\ModuleCode;
use App\Modules\Inventory\Application\Commands\DeleteItem;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\ReturnItems;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Application\Commands\TransferItems;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\LocalClock;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/** Shared presentation drafts keep the item list and detail actions consistent. */
trait ManagesInventory
{
    public bool $itemDialog = false;

    #[Locked]
    public ?string $editingId = null;

    /** Form drafts are revalidated against current records by SaveItem; not Locked.
     * @var array<string, string>
     */
    public array $form = ['code' => '', 'name' => '', 'serial_no' => '', 'acquired_on' => '', 'cost' => '', 'category_id' => '', 'location_id' => '', 'remarks' => ''];

    /** Checkbox draft; not Locked because each selected id is revalidated by the command.
     * @var list<string>
     */
    public array $selectedIds = [];

    public bool $movementDialog = false;

    #[Locked]
    public string $movement = 'issue';

    #[Locked]
    public string $employeeId = '';

    #[Locked]
    public string $issuerId = '';

    public string $movementDate = '';

    public string $mrNumber = '';

    public string $movementRemarks = '';

    public bool $deleteDialog = false;

    #[Locked]
    public ?string $deletingId = null;

    public function openAdd(): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'serial_no' => '', 'acquired_on' => LocalClock::todayKey(), 'cost' => '', 'category_id' => '', 'location_id' => '', 'remarks' => ''];
        $this->itemDialog = true;
    }

    public function startEdit(string $id): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $item = app(InventoryRecords::class)->item($access, $id);
        abort_if($item === null, 404);
        $this->resetValidation();
        $this->editingId = $item->id;
        foreach (array_keys($this->form) as $key) {
            $this->form[$key] = (string) ($item->{$key} ?? '');
        }
        $this->itemDialog = true;
    }

    public function saveItem(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        try {
            app(SaveItem::class)->handle($access, $this->editingId, $this->form);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('form.'.$field, $message);
                }
            }

            return;
        } catch (QueryException $exception) {
            report($exception);
            $this->addError('inventory', 'The item could not be saved. Try again.');

            return;
        } catch (\RuntimeException $exception) {
            $this->addError('inventory', $exception->getMessage());

            return;
        }
        $this->itemDialog = false;
        Flux::toast(variant: 'success', text: 'Item saved.');
    }

    public function confirmDelete(string $id): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        $this->deletingId = $id;
        $this->deleteDialog = true;
    }

    public function deleteItem(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        if ($this->deletingId === null) {
            return;
        }
        try {
            app(DeleteItem::class)->handle($access, $this->deletingId);
        } catch (QueryException $exception) {
            report($exception);
            $this->addError('inventory', 'The item could not be deleted. Try again.');

            return;
        } catch (\RuntimeException $exception) {
            $this->addError('inventory', $exception->getMessage());

            return;
        }
        $this->deleteDialog = false;
        $this->selectedIds = array_values(array_diff($this->selectedIds, [$this->deletingId]));
        $this->deletingId = null;
        Flux::toast(variant: 'success', text: 'Item deleted.');
        $this->redirectRoute('inventory.items', navigate: true);
    }

    public function startMovement(string $movement, ?string $id = null): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        abort_unless(in_array($movement, ['issue', 'transfer', 'return'], true), 422);
        $this->resetValidation();
        if ($id !== null) {
            $this->selectedIds = [$id];
        }
        if ($this->selectedIds === []) {
            $this->addError('inventory', 'Select at least one item first.');

            return;
        }
        $this->movement = $movement;
        $this->employeeId = '';
        $this->issuerId = '';
        $this->movementDate = LocalClock::todayKey();
        $this->movementRemarks = '';
        $this->mrNumber = '';
        $this->movementDialog = true;
    }

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if (! in_array($name, ['inventory-recipient', 'inventory-issuer'], true)) {
            return;
        }
        if (app(OperationalDirectory::class)->find($access, $id) === null) {
            $this->addError('inventory', 'Choose an employee from the directory.');

            return;
        }
        if ($name === 'inventory-recipient') {
            $this->employeeId = $id;
        } else {
            $this->issuerId = $id;
        }
    }

    public function saveMovement(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        try {
            if ($this->movement === 'return') {
                app(ReturnItems::class)->handle($access, $this->selectedIds, $this->movementDate, $this->movementRemarks);
                $receiptId = null;
            } elseif ($this->movement === 'transfer') {
                $receiptId = app(TransferItems::class)->handle($access, $this->selectedIds, $this->employeeId, $this->issuerId, $this->movementDate, $this->mrNumber, $this->movementRemarks);
            } else {
                $receiptId = app(IssueItems::class)->handle($access, $this->selectedIds, $this->employeeId, $this->issuerId, $this->movementDate, $this->mrNumber, $this->movementRemarks);
            }
        } catch (QueryException $exception) {
            report($exception);
            $this->addError('inventory', 'The movement could not be saved. Check the details and try again.');

            return;
        } catch (\RuntimeException $exception) {
            $this->addError('inventory', $exception->getMessage());

            return;
        }
        $this->movementDialog = false;
        $this->selectedIds = [];
        Flux::toast(variant: 'success', text: $receiptId === null ? 'Items returned to stock.' : 'Memorandum receipt issued.');
        if ($receiptId !== null) {
            $this->redirectRoute('inventory.receipts.show', ['id' => $receiptId], navigate: true);
        }
    }
}
