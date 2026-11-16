<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectCashEntry;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecordCashEntry
{
    public function handle(Access $actor, string $projectId, CashEntryDraft $input): string
    {
        return DB::transaction(static function () use ($actor, $projectId, $input): string {
            FinanceRules::locked($actor, $projectId, edit: true);
            FinanceRules::settings($projectId);
            $values = ['kind' => $input->kind, 'description' => trim($input->description), 'party' => trim($input->party),
                'reference' => trim($input->reference), 'notes' => trim($input->notes), 'correctionReason' => trim($input->correctionReason), 'requestId' => $input->requestId];
            Validator::make($values, ['kind' => ['required', Rule::in(['RECEIPT', 'EXPENSE'])], 'description' => 'required|string|max:200',
                'party' => 'nullable|string|max:200', 'reference' => 'nullable|string|max:200', 'notes' => 'nullable|string|max:4000',
                'requestId' => 'nullable|ulid', 'correctionReason' => [$input->replacesId === null ? 'nullable' : 'required', 'string', 'max:1000']])->validate();
            if (! TaskSchedule::isDateKey($input->occurredOn) || $input->occurredOn > ManilaClock::todayKey()) {
                throw ValidationException::withMessages(['occurredOn' => 'Choose an actual date through today.']);
            }
            $amount = FinanceAmount::minor($input->amount, 'amount', positive: true);
            if ($input->requestId !== null) {
                // An older transaction snapshot must not hide an accepted retry.
                $accepted = ProjectCashEntry::query()->where('project_id', $projectId)->whereKey($input->requestId)->lockForUpdate()->first();
                if ($accepted !== null) {
                    if ($accepted->created_by !== (int) $actor->userId || $accepted->kind !== $input->kind
                        || $accepted->occurred_on->format('Y-m-d') !== $input->occurredOn || $accepted->amount_minor !== $amount
                        || $accepted->description !== $values['description'] || $accepted->category_id !== $input->categoryId
                        || $accepted->group_id !== $input->groupId || ($accepted->party ?? '') !== $values['party']
                        || ($accepted->reference ?? '') !== $values['reference'] || ($accepted->notes ?? '') !== $values['notes']
                        || $accepted->replaces_id !== $input->replacesId) {
                        throw ValidationException::withMessages(['entry' => 'This form was already recorded. Open a new entry to record another transaction.']);
                    }

                    return $accepted->id;
                }
            }
            $category = FinanceRules::category($projectId, $input->categoryId);
            $group = FinanceRules::group($projectId, $input->groupId);
            if ($input->replacesId !== null) {
                $old = ProjectCashEntry::query()->where('project_id', $projectId)->whereKey($input->replacesId)->lockForUpdate()->first();
                abort_if($old === null, 404);
                if ($old->voided_at !== null) {
                    throw ValidationException::withMessages(['entry' => 'This entry was already voided or corrected. Reload the ledger.']);
                }
                $old->forceFill(['voided_at' => now(), 'voided_by' => (int) $actor->userId, 'void_reason' => $values['correctionReason']])->save();
            }
            // The limit includes entries committed before we acquired the project lock.
            $recorded = (int) ProjectCashEntry::query()->where('project_id', $projectId)->where('kind', $input->kind)->whereNull('voided_at')->lockForUpdate()->sum('amount_minor');
            if ($recorded > FinanceAmount::LIMIT - $amount) {
                throw ValidationException::withMessages(['amount' => 'The recorded total for this project is too large.']);
            }
            $entry = new ProjectCashEntry;
            if ($input->requestId !== null) {
                $entry->id = $input->requestId;
            }
            $entry->forceFill(['project_id' => $projectId, 'category_id' => $category->id, 'category_label' => $category->name,
                'group_id' => $group?->id, 'group_label' => $group?->name, 'kind' => $input->kind, 'occurred_on' => $input->occurredOn,
                'amount_minor' => $amount, 'description' => $values['description'], 'party' => $values['party'] === '' ? null : $values['party'],
                'reference' => $values['reference'] === '' ? null : $values['reference'], 'notes' => $values['notes'] === '' ? null : $values['notes'],
                'created_by' => (int) $actor->userId, 'replaces_id' => $input->replacesId])->save();
            AuditService::record($actor, $input->replacesId === null ? AuditEvent::PROJECT_CASH_RECORDED : AuditEvent::PROJECT_CASH_CORRECTED,
                'projects', $projectId, 'Cash entry '.$entry->id.($input->replacesId === null ? ' recorded' : ' replaces '.$input->replacesId));

            return $entry->id;
        });
    }
}
