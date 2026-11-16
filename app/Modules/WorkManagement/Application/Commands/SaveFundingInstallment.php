<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectFunding;
use App\Models\ProjectFundingInstallment;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\FundingRules;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Contracts\Views\FundingInstallmentView;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveFundingInstallment
{
    public function handle(Access $actor, string $projectId, string $fundingId, ?string $id, int $revision, string $name, string $amount, ?string $plannedOn = null, string $conditions = '', string $status = 'ACTIVE', string $reason = ''): FundingInstallmentView
    {
        return DB::transaction(static function () use ($actor, $projectId, $fundingId, $id, $revision, $name, $amount, $plannedOn, $conditions, $status, $reason): FundingInstallmentView {
            $project = FinanceRules::locked($actor, $projectId, edit: true);
            $funding = ProjectFunding::query()->where('project_id', $project->id)->whereKey($fundingId)->lockForUpdate()->first();
            abort_if($funding === null, 404);
            Validator::make(['name' => trim($name), 'conditions' => trim($conditions), 'status' => $status], ['name' => 'required|string|max:160', 'conditions' => 'nullable|string|max:4000', 'status' => 'required|in:ACTIVE,DISCONTINUED'])->validate();
            FundingRules::date($plannedOn, 'plannedOn');
            $minor = FinanceAmount::minor($amount, 'amount');
            $row = $id === null ? null : ProjectFundingInstallment::query()->where('funding_id', $funding->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            ReportingRules::revision($row, $revision, 'installment');
            $others = (int) ProjectFundingInstallment::query()->where('funding_id', $funding->id)->where('status', 'ACTIVE')
                ->when($row !== null, static fn ($query) => $query->where('id', '!=', $row->id))->lockForUpdate()->sum('planned_minor');
            $next = $others + ($status === 'ACTIVE' ? $minor : 0);
            if ($next > FinanceAmount::LIMIT || ($funding->committed_minor !== null && $next > $funding->committed_minor)) {
                throw ValidationException::withMessages(['amount' => 'The active installment total exceeds the funding commitment.']);
            }
            $row ??= new ProjectFundingInstallment;
            ReportingRules::history($row, $actor, $reason);
            $row->forceFill(['project_id' => $project->id, 'funding_id' => $funding->id, 'name' => trim($name), 'planned_minor' => $minor,
                'planned_on' => $plannedOn, 'conditions' => trim($conditions) ?: null, 'status' => $status, 'revision' => $revision + 1,
                'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_FUNDING_SAVED, 'projects', $project->id, 'Funding installment saved');

            return Snapshot::of($row, FundingInstallmentView::class);
        });
    }
}
