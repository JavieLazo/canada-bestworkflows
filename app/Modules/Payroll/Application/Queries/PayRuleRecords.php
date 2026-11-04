<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollPayRule;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Contracts\Views\PayRuleView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The Pay Basis & Rates page's reads: one scope's versions, newest first,
 * an employee's drafts included (`status`; only an approved version prices).
 * The company's and the statuses' are readable by any signed-in account
 * (the page sits behind the RECORDS or PAYROLL gate); one employee's are
 * pay data (SEC-01), read by an all-data PAYROLL holder only. Writes go
 * through the commands, which check all-data.
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
        $rows = PayrollPayRule::query()->where('scope', $scope)->orderByDesc('effective_from')->get();
        if ($rows->contains(static fn (PayrollPayRule $row): bool => $row->scope_type === PayrollPayRule::SCOPE_EMPLOYEE)) {
            abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        }

        return $rows->map(static fn (PayrollPayRule $row): PayRuleView => Snapshot::of($row, PayRuleView::class));
    }

    /** The scope's latest APPROVED version — the one new figures build on; a draft never is. */
    public function latest(Access $actor, string $scope): ?PayRuleView
    {
        return $this->versions($actor, $scope)->first(static fn (PayRuleView $row): bool => $row->status !== PayrollPayRule::DRAFT);
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

        return array_values(array_map('strval', PayrollPayRule::query()->where('scope', '!=', PayrollPayRule::COMPANY)
            ->when(PayRulesStore::employeeScopesReady(), static fn ($query) => $query->where('scope_type', PayrollPayRule::SCOPE_STATUS))
            ->distinct()->pluck('scope')->all()));
    }

    /**
     * Every employee's versions of their own, newest first.
     *
     * @return array<string, list<PayRuleView>> employee id => versions
     */
    public function employeeVersions(Access $actor): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! PayRulesStore::employeeScopesReady()) {
            return [];
        }
        $versions = [];
        foreach (PayrollPayRule::query()->where('scope_type', PayrollPayRule::SCOPE_EMPLOYEE)->orderByDesc('effective_from')->get() as $row) {
            $versions[$row->scope][] = Snapshot::of($row, PayRuleView::class);
        }

        return $versions;
    }
}
