<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\FundingRules;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordContributionDelivery
{
    public function handle(Access $actor, string $projectId, string $contributionId, string $date, string $quantity = '', string $value = '', string $basis = '', string $reference = '', string $notes = '', ?string $replacesId = null, string $reason = '', ?string $requestId = null): string
    {
        return DB::transaction(static function () use ($actor, $projectId, $contributionId, $date, $quantity, $value, $basis, $reference, $notes, $replacesId, $reason, $requestId): string {
            $project = FinanceRules::locked($actor, $projectId, edit: true);
            $contribution = ProjectContribution::query()->where('project_id', $project->id)->whereKey($contributionId)->lockForUpdate()->first();
            abort_if($contribution === null, 404);
            if ($contribution->kind !== 'IN_KIND') {
                throw ValidationException::withMessages(['contribution' => 'Record cash contributions once in the cash ledger and allocate the receipt to this contribution.']);
            }
            FundingRules::date($date, 'occurredOn', true);
            $scaled = ResultValue::parse($quantity, 'quantity');
            $minor = trim($value) === '' ? null : FinanceAmount::minor($value, 'amount');
            Validator::make(['basis' => trim($basis), 'reference' => trim($reference), 'notes' => trim($notes), 'reason' => trim($reason), 'requestId' => $requestId], [
                'basis' => [$minor !== null ? 'required' : 'nullable', 'string', 'max:4000'], 'reference' => 'nullable|string|max:200', 'notes' => 'nullable|string|max:4000',
                'reason' => [$replacesId !== null ? 'required' : 'nullable', 'string', 'max:1000'], 'requestId' => 'nullable|ulid',
            ])->validate();
            if (($scaled === null && $minor === null) || ($scaled !== null && ($scaled < 0 || $contribution->unit === null))) {
                throw ValidationException::withMessages(['quantity' => 'Record a non-negative quantity with its unit, an explicit valuation, or both.']);
            }
            $accepted = $requestId === null ? null : ProjectContributionDelivery::query()->where('project_id', $project->id)->whereKey($requestId)->lockForUpdate()->first();
            if ($accepted !== null) {
                if ($accepted->contribution_id !== $contribution->id || $accepted->occurred_on !== $date || $accepted->quantity_scaled !== $scaled
                    || $accepted->value_minor !== $minor || ($accepted->valuation_basis ?? '') !== trim($basis) || ($accepted->reference ?? '') !== trim($reference)
                    || ($accepted->notes ?? '') !== trim($notes) || $accepted->created_by !== (int) $actor->userId || $accepted->replaces_id !== $replacesId) {
                    throw ValidationException::withMessages(['delivery' => 'This form was already recorded. Open a new delivery.']);
                }

                return $accepted->id;
            }
            $old = $replacesId === null ? null : ProjectContributionDelivery::query()->where('contribution_id', $contribution->id)->whereKey($replacesId)->lockForUpdate()->first();
            abort_if($replacesId !== null && $old === null, 404);
            if ($old !== null) {
                if ($old->voided_at !== null) {
                    throw ValidationException::withMessages(['delivery' => 'This earlier delivery was already voided or corrected.']);
                }
                $old->forceFill(['voided_at' => now(), 'voided_by' => (int) $actor->userId, 'void_reason' => trim($reason)])->save();
            }
            $row = new ProjectContributionDelivery;
            if ($requestId !== null) {
                $row->id = $requestId;
            }
            $row->forceFill(['project_id' => $project->id, 'contribution_id' => $contribution->id, 'occurred_on' => $date, 'quantity_scaled' => $scaled,
                'value_minor' => $minor, 'valuation_basis' => trim($basis) ?: null, 'reference' => trim($reference) ?: null, 'notes' => trim($notes) ?: null,
                'replaces_id' => $old?->id, 'created_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_CONTRIBUTION_DELIVERED, 'projects', $project->id, 'In-kind delivery recorded without adding it to cash');

            return $row->id;
        }, 3);
    }

    public function void(Access $actor, string $projectId, string $id, string $reason): void
    {
        DB::transaction(static function () use ($actor, $projectId, $id, $reason): void {
            $project = FinanceRules::locked($actor, $projectId, edit: true);
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:1000'])->validate();
            $row = ProjectContributionDelivery::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->voided_at === null) {
                $row->forceFill(['voided_at' => now(), 'voided_by' => (int) $actor->userId, 'void_reason' => trim($reason)])->save();
                AuditService::record($actor, AuditEvent::PROJECT_CONTRIBUTION_DELIVERED, 'projects', $project->id, 'In-kind delivery voided with its history retained');
            }
        });
    }
}
