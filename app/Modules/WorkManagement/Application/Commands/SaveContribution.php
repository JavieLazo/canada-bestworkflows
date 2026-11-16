<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\FundingRules;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Contracts\Views\ContributionView;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveContribution
{
    public function handle(Access $actor, string $projectId, ?string $id, int $revision, string $name, string $contributor, string $kind,
        string $amount = '', string $quantity = '', string $unit = '', string $valuationBasis = '', ?string $expectedOn = null, string $notes = '', string $status = 'ACTIVE', string $reason = ''): ContributionView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $name, $contributor, $kind, $amount, $quantity, $unit, $valuationBasis, $expectedOn, $notes, $status, $reason): ContributionView {
            $project = FinanceRules::locked($actor, $projectId, edit: true);
            FinanceRules::settings($project->id);
            Validator::make(['name' => trim($name), 'contributor' => trim($contributor), 'kind' => $kind, 'unit' => trim($unit), 'valuationBasis' => trim($valuationBasis), 'notes' => trim($notes), 'status' => $status], [
                'name' => 'required|string|max:200', 'contributor' => 'required|string|max:200', 'kind' => 'required|in:CASH,IN_KIND', 'unit' => 'nullable|string|max:60',
                'valuationBasis' => [$kind === 'IN_KIND' && trim($amount) !== '' ? 'required' : 'nullable', 'string', 'max:4000'], 'notes' => 'nullable|string|max:4000', 'status' => 'required|in:ACTIVE,DISCONTINUED',
            ])->validate();
            FundingRules::date($expectedOn, 'expectedOn');
            $minor = trim($amount) === '' ? null : FinanceAmount::minor($amount, 'amount');
            $scaled = $kind === 'IN_KIND' ? ResultValue::parse($quantity, 'quantity') : null;
            if ($scaled !== null && ($scaled < 0 || trim($unit) === '')) {
                throw ValidationException::withMessages(['quantity' => 'Use a non-negative quantity and its unit.']);
            }
            $row = $id === null ? null : ProjectContribution::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            ReportingRules::revision($row, $revision, 'contribution');
            if ($row !== null && ($row->kind !== $kind || ($row->unit ?? '') !== ($kind === 'CASH' ? '' : trim($unit)))
                && (ProjectContributionDelivery::query()->where('contribution_id', $row->id)->lockForUpdate()->first() !== null
                    || ProjectCashAllocation::query()->where('source_kind', 'CONTRIBUTION')->where('source_id', $row->id)->lockForUpdate()->first() !== null)) {
                throw ValidationException::withMessages(['kind' => 'Keep the contribution kind and unit after deliveries exist. Add another contribution instead.']);
            }
            $row ??= new ProjectContribution;
            ReportingRules::history($row, $actor, $reason);
            $row->forceFill(['project_id' => $project->id, 'name' => trim($name), 'contributor' => trim($contributor), 'kind' => $kind, 'unit' => $kind === 'IN_KIND' ? (trim($unit) ?: null) : null,
                'quantity_scaled' => $scaled, 'committed_minor' => $minor, 'valuation_basis' => $kind === 'IN_KIND' ? (trim($valuationBasis) ?: null) : null,
                'expected_on' => $expectedOn, 'notes' => trim($notes) ?: null, 'status' => $status, 'revision' => $revision + 1,
                'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_CONTRIBUTION_SAVED, 'projects', $project->id, 'Contribution commitment saved');

            return Snapshot::of($row, ContributionView::class);
        });
    }
}
