<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectBudgetLine;
use App\Models\ProjectReportingPeriod;
use Illuminate\Validation\ValidationException;

final class BudgetReporting
{
    /** @param array{periodId?: string|null, sourceKind?: string, sourceId?: string|null}|null $input
     * @return array<string, string|null>
     */
    public static function values(string $projectId, ?array $input, ProjectBudgetLine $line): array
    {
        if ($input === null) {
            return [];
        }
        $periodId = $input['periodId'] ?? null;
        $period = $periodId === null ? null : ProjectReportingPeriod::query()->where('project_id', $projectId)->whereKey($periodId)->first();
        if ($periodId !== null && $period === null) {
            throw ValidationException::withMessages(['periodId' => 'Choose a reporting period in this project.']);
        }
        $source = FundingRules::source($projectId, $input['sourceKind'] ?? 'NONE', $input['sourceId'] ?? null);

        return ['period_id' => $period?->id, 'period_label' => $period?->name, 'period_starts_on' => $period?->starts_on, 'period_ends_on' => $period?->ends_on,
            'funding_id' => $source['source_kind'] === 'FUNDING' ? $source['source_id'] : null,
            'contribution_id' => $source['source_kind'] === 'CONTRIBUTION' ? $source['source_id'] : null, 'source_label' => $source['source_label']];
    }
}
