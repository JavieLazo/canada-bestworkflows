<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectContribution;
use App\Models\ProjectFunding;
use App\Models\ProjectFundingInstallment;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Support\LocalClock;
use Illuminate\Validation\ValidationException;

final class FundingRules
{
    public static function date(?string $date, string $field, bool $actual = false): void
    {
        if ($date !== null && (! TaskSchedule::isDateKey($date) || ($actual && $date > LocalClock::todayKey()))) {
            throw ValidationException::withMessages([$field => $actual ? 'Choose an actual date through today.' : 'Choose a valid date.']);
        }
    }

    /** @return array{source_kind: string, source_id: string|null, source_label: string|null, installment_id: string|null, installment_label: string|null} */
    public static function source(string $projectId, string $kind, ?string $id, ?string $installmentId = null): array
    {
        $funding = $kind === 'FUNDING' && $id !== null ? ProjectFunding::query()->where('project_id', $projectId)->whereKey($id)->first() : null;
        $contribution = $kind === 'CONTRIBUTION' && $id !== null ? ProjectContribution::query()->where('project_id', $projectId)->where('kind', 'CASH')->whereKey($id)->first() : null;
        $installment = $installmentId === null ? null : ProjectFundingInstallment::query()->where('project_id', $projectId)->whereKey($installmentId)->first();
        if (! in_array($kind, ['NONE', 'FUNDING', 'CONTRIBUTION'], true) || ($kind === 'NONE' && $id !== null)
            || ($kind === 'FUNDING' && $funding === null) || ($kind === 'CONTRIBUTION' && $contribution === null)
            || ($installmentId !== null && ($installment === null || $funding === null || $installment->funding_id !== $funding->id))) {
            throw ValidationException::withMessages(['allocations' => 'Choose a funding source or cash contribution in this project.']);
        }

        return ['source_kind' => $kind, 'source_id' => $funding->id ?? $contribution?->id, 'source_label' => $funding->name ?? $contribution?->name,
            'installment_id' => $installment?->id, 'installment_label' => $installment?->name];
    }
}
