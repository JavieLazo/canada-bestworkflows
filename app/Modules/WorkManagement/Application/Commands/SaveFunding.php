<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectFunding;
use App\Models\ProjectFundingInstallment;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\FundingRules;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Modules\WorkManagement\Contracts\Views\FundingView;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveFunding
{
    public function handle(Access $actor, string $projectId, ?string $id, int $revision, string $name, string $provider, string $amount = '', ?string $expectedOn = null, string $notes = '', string $status = 'ACTIVE', string $reason = ''): FundingView
    {
        return DB::transaction(static function () use ($actor, $projectId, $id, $revision, $name, $provider, $amount, $expectedOn, $notes, $status, $reason): FundingView {
            $project = FinanceRules::locked($actor, $projectId, edit: true);
            FinanceRules::settings($project->id);
            Validator::make(['name' => trim($name), 'provider' => trim($provider), 'notes' => trim($notes), 'status' => $status], [
                'name' => 'required|string|max:200', 'provider' => 'nullable|string|max:200', 'notes' => 'nullable|string|max:4000', 'status' => 'required|in:ACTIVE,DISCONTINUED',
            ])->validate();
            FundingRules::date($expectedOn, 'expectedOn');
            $minor = trim($amount) === '' ? null : FinanceAmount::minor($amount, 'amount');
            $row = $id === null ? null : ProjectFunding::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            ReportingRules::revision($row, $revision, 'funding');
            if ($row !== null && $minor !== null && (int) ProjectFundingInstallment::query()->where('funding_id', $row->id)->where('status', 'ACTIVE')->lockForUpdate()->sum('planned_minor') > $minor) {
                throw ValidationException::withMessages(['amount' => 'The active installments exceed this commitment. Revise them first.']);
            }
            $row ??= new ProjectFunding;
            ReportingRules::history($row, $actor, $reason);
            $row->forceFill(['project_id' => $project->id, 'name' => trim($name), 'provider' => trim($provider) ?: null, 'committed_minor' => $minor,
                'expected_on' => $expectedOn, 'notes' => trim($notes) ?: null, 'status' => $status, 'revision' => $revision + 1,
                'created_by' => $row->created_by ?? (int) $actor->userId, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_FUNDING_SAVED, 'projects', $project->id, 'Funding commitment saved');

            return Snapshot::of($row, FundingView::class);
        });
    }
}
