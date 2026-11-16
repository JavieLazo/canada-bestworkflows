<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectCashEntry;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class VoidCashEntry
{
    public function handle(Access $actor, string $projectId, string $entryId, string $reason): bool
    {
        return DB::transaction(static function () use ($actor, $projectId, $entryId, $reason): bool {
            FinanceRules::locked($actor, $projectId, edit: true);
            $entry = ProjectCashEntry::query()->where('project_id', $projectId)->whereKey($entryId)->lockForUpdate()->first();
            abort_if($entry === null, 404);
            if ($entry->voided_at !== null) {
                return false;
            }
            $reason = trim($reason);
            Validator::make(['voidReason' => $reason], ['voidReason' => 'required|string|max:1000'])->validate();
            $entry->forceFill(['voided_at' => now(), 'voided_by' => (int) $actor->userId, 'void_reason' => $reason])->save();
            AuditService::record($actor, AuditEvent::PROJECT_CASH_VOIDED, 'projects', $projectId, 'Cash entry '.$entry->id.' voided');

            return true;
        });
    }
}
