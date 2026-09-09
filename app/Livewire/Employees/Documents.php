<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\ProjectTaskFile;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * an ownership check. 2 MB per file, and a storage ceiling shared with task
 * attachments (BESTWORKFLOWS_STORAGE_CEILING_BYTES, default 1 GB).
 */
class Documents extends Component
{
    use WithFileUploads;
    use WithTableFilters;

    #[Locked]
    public string $employeeId;

    public bool $adding = false;

    /** @var array{document_no: string, name: string, category: string, expires_on: string} */
    public array $form = ['document_no' => '', 'name' => '', 'category' => '', 'expires_on' => ''];

    /**
     * The picked file (Livewire temporary upload).
     *
     * @var TemporaryUploadedFile|null
     */
    public $upload = null;

    public function mount(string $employeeId): void
    {
        $this->employeeId = $employeeId;
    }

    /**
     * allowed() (api/employees/[id]/documents/route.ts:33-37): the Employees
     * module with `all_data`, or the caller's own 201 file.
     */
    private function allowed(Access $access): bool
    {
        return $access->mayReadAllData(ModuleCode::EMPLOYEES)
            || $access->employeeId === $this->employeeId;
    }

    /** One budget across every employee document AND every task file. */
    private function usedBytes(): int
    {
        return (int) EmployeeDocument::query()->sum('size')
            + (int) ProjectTaskFile::query()->sum('size');
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
        if ($this->upload === null) {
            return;
        }

        $size = (int) $this->upload->getSize();

        if ($size > EmployeeFields::MAX_FILE_BYTES) {
            // attachmentRejection (attachments.ts:52-57), API-mode wording.
            Flux::toast(
                text: $this->upload->getClientOriginalName().' is '.EmployeeFields::humanSize($size)
                    .' — the limit is '.EmployeeFields::humanSize(EmployeeFields::MAX_FILE_BYTES).' per file.',
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

        $employee = EmployeeProfile::query()->find($this->employeeId);

        if ($employee === null) {
            return;
        }

        if ($this->upload === null) {
            Flux::toast(text: 'Choose a file to attach.', variant: 'danger');

            return;
        }

        if ($this->form['category'] === '') {
            Flux::toast(text: 'Choose a category.', variant: 'danger');

            return;
        }

        // The API's own checks (api/employees/[id]/documents/route.ts:60-72).
        if (DocumentType::query()->where('name', $this->form['category'])->doesntExist()) {
            Flux::toast(text: 'Choose a category from Document Settings.', variant: 'danger');

            return;
        }

        $expires = trim($this->form['expires_on']);

        if ($expires !== '' && ! EmployeeFields::isCalendarDay($expires)) {
            Flux::toast(text: 'Expiration must be a real calendar date.', variant: 'danger');

            return;
        }

        $size = (int) $this->upload->getSize();

        if ($size > EmployeeFields::MAX_FILE_BYTES) {
            Flux::toast(text: 'The uploaded file is larger than the 2 MB limit.', variant: 'danger');

            return;
        }

        if ($this->usedBytes() + $size > EmployeeFields::storageCeilingBytes()) {
            // assertStorageCeiling (lib/api/storage.ts:18-29).
            Flux::toast(text: 'The file store is full. Delete old attachments before uploading more.', variant: 'danger');

            return;
        }

        $originalName = $this->upload->getClientOriginalName();
        $extension = strtolower((string) $this->upload->getClientOriginalExtension());
        $storedName = (string) Str::ulid().($extension !== '' ? '.'.$extension : '');

        $path = $this->upload->storeAs('employee-documents', $storedName, 'local');

        if ($path === false) {
            Flux::toast(text: 'Upload failed', variant: 'danger');

            return;
        }

        $name = trim($this->form['name']) !== '' ? trim($this->form['name']) : $originalName;

        $document = new EmployeeDocument;
        $document->fill([
            'employee_id' => $this->employeeId,
            'document_no' => trim($this->form['document_no']) !== '' ? mb_substr(trim($this->form['document_no']), 0, 80) : null,
            'name' => mb_substr($name, 0, 200),
            'category' => mb_substr($this->form['category'], 0, 80),
            'expires_on' => $expires !== '' ? $expires : null,
            'file_name' => mb_substr($originalName, 0, 300),
            // The stored file is the truth, not the client's claim.
            'mime' => mb_substr((string) $this->upload->getMimeType(), 0, 120),
            'size' => $size,
            'path' => $path,
        ]);
        // Actor column: from the session, never from the request.
        $document->uploaded_by = $access->userId === null ? null : (int) $access->userId;
        $document->save();

        AuditService::record(
            $access,
            AuditEvent::DOCUMENT_UPLOADED,
            'employee_documents',
            $document->id,
            "{$document->name} attached",
        );

        Flux::toast(text: 'Document added.', variant: 'success');
        $this->resetDialog();
        $this->adding = false;
    }

    public function remove(string $documentId): void
    {
        $access = app(Access::class);

        if (! $this->allowed($access)) {
            abort(403);
        }

        // Narrowed to THIS employee's file before the id is honoured.
        $document = EmployeeDocument::query()
            ->where('employee_id', $this->employeeId)
            ->whereKey($documentId)
            ->first();

        if ($document === null) {
            return;
        }

        Storage::disk('local')->delete($document->path);
        $document->delete();

        AuditService::record(
            $access,
            AuditEvent::DOCUMENT_DELETED,
            'employee_documents',
            $documentId,
            "{$document->name} deleted",
        );

        Flux::toast(text: "{$document->name} deleted.", variant: 'success');
    }

    public function render(): View
    {
        $access = app(Access::class);
        $employee = EmployeeProfile::query()->find($this->employeeId);

        $query = EmployeeDocument::query()
            ->where('employee_id', $this->employeeId)
            ->orderByDesc('created_at');

        if (! $this->allowed($access)) {
            $query->whereRaw('1 = 0');
        }

        $total = (clone $query)->count();
        $list = $this->paginateClamped($query, 'documentsPage');

        return view('livewire.employees.documents', [
            'employee' => $employee,
            'list' => $list,
            'total' => $total,
            'usedBytes' => $this->usedBytes(),
            'maxFileBytes' => EmployeeFields::MAX_FILE_BYTES,
            'categories' => DocumentType::query()->orderBy('name')->pluck('name')->all(),
            'today' => EmployeeFields::todayKey(),
        ]);
    }
}
