<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectRecordFile;
use App\Modules\WorkManagement\Application\RecordEvidence;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;

final class RemoveRecordEvidence
{
    public function handle(Access $actor, string $projectId, string $id): bool
    {
        return DB::transaction(static function () use ($actor, $projectId, $id): bool {
            $file = ProjectRecordFile::query()->where('project_id', $projectId)->whereKey($id)->first();
            abort_if($file === null, 404);
            RecordEvidence::locked($actor, $projectId, $file->entity_type, $file->record_id, true);
            $file = ProjectRecordFile::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($file->removed_at !== null) {
                return false;
            }
            $file->removed_at = now();
            $file->save();
            AuditService::record($actor, AuditEvent::PROJECT_RECORD_FILE_REMOVED, 'projects', $projectId, 'Project evidence removed from the current view');

            // shortcut: Keep removed evidence bytes; add retention-aware purging once the storage policy is agreed.
            return true;
        });
    }
}
