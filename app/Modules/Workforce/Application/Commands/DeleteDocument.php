<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Infrastructure\RecordChangeStorageUsage;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Owns attachment authorization, persistence and audit; the UI handles upload state only. */
final class DeleteDocument
{
    public function handle(Access $access, string $employeeId, string $documentId): ?string
    {
        abort_unless($access->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        $document = DB::transaction(static function () use ($access, $employeeId, $documentId): ?EmployeeDocument {
            abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
            abort_if(EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first() === null, 404);
            $document = EmployeeDocument::query()->where('employee_id', $employeeId)->whereKey($documentId)->first();
            if ($document !== null) {
                $document->delete();
                if (! app(RecordChangeStorageUsage::class)->retains($document->path)) {
                    DB::afterCommit(static fn () => Storage::disk('local')->delete($document->path));
                }
            }

            return $document;
        });
        if ($document === null) {
            return null;
        }
        AuditService::record($access, AuditEvent::DOCUMENT_DELETED, 'employee_documents', $documentId, "{$document->name} deleted");

        return $document->name;
    }
}
