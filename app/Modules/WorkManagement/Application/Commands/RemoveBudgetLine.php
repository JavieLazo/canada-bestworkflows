<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectBudgetLine;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;

final class RemoveBudgetLine
{
    public function handle(Access $actor, string $projectId, string $budgetId, int $revision, string $lineId): int
    {
        return DB::transaction(static function () use ($actor, $projectId, $budgetId, $revision, $lineId): int {
            FinanceRules::locked($actor, $projectId, edit: true);
            $budget = FinanceRules::draft($projectId, $budgetId, $revision);
            $line = ProjectBudgetLine::query()->where('budget_id', $budgetId)->whereKey($lineId)->first();
            abort_if($line === null, 404);
            $line->delete();
            $budget->revision++;
            $budget->save();
            AuditService::record($actor, AuditEvent::PROJECT_BUDGET_EDITED, 'projects', $projectId, 'Budget version '.$budget->version.' draft cost row removed');

            return $budget->revision;
        });
    }
}
