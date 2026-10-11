<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Models\PayrollPayRule;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Contracts\Views\PayRuleView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The Pay Basis & Rates page's reads: one scope's versions, newest first.
 * Readable by any signed-in account (the page sits behind the RECORDS
 * gate); writes go through the command, which checks all-data.
 */
final class PayRuleRecords
{
    /** @return Collection<int, PayRuleView> */
    public function versions(Access $actor, string $scope): Collection
    {
        abort_if($actor->userId === null, 403);
        if (! PayRulesStore::ready()) {
            return new Collection;
        }

        return PayrollPayRule::query()->where('scope', $scope)->orderByDesc('effective_from')->get()
            ->map(static fn (PayrollPayRule $row): PayRuleView => Snapshot::of($row, PayRuleView::class));
    }

    /** The scope's latest version — the one new figures build on. */
    public function latest(Access $actor, string $scope): ?PayRuleView
    {
        return $this->versions($actor, $scope)->first();
    }

    /**
     * The status ids that carry an override of their own.
     *
     * @return list<string>
     */
    public function overriddenScopes(Access $actor): array
    {
        abort_if($actor->userId === null, 403);
        if (! PayRulesStore::ready()) {
            return [];
        }

        return array_values(array_map('strval', PayrollPayRule::query()->where('scope', '!=', PayrollPayRule::COMPANY)->distinct()->pluck('scope')->all()));
    }
}
