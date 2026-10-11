<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Platform\Files\AttachmentStore;
use App\Platform\Files\AttachmentUsage;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Owns attachment authorization, persistence and audit; the UI handles upload state only. */
final class AttachDocument
{
    /** @param array{document_no: string, name: string, category: string, expires_on: string} $form */
    public function handle(Access $access, string $employeeId, ?UploadedFile $upload, array $form): ?string
    {
        abort_unless($access->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        $employee = EmployeeProfile::query()->find($employeeId);
        if ($employee === null) {
            return null;
        }
        if ($upload === null) {
            throw new \RuntimeException('Choose a file to attach.');
        }
        if ($form['category'] === '') {
            throw new \RuntimeException('Choose a category.');
        }
        // The API's own checks (api/employees/[id]/documents/route.ts:60-72).
        if (DocumentType::query()->where('name', $form['category'])->doesntExist()) {
            throw new \RuntimeException('Choose a category from Document Settings.');
        }
        $expires = trim($form['expires_on']);
        if ($expires !== '' && ! EmployeeFields::isCalendarDay($expires)) {
            throw new \RuntimeException('Expiration must be a real calendar date.');
        }
        $size = (int) $upload->getSize();
        if ($size > EmployeeFields::maxFileBytes()) {
            throw new \RuntimeException('The uploaded file is larger than the '.EmployeeFields::humanSize(EmployeeFields::maxFileBytes()).' limit.');
        }
        if (AttachmentUsage::bytes() + $size > EmployeeFields::storageCeilingBytes()) {
            throw new \RuntimeException('The file store is full. Delete old attachments before uploading more.');
        }
        $originalName = $upload->getClientOriginalName();
        $extension = strtolower((string) $upload->getClientOriginalExtension());
        $storedName = (string) Str::ulid().($extension !== '' ? '.'.$extension : '');
        try {
            $document = app(AttachmentStore::class)->store($upload, 'employee-documents', $storedName, function (string $path) use ($access, $employeeId, $form, $expires, $originalName, $size, $upload): EmployeeDocument {
                $name = trim($form['name']) !== '' ? trim($form['name']) : $originalName;
                $document = new EmployeeDocument;
                $document->fill([
                    'employee_id' => $employeeId,
                    'document_no' => trim($form['document_no']) !== '' ? mb_substr(trim($form['document_no']), 0, 80) : null,
                    'name' => mb_substr($name, 0, 200),
                    'category' => mb_substr($form['category'], 0, 80),
                    'expires_on' => $expires !== '' ? $expires : null,
                    'file_name' => mb_substr($originalName, 0, 300),
                    // The stored file is the truth, not the client's claim.
                    'mime' => mb_substr((string) $upload->getMimeType(), 0, 120),
                    'size' => $size,
                    'path' => $path,
                ]);
                // Actor column: from the session, never from the request.
                $document->uploaded_by = $access->userId === null ? null : (int) $access->userId;
                $document->save();

                return $document;
            }, ceiling: EmployeeFields::storageCeilingBytes(), lockOwner: static function () use ($access, $employeeId): void {
                abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
                abort_if(EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first() === null, 404);
            });
        } catch (Throwable $e) {
            Log::error('[documents] upload failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Upload failed');
        }
        AuditService::record($access, AuditEvent::DOCUMENT_UPLOADED, 'employee_documents', $document->id, "{$document->name} attached");

        return $document->name;
    }
}
