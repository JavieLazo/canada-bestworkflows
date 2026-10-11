<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Inventory\Application\Commands\RecordDisposal;
use App\Modules\Inventory\Application\Queries\InventoryAttachments;
use App\Modules\Inventory\Application\Queries\InventoryLifecycle;
use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Platform\Files\UploadLimits;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** Records office-authorized disposal only after custody and condition safeguards pass. */
#[Layout('layouts.app')]
#[Title('Inventory disposals')]
class DisposalsPage extends Component
{
    use WithFileUploads;
    use WithTableFilters;

    public bool $dialogOpen = false;

    public bool $evidenceOpen = false;

    #[Locked]
    public ?string $viewingId = null;

    /** Locked exception: form draft resolved against eligible stock by the command. */
    public string $itemId = '';

    #[Locked]
    public string $authorityId = '';

    public string $itemSearch = '';

    public string $disposedOn = '';

    public string $method = '';

    public string $reason = '';

    public string $authorizationReference = '';

    public ?TemporaryUploadedFile $upload = null;

    public ?string $formError = null;

    public function mount(): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->disposedOn = ManilaClock::dayKey(now());
    }

    public function openAdd(): void
    {
        abort_unless(app(Access::class)->mayReadAllData(ModuleCode::INVENTORY), 403);
        $this->reset('itemId', 'authorityId', 'method', 'reason', 'authorizationReference', 'upload', 'formError');
        $this->dialogOpen = true;
    }

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if ($name !== 'inventory-disposal-authority') {
            return;
        }
        abort_unless(app(InventoryLifecycle::class)->employeeExists($access, $id), 403);
        $this->authorityId = $id;
    }

    public function updatedUpload(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if ($this->upload && ((int) $this->upload->getSize() > UploadLimits::maxFileBytes() || ! in_array($this->upload->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true))) {
            $this->upload = null;
            $this->addError('upload', 'Choose a PDF, JPEG or PNG within the attachment limit.');
        }
    }

    public function save(): void
    {
        $access = app(Access::class);
        abort_unless($access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if ($this->upload === null) {
            $this->addError('upload', 'Attach the office’s disposal authorization.');

            return;
        }
        try {
            app(RecordDisposal::class)->save($access, ['item_id' => $this->itemId, 'disposed_on' => $this->disposedOn, 'method' => $this->method, 'reason' => $this->reason, 'authorization_reference' => $this->authorizationReference, 'authorized_by_employee_id' => $this->authorityId], $this->upload);
            $this->dialogOpen = false;
            $this->upload = null;
            Flux::toast(variant: 'success', text: 'Authorized disposal recorded.');
        } catch (QueryException $error) {
            report($error);
            $this->formError = 'The disposal could not be saved. Try again.';
        } catch (\RuntimeException $error) {
            $this->formError = $error->getMessage();
        }
    }

    public function viewEvidence(string $id): void
    {
        app(InventoryAttachments::class)->disposal(app(Access::class), $id);
        $this->viewingId = $id;
        $this->evidenceOpen = true;
    }

    public function render(): View
    {
        $access = app(Access::class);

        return view('livewire.inventory.disposals-page', [
            'rows' => $this->acceptPage(app(InventoryLifecycle::class)->disposals($access, $this->search, (int) $this->getPage(), $this->perPage)),
            'items' => $this->dialogOpen ? app(InventoryRecords::class)->items($access, ['search' => $this->itemSearch, 'status' => 'IN_STOCK', 'condition' => 'UNSERVICEABLE'], 'code', 'asc', 1, 50) : [],
            'files' => $this->viewingId ? app(InventoryAttachments::class)->disposal($access, $this->viewingId) : [],
        ]);
    }
}
