<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\FundingRules;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SetCashAllocations
{
    /** @param list<array{amount: string, sourceKind?: string, sourceId?: string|null, installmentId?: string|null, groupId?: string|null}> $input */
    public function handle(Access $actor, string $projectId, string $entryId, int $revision, array $input, string $reason = ''): int
    {
        return DB::transaction(static function () use ($actor, $projectId, $entryId, $revision, $input, $reason): int {
            $project = FinanceRules::locked($actor, $projectId, edit: true);
            $entry = ProjectCashEntry::query()->where('project_id', $project->id)->whereKey($entryId)->lockForUpdate()->first();
            abort_if($entry === null, 404);
            if ($entry->voided_at !== null || $entry->allocation_revision !== $revision) {
                throw ValidationException::withMessages(['allocations' => 'This entry or its allocations changed. Reopen the current entry.']);
            }
            Validator::make(['allocations' => $input, 'reason' => trim($reason)], ['allocations' => 'required|array|min:1|max:100',
                'allocations.*.amount' => 'required|string', 'allocations.*.sourceKind' => 'nullable|string|in:NONE,FUNDING,CONTRIBUTION',
                'allocations.*.sourceId' => 'nullable|string', 'allocations.*.installmentId' => 'nullable|string', 'allocations.*.groupId' => 'nullable|string',
                'reason' => [$revision > 0 ? 'required' : 'nullable', 'string', 'max:1000']])->validate();
            $rows = [];
            $total = 0;
            foreach ($input as $item) {
                $minor = FinanceAmount::minor($item['amount'], 'allocations', positive: true);
                if ($total > $entry->amount_minor - $minor) {
                    throw ValidationException::withMessages(['allocations' => 'Split amounts must add up to the recorded cash amount.']);
                }
                $source = FundingRules::source($project->id, $item['sourceKind'] ?? 'NONE', $item['sourceId'] ?? null, $item['installmentId'] ?? null);
                $group = FinanceRules::group($project->id, $item['groupId'] ?? null);
                $rows[] = [...$source, 'group_id' => $group?->id, 'group_label' => $group?->name, 'amount_minor' => $minor];
                $total += $minor;
            }
            if ($total !== $entry->amount_minor) {
                throw ValidationException::withMessages(['allocations' => 'Split amounts must add up to the recorded cash amount.']);
            }
            foreach ($rows as $row) {
                $allocation = new ProjectCashAllocation;
                $allocation->forceFill([...$row, 'project_id' => $project->id, 'entry_id' => $entry->id, 'revision' => $revision + 1,
                    'reason' => trim($reason) ?: null, 'created_by' => (int) $actor->userId])->save();
            }
            $entry->allocation_revision++;
            $entry->save();
            AuditService::record($actor, AuditEvent::PROJECT_CASH_ALLOCATED, 'projects', $project->id, 'Cash allocation version '.$entry->allocation_revision.' saved');

            return $entry->allocation_revision;
        });
    }
}
