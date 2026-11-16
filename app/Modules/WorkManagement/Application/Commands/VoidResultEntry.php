<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectResultEntry;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class VoidResultEntry
{
    public function handle(Access $actor, string $projectId, string $id, string $reason): bool
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $reason): bool {
            $project = ReportingRules::locked($actor, $projectId, edit: true);
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:1000'])->validate();
            $entry = ProjectResultEntry::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($entry === null, 404);
            if ($entry->voided_at !== null) {
                return false;
            }
            $entry->forceFill(['voided_at' => now(), 'voided_by' => (int) $actor->userId, 'void_reason' => trim($reason)])->save();
            AuditService::record($actor, AuditEvent::PROJECT_RESULT_VOIDED, 'projects', $project->id, 'Accomplishment voided with its history retained');

            return true;
        });
    }
}
