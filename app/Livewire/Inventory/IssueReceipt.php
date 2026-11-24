<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\TableDefaults;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\LocalClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithoutUrlPagination;

/** Receipt creation keeps employee and stock selection together on the receipt register. */
class IssueReceipt extends Component
{
    use WithoutUrlPagination;
    use WithTableFilters;

    public bool $dialogOpen = false;

    /** Checkbox drafts are not Locked; IssueItems revalidates every selected item.
     * @var list<string>
     */
    public array $selectedIds = [];

    #[Locked]
    public string $employeeId = '';

    #[Locked]
    public string $issuerId = '';

    public string $issuedOn = '';

    public string $mrNumber = '';

    public string $remarks = '';

    public function open(): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        $this->reset('selectedIds', 'employeeId', 'issuerId', 'mrNumber', 'search', 'remarks');
        $this->resetPage('issueStockPage');
        $this->issuedOn = LocalClock::todayKey();
        $this->dialogOpen = true;
    }

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if (! in_array($name, ['issue-receipt-recipient', 'issue-receipt-issuer'], true)) {
            return;
        }
        if (app(OperationalDirectory::class)->find($access, $id) === null) {
            $this->addError('inventory', 'Choose an employee from the directory.');

            return;
        }
        if ($name === 'issue-receipt-recipient') {
            $this->employeeId = $id;
        } else {
            $this->issuerId = $id;
        }
    }

    public function updatedPerPage(mixed $value): void
    {
        $this->perPage = in_array((int) $value, TableDefaults::PAGE_SIZE_OPTIONS, true)
            ? (int) $value : TableDefaults::DEFAULT_PAGE_SIZE;
        $this->resetPage('issueStockPage');
    }

    public function save(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        try {
            $id = app(IssueItems::class)->handle($access, $this->selectedIds, $this->employeeId, $this->issuerId, $this->issuedOn, $this->mrNumber, $this->remarks);
        } catch (QueryException $exception) {
            report($exception);
            $this->addError('inventory', 'The receipt could not be saved. Check the MR No. and try again.');

            return;
        } catch (\RuntimeException $exception) {
            $this->addError('inventory', $exception->getMessage());

            return;
        }
        $this->dialogOpen = false;
        Flux::toast(variant: 'success', text: 'Memorandum receipt issued.');
        $this->redirectRoute('inventory.receipts.show', ['id' => $id], navigate: true);
    }

    public function render(): View
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $rows = null;
        if ($this->dialogOpen) {
            $rows = app(InventoryRecords::class)->items($access, ['search' => $this->search, 'status' => 'IN_STOCK', 'condition' => 'SERVICEABLE'], 'code', 'asc', (int) $this->getPage('issueStockPage'), $this->perPage);
            $rows->setPageName('issueStockPage');
            $rows = $this->acceptPage($rows, 'issueStockPage');
        }

        return view('livewire.inventory.issue-receipt', ['stockItems' => $rows]);
    }
}
