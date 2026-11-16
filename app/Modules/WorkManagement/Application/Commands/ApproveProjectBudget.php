<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCostCategory;
use App\Models\ProjectTaskGroup;
use App\Modules\WorkManagement\Application\BudgetReporting;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveProjectBudget
{
    public function handle(Access $actor, string $projectId, string $budgetId, int $revision): bool
    {
        return DB::transaction(static function () use ($actor, $projectId, $budgetId, $revision): bool {
            FinanceRules::locked($actor, $projectId, admin: true);
            $budget = FinanceRules::draft($projectId, $budgetId, $revision);
            $lines = ProjectBudgetLine::query()->where('budget_id', $budgetId);
            if (! (clone $lines)->exists()) {
                throw ValidationException::withMessages(['budget' => 'Add at least one cost row before approving this budget.']);
            }
            $categories = ProjectCostCategory::query()->where('project_id', $projectId)->pluck('name', 'id');
            $groups = ProjectTaskGroup::query()->where('project_id', $projectId)->pluck('name', 'id');
            foreach ((clone $lines)->lazyById(100) as $line) {
                $line->category_label = $categories[$line->category_id];
                $line->group_label = $line->group_id === null ? $line->group_label : $groups[$line->group_id];
                $sourceKind = $line->funding_id !== null ? 'FUNDING' : ($line->contribution_id !== null ? 'CONTRIBUTION' : 'NONE');
                $line->forceFill(BudgetReporting::values($projectId, ['periodId' => $line->period_id, 'sourceKind' => $sourceKind, 'sourceId' => $line->funding_id ?? $line->contribution_id], $line));
                $line->save();
            }
            $budget->total_minor = (int) $lines->sum('total_minor');
            $budget->status = 'APPROVED';
            $budget->approved_by = (int) $actor->userId;
            $budget->approved_at = now()->toImmutable();
            $budget->revision++;
            $budget->save();
            AuditService::record($actor, AuditEvent::PROJECT_BUDGET_APPROVED, 'projects', $projectId, 'Budget version '.$budget->version.' approved');

            return true;
        });
    }
}
