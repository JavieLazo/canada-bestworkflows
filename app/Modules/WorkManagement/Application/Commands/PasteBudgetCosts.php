<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\BudgetPaste;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

final class PasteBudgetCosts
{
    public function handle(Access $actor, string $projectId, string $budgetId, int $revision, string $text, string $categoryId, ?string $groupId = null): int
    {
        return DB::transaction(static function () use ($actor, $projectId, $budgetId, $revision, $text, $categoryId, $groupId): int {
            FinanceRules::locked($actor, $projectId, edit: true);
            FinanceRules::draft($projectId, $budgetId, $revision);
            $save = app(SaveBudgetLine::class);
            foreach (BudgetPaste::rows($text) as $row) {
                $revision = $save->handle($actor, $projectId, $budgetId, $revision, null,
                    new BudgetLineDraft($row['label'], $categoryId, FinanceAmount::input($row['amount']), groupId: $groupId));
            }

            return $revision;
        });
    }
}
