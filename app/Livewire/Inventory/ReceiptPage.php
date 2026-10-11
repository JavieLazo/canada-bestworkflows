<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithChangeStamp;
use App\Modules\Inventory\Application\Commands\CorrectReceipt;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Receipt details show frozen issue evidence and offer an explicit linked correction. */
#[Title('Memorandum receipt')]
class ReceiptPage extends Component
{
    use WithChangeStamp;

    #[Locked]
    public string $receiptId;

    public bool $correctionDialog = false;

    #[Locked]
    public string $employeeId = '';

    #[Locked]
    public string $issuerId = '';

    public string $issuedOn = '';

    public string $mrNumber = '';

    public string $reason = '';

    public function mount(string $id): void
    {
        $this->receiptId = $id;
    }

    public function openCorrection(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $receipt = app(InventoryRecords::class)->receipt($access, $this->receiptId);
        abort_if($receipt === null, 404);
        $this->resetValidation();
        $this->employeeId = $receipt->employee_id;
        $this->issuerId = $receipt->issuer_employee_id;
        $this->issuedOn = $receipt->issued_on;
        $this->reason = '';
        $this->mrNumber = '';
        $this->correctionDialog = true;
    }

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if (! in_array($name, ['correction-recipient', 'correction-issuer'], true)) {
            return;
        }
        if (app(OperationalDirectory::class)->find($access, $id) === null) {
            $this->addError('inventory', 'Choose an employee from the directory.');

            return;
        }
        if ($name === 'correction-recipient') {
            $this->employeeId = $id;
        } else {
            $this->issuerId = $id;
        }
    }

    public function correctReceipt(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->resetValidation();
        try {
            $replacement = app(CorrectReceipt::class)->handle($access, $this->receiptId, $this->employeeId, $this->issuerId, $this->issuedOn, $this->mrNumber, $this->reason);
        } catch (QueryException $exception) {
            report($exception);
            $this->addError('inventory', 'The corrected receipt could not be saved. Check the MR No. and try again.');

            return;
        } catch (\RuntimeException $exception) {
            $this->addError('inventory', $exception->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: 'Correction recorded. The original MR remains in history.');
        $this->redirectRoute('inventory.receipts.show', ['id' => $replacement], navigate: true);
    }

    /** The inventory stamp watches receipt corrections, custody and signed attachments. */
    protected function changeStamp(): string
    {
        return app(InventoryRecords::class)->changeStamp(app(Access::class));
    }

    public function render(): View
    {
        $this->markStampRendered();
        $access = app(Access::class);
        $receipt = app(InventoryRecords::class)->receipt($access, $this->receiptId);
        abort_if($receipt === null, 404);

        return view('livewire.inventory.receipt-page', [
            'receipt' => $receipt,
            'canManage' => $access->mayReadAllData(ModuleCode::INVENTORY),
        ]);
    }
}
