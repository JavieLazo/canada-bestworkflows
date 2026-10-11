<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Enums\ModuleCode;
use App\Modules\Inventory\Application\Commands\AttachSignedReceipt;
use App\Modules\Inventory\Application\Commands\DeleteSignedReceipt;
use App\Modules\Inventory\Application\Queries\InventoryAttachments;
use App\Platform\Files\UploadLimits;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** Shared receipt evidence panel keeps employee downloads scoped and manager writes separate. */
class SignedReceipt extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $receiptId;

    #[Locked]
    public bool $own = false;

    public ?TemporaryUploadedFile $upload = null;

    public function mount(string $receiptId, bool $own = false): void
    {
        $this->receiptId = $receiptId;
        $this->own = $own;
        app(InventoryAttachments::class)->receipt(app(Access::class), $receiptId, $own);
    }

    public function updatedUpload(): void
    {
        $access = app(Access::class);
        abort_unless(! $this->own && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if ($this->upload && ((int) $this->upload->getSize() > UploadLimits::maxFileBytes() || ! in_array($this->upload->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true))) {
            $this->upload = null;
            $this->addError('upload', 'Choose a PDF, JPEG or PNG within the configured attachment limit.');
        }
    }

    public function attach(): void
    {
        $access = app(Access::class);
        abort_unless(! $this->own && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
        if ($this->upload === null) {
            $this->addError('upload', 'Choose signed evidence to attach.');

            return;
        }
        try {
            app(AttachSignedReceipt::class)->save($access, $this->receiptId, $this->upload);
            $this->upload = null;
            Flux::toast(variant: 'success', text: 'Signed MR attached.');
        } catch (QueryException $error) {
            report($error);
            $this->addError('upload', 'The signed MR could not be attached. Try again.');
        } catch (\RuntimeException $error) {
            $this->addError('upload', $error->getMessage());
        }
    }

    public function deleteFile(string $id): void
    {
        $access = app(Access::class);
        abort_unless(! $this->own && $access->mayReadAllData(ModuleCode::INVENTORY), 403);
        $files = app(InventoryAttachments::class)->receipt($access, $this->receiptId);
        abort_unless($files->contains(fn ($file): bool => $file->id === $id), 403);
        app(DeleteSignedReceipt::class)->delete($access, $id);
        Flux::toast(variant: 'success', text: 'Signed MR attachment removed.');
    }

    public function render(): View
    {
        $access = app(Access::class);

        return view('livewire.inventory.signed-receipt', [
            'files' => app(InventoryAttachments::class)->receipt($access, $this->receiptId, $this->own),
            'canManage' => ! $this->own && $access->mayReadAllData(ModuleCode::INVENTORY),
            'maxBytes' => UploadLimits::maxFileBytes(),
        ]);
    }
}
