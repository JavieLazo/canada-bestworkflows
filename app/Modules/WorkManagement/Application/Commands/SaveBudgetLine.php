<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectBudgetLine;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\BudgetReporting;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveBudgetLine
{
    public function handle(Access $actor, string $projectId, string $budgetId, int $revision, ?string $lineId, BudgetLineDraft $input): int
    {
        return DB::transaction(static function () use ($actor, $projectId, $budgetId, $revision, $lineId, $input): int {
            $project = FinanceRules::locked($actor, $projectId, edit: true);
            $projectId = $project->id;
            $budget = FinanceRules::draft($projectId, $budgetId, $revision);
            $budgetId = $budget->id;
            $label = trim($input->label);
            $notes = trim($input->notes);
            Validator::make(['label' => $label, 'notes' => $notes, 'basis' => $input->basis, 'frequency' => $input->frequency], [
                'label' => 'required|string|max:200', 'notes' => 'nullable|string|max:4000',
                'basis' => ['required', Rule::in(['FLAT', 'CALCULATED'])], 'frequency' => $input->basis === 'CALCULATED' ? 'integer|min:1|max:1000' : 'nullable',
            ])->validate();
            $category = FinanceRules::category($projectId, $input->categoryId);
            $group = FinanceRules::group($projectId, $input->groupId);
            $quantity = $input->basis === 'CALCULATED' ? FinanceAmount::quantity($input->quantity) : null;
            $unit = $input->basis === 'CALCULATED' ? FinanceAmount::minor($input->unitAmount, 'unitAmount') : null;
            $amount = $quantity !== null && $unit !== null ? FinanceAmount::calculated($quantity, $input->frequency, $unit) : FinanceAmount::minor($input->amount, 'amount');
            $line = $lineId === null ? new ProjectBudgetLine : ProjectBudgetLine::query()->where('budget_id', $budgetId)->whereKey($lineId)->first();
            abort_if($line === null, 404);
            $others = (int) ProjectBudgetLine::query()->where('budget_id', $budgetId)->when($lineId !== null, static fn ($query) => $query->where('id', '!=', $lineId))->sum('total_minor');
            if ($others > FinanceAmount::LIMIT - $amount) {
                throw ValidationException::withMessages(['amount' => 'The budget total is too large.']);
            }
            $line->forceFill(['project_id' => $projectId, 'budget_id' => $budgetId, 'category_id' => $category->id, 'category_label' => $category->name,
                'group_id' => $group?->id, 'group_label' => $group?->name, 'label' => $label, 'basis' => $input->basis,
                'quantity_scaled' => $quantity, 'frequency' => $quantity === null ? null : $input->frequency, 'unit_minor' => $unit,
                'total_minor' => $amount, 'notes' => $notes === '' ? null : $notes, ...BudgetReporting::values($projectId, $input->reporting, $line)])->save();
            $budget->revision++;
            $budget->save();
            AuditService::record($actor, AuditEvent::PROJECT_BUDGET_EDITED, 'projects', $projectId, 'Budget version '.$budget->version.' cost row saved');

            return $budget->revision;
        });
    }
}
