<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\TableDefaults;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Workforce\Application\Commands\AttachDocument;
use App\Modules\Workforce\Application\Commands\DeleteDocument;
use App\Modules\Workforce\Application\Queries\EmployeeDocuments;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Platform\Files\AttachmentUsage;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The Documents card on a 201 file — the port of
 * `src/app/(protected)/employees/[id]/employee-documents.tsx` and the
 * endpoints under `api/employees/[id]/documents`.
 *
 * Metadata lives in the row; the bytes live on the private local disk
 * (storage/app/private) and are served by DocumentDownloadController behind
 * an Employee Records management check. The per-file cap (COPEMS_MAX_UPLOAD_BYTES, default
 * 2 MB) and the storage ceiling shared with task attachments
 * (COPEMS_STORAGE_CEILING_BYTES, default 1 GB) both come from
 * `config('copems.uploads.*')` through EmployeeFields.
 */
class Documents extends Component
{
    use WithFileUploads;
    use WithTableFilters;

    #[Locked]
    public string $employeeId;

    #[Locked]
    public bool $own = false;

    public bool $adding = false;

    public string $category = '';

    /** @var array{document_no: string, name: string, category: string, expires_on: string} */
    public array $form = ['document_no' => '', 'name' => '', 'category' => '', 'expires_on' => ''];

    /**
     * The picked file (Livewire temporary upload).
     *
     * @var TemporaryUploadedFile|null
     */
    public $upload = null;

    public function mount(string $employeeId, bool $own = false): void
    {
        $this->own = $own;
        $this->employeeId = $employeeId;
        abort_unless($this->allowed(app(Access::class)), 403);
    }

    private function allowed(Access $access): bool
    {
        return ! $this->own && $access->mayReadAllData(ModuleCode::EMPLOYEES);
    }

    public function updatedPerPage(mixed $value): void
    {
        $size = (int) $value;
        $this->perPage = in_array($size, TableDefaults::PAGE_SIZE_OPTIONS, true)
            ? $size
            : TableDefaults::DEFAULT_PAGE_SIZE;
        $this->resetPage('documentsPage');
    }

    /** One budget across every employee document AND every task file. */
    private function usedBytes(): int
    {
        return AttachmentUsage::bytes();
    }

    /** Closing the dialog resets it. */
    public function updatedAdding(bool $value): void
    {
        if (! $value) {
            $this->resetDialog();
        }
    }

    private function resetDialog(): void
    {
        $this->form = ['document_no' => '', 'name' => '', 'category' => '', 'expires_on' => ''];
        $this->upload = null;
    }

    /**
     * pick() (employee-documents.tsx:279-289): reject over-cap immediately,
     * and default the label to the file name — which stays editable.
     */
    public function updatedUpload(): void
    {
        abort_unless($this->allowed(app(Access::class)), 403);
        if ($this->upload === null) {
            return;
        }

        $size = (int) $this->upload->getSize();

        if ($size > EmployeeFields::maxFileBytes()) {
            // attachmentRejection (attachments.ts:52-57), API-mode wording.
            Flux::toast(
                text: $this->upload->getClientOriginalName().' is '.EmployeeFields::humanSize($size)
                    .' — the limit is '.EmployeeFields::humanSize(EmployeeFields::maxFileBytes()).' per file.',
                variant: 'danger',
            );
            $this->upload = null;

            return;
        }

        if (trim($this->form['name']) === '') {
            $original = $this->upload->getClientOriginalName();
            $this->form['name'] = (string) preg_replace('/\.[^.]+$/', '', $original);
        }
    }

    public function submit(): void
    {
        $access = app(Access::class);
        if (! $this->allowed($access)) {
            abort(403);
        }
        try {
            $name = app(AttachDocument::class)->handle($access, $this->employeeId, $this->upload, $this->form);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'Upload failed', variant: 'danger');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        if ($name === null) {
            return;
        }
        Flux::toast(text: 'Document added.', variant: 'success');
        $this->resetDialog();
        $this->adding = false;
    }

    public function removeDocument(string $documentId): void
    {
        $access = app(Access::class);
        abort_unless($this->allowed($access), 403);
        $name = app(DeleteDocument::class)->handle($access, $this->employeeId, $documentId);
        if ($name !== null) {
            Flux::toast(text: "{$name} deleted.", variant: 'success');
        }
    }

    public function render(): View
    {
        $access = app(Access::class);
        abort_unless($this->allowed($access), 403);
        $employee = app(EmployeeRecords::class)->find($access, $this->employeeId);
        $documents = app(EmployeeDocuments::class);
        $list = $this->acceptPage($documents->page($access, $this->employeeId, (int) $this->getPage('documentsPage'), $this->perPage, $this->category), 'documentsPage');
        $total = $list->total();

        return view('livewire.employees.documents', [
            'employee' => $employee,
            'canEdit' => $this->allowed($access),
            'list' => $list,
            'total' => $total,
            'usedBytes' => $this->usedBytes(),
            'maxFileBytes' => EmployeeFields::maxFileBytes(),
            'categories' => $documents->categories($access),
            'filterCategories' => $documents->filterCategories($access, $this->employeeId),
            'today' => EmployeeFields::todayKey(),
        ]);
    }
}
