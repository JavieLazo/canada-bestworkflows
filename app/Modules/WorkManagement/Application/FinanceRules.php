<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContribution;
use App\Models\ProjectCostCategory;
use App\Models\ProjectFinance;
use App\Models\ProjectFinanceMember;
use App\Models\ProjectFunding;
use App\Models\ProjectRecordValue;
use App\Models\ProjectTaskGroup;
use App\Modules\WorkManagement\Infrastructure\ProjectMembership;
use App\Support\Access;
use Illuminate\Validation\ValidationException;

final class FinanceRules
{
    public static function hasFinancialEntries(string $projectId, bool $lock = false): bool
    {
        $costs = ProjectBudgetLine::withTrashed()->where('project_id', $projectId)->select('id');
        $cash = ProjectCashEntry::query()->where('project_id', $projectId)->select('id');
        foreach ([ProjectFunding::query()->where('project_id', $projectId), ProjectContribution::query()->where('project_id', $projectId),
            ProjectFinance::query()->where('project_id', $projectId)->where('opening_revision', '>', 0),
            ProjectRecordValue::query()->join('project_report_fields', 'project_report_fields.id', '=', 'project_record_values.field_id')->where('project_record_values.project_id', $projectId)->where('project_report_fields.type', 'MONEY')->select('project_record_values.id')] as $query) {
            if ($lock ? $query->lockForUpdate()->first() !== null : $query->exists()) {
                return true;
            }
        }

        return $lock ? $costs->lockForUpdate()->first() !== null || $cash->lockForUpdate()->first() !== null : $costs->exists() || $cash->exists();
    }

    public static function locked(Access $actor, string $projectId, bool $edit = false, bool $admin = false): Project
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS), 403);
        $project = WorkRules::lockedProject($actor, $projectId);
        abort_if($project === null, 403);
        if (WorkRules::managesProject($actor, $project)) {
            return $project;
        }
        abort_if($admin, 403, 'Only project administrators can manage finance access or approve budgets.');
        $grant = ProjectFinanceMember::query()->where('project_id', $projectId)->where('user_id', $actor->userId)->lockForUpdate()->first();
        $team = ProjectMembership::forActor($actor)->where('project_id', $projectId)->lockForUpdate()->first();
        abort_unless($grant !== null && in_array($grant->role, $edit ? ['EDITOR'] : ['VIEWER', 'EDITOR'], true)
            && ($team !== null || (string) $project->manager_id === $actor->userId), 403, 'Financial access is required for this project.');

        return $project;
    }

    public static function settings(string $projectId): ProjectFinance
    {
        $settings = ProjectFinance::query()->where('project_id', $projectId)->lockForUpdate()->first();
        if ($settings === null) {
            throw ValidationException::withMessages(['finance' => 'Choose a currency before recording project finance.']);
        }

        return $settings;
    }

    public static function draft(string $projectId, string $budgetId, int $revision): ProjectBudget
    {
        $budget = ProjectBudget::query()->where('project_id', $projectId)->whereKey($budgetId)->lockForUpdate()->first();
        abort_if($budget === null, 404);
        if ($budget->status !== 'DRAFT') {
            throw ValidationException::withMessages(['budget' => 'Approved budgets are kept as history. Start a revision to change the plan.']);
        }
        if ($budget->revision !== $revision) {
            throw ValidationException::withMessages(['budget' => 'This draft changed. Reopen it before saving or approving.']);
        }

        return $budget;
    }

    public static function category(string $projectId, string $id): ProjectCostCategory
    {
        $category = ProjectCostCategory::query()->where('project_id', $projectId)->whereKey($id)->first();
        if ($category === null) {
            throw ValidationException::withMessages(['categoryId' => 'Choose a category in this project.']);
        }

        return $category;
    }

    public static function group(string $projectId, ?string $id): ?ProjectTaskGroup
    {
        if ($id === null) {
            return null;
        }
        $group = ProjectTaskGroup::query()->where('project_id', $projectId)->whereKey($id)->first();
        if ($group === null) {
            throw ValidationException::withMessages(['groupId' => 'Choose an activity in this project.']);
        }

        return $group;
    }
}
