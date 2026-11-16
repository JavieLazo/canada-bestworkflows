<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\FundingRules;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordAllocatedCash
{
    public function handle(Access $actor, string $projectId, CashEntryDraft $input, string $sourceKind, ?string $sourceId, ?string $installmentId = null): string
    {
        return DB::transaction(static function () use ($actor, $projectId, $input, $sourceKind, $sourceId, $installmentId): string {
            $project = FinanceRules::locked($actor, $projectId, edit: true);
            $source = FundingRules::source($project->id, $sourceKind, $sourceId, $installmentId);
            $existing = $input->requestId === null ? null : ProjectCashEntry::query()->where('project_id', $project->id)->whereKey($input->requestId)->lockForUpdate()->first();
            $id = app(RecordCashEntry::class)->handle($actor, $project->id, $input);
            $entry = ProjectCashEntry::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($existing !== null) {
                $rows = ProjectCashAllocation::query()->where('entry_id', $id)->where('revision', $entry->allocation_revision)->get();
                $row = $rows->first();
                if ($rows->count() !== 1 || $row === null || $row->source_kind !== $source['source_kind'] || $row->source_id !== $source['source_id']
                    || $row->installment_id !== $source['installment_id'] || $row->amount_minor !== $entry->amount_minor || $row->group_id !== $entry->group_id) {
                    throw ValidationException::withMessages(['entry' => 'This form was already recorded with a different allocation. Open a new entry or revise its allocation.']);
                }

                return $id;
            }
            app(SetCashAllocations::class)->handle($actor, $project->id, $id, 0, [['amount' => FinanceAmount::input($entry->amount_minor),
                'sourceKind' => $source['source_kind'], 'sourceId' => $source['source_id'], 'installmentId' => $source['installment_id'], 'groupId' => $entry->group_id]]);

            return $id;
        }, 3);
    }
}
