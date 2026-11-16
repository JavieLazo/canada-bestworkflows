<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectFunding;
use App\Modules\WorkManagement\Infrastructure\FinanceScope;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** @phpstan-type Series array{sources: int, known: int, committed: int, received: int, unplanned: int}
 * @phpstan-type Summary array{currency: string, series: array{FUNDING: Series, CASH: Series, IN_KIND: Series}, unlinked: int, inactive: int, inactive_inkind: int, unvalued: int}
 */
final class ProjectFundingSummary
{
    /** @return list<Summary> */
    public function totals(Access $actor, ?string $projectId = null, string $portfolio = 'ALL', string $client = 'ALL'): array
    {
        $scope = FinanceScope::projects($actor, portfolio: $portfolio, client: $client)->when($projectId !== null, static fn (Builder $query) => $query->whereKey($projectId))->select('projects.id');
        $funding = ProjectFunding::query()->join('project_finances', 'project_finances.project_id', '=', 'project_fundings.project_id')->whereIn('project_fundings.project_id', clone $scope)->where('status', 'ACTIVE')
            ->select('project_finances.currency', 'committed_minor')->selectRaw("'FUNDING' AS kind");
        $contributions = ProjectContribution::query()->join('project_finances', 'project_finances.project_id', '=', 'project_contributions.project_id')->whereIn('project_contributions.project_id', clone $scope)->where('status', 'ACTIVE')->select('project_finances.currency', 'committed_minor', 'kind');
        $promises = DB::query()->fromSub($funding->unionAll($contributions), 'sources')->select('currency', 'kind')->selectRaw('COUNT(*) AS sources, COUNT(committed_minor) AS known, SUM(committed_minor) AS committed')->groupBy('currency', 'kind')->get();
        $data = [];
        foreach ($promises as $row) {
            $item = $data[$row->currency] ?? $this->currency($row->currency);
            $kind = match ($row->kind) {
                'FUNDING' => 'FUNDING', 'CASH' => 'CASH', default => 'IN_KIND'
            };
            $item['series'][$kind] = ['sources' => (int) $row->sources, 'known' => (int) $row->known, 'committed' => (int) $row->committed, 'received' => 0, 'unplanned' => 0];
            $data[$row->currency] = $item;
        }
        $cash = ProjectCashAllocation::query()->join('project_cash_entries', 'project_cash_entries.id', '=', 'project_cash_allocations.entry_id')->join('project_finances', 'project_finances.project_id', '=', 'project_cash_entries.project_id')
            ->leftJoin('project_fundings', static fn ($join) => $join->on('project_fundings.id', '=', 'project_cash_allocations.source_id')->where('source_kind', 'FUNDING'))
            ->leftJoin('project_contributions', static fn ($join) => $join->on('project_contributions.id', '=', 'project_cash_allocations.source_id')->where('source_kind', 'CONTRIBUTION'))
            ->whereIn('project_cash_entries.project_id', clone $scope)->whereColumn('project_cash_entries.allocation_revision', 'project_cash_allocations.revision')->whereNull('project_cash_entries.voided_at')->where('project_cash_entries.kind', 'RECEIPT')->whereDate('project_cash_entries.occurred_on', '<=', ManilaClock::todayKey())->whereIn('source_kind', ['FUNDING', 'CONTRIBUTION'])
            ->select('project_finances.currency')->selectRaw("CASE WHEN source_kind = 'FUNDING' THEN 'FUNDING' ELSE 'CASH' END AS source_type,
                CASE WHEN COALESCE(project_fundings.status, project_contributions.status, 'MISSING') != 'ACTIVE' THEN 'INACTIVE' WHEN COALESCE(project_fundings.committed_minor, project_contributions.committed_minor) IS NULL THEN 'UNPLANNED' ELSE 'KNOWN' END AS coverage,
                SUM(project_cash_allocations.amount_minor) AS received")->groupBy('project_finances.currency', 'source_type', 'coverage')->toBase()->get();
        $linked = [];
        foreach ($cash as $row) {
            $item = $data[$row->currency] ?? $this->currency($row->currency);
            $linked[$row->currency] = ($linked[$row->currency] ?? 0) + (int) $row->received;
            if ($row->coverage === 'INACTIVE') {
                $item['inactive'] += (int) $row->received;
            } else {
                $kind = $row->source_type === 'FUNDING' ? 'FUNDING' : 'CASH';
                $item['series'][$kind][$row->coverage === 'KNOWN' ? 'received' : 'unplanned'] += (int) $row->received;
            }
            $data[$row->currency] = $item;
        }
        $deliveries = ProjectContributionDelivery::query()->join('project_contributions', 'project_contributions.id', '=', 'project_contribution_deliveries.contribution_id')->join('project_finances', 'project_finances.project_id', '=', 'project_contributions.project_id')
            ->whereIn('project_contributions.project_id', clone $scope)->whereNull('voided_at')->where('occurred_on', '<=', ManilaClock::todayKey())->select('project_finances.currency')
            ->selectRaw("SUM(CASE WHEN project_contributions.status = 'ACTIVE' AND project_contributions.committed_minor IS NOT NULL THEN value_minor ELSE 0 END) AS received,
                SUM(CASE WHEN project_contributions.status = 'ACTIVE' AND project_contributions.committed_minor IS NULL THEN value_minor ELSE 0 END) AS unplanned,
                SUM(CASE WHEN project_contributions.status != 'ACTIVE' THEN value_minor ELSE 0 END) AS inactive_value, SUM(CASE WHEN value_minor IS NULL THEN 1 ELSE 0 END) AS unvalued")->groupBy('project_finances.currency')->toBase()->get();
        foreach ($deliveries as $row) {
            $item = $data[$row->currency] ?? $this->currency($row->currency);
            $item['series']['IN_KIND']['received'] = (int) $row->received;
            $item['series']['IN_KIND']['unplanned'] = (int) $row->unplanned;
            $item['unvalued'] = (int) $row->unvalued;
            $item['inactive_inkind'] = (int) $row->inactive_value;
            $data[$row->currency] = $item;
        }
        $receipts = ProjectCashEntry::query()->join('project_finances', 'project_finances.project_id', '=', 'project_cash_entries.project_id')->whereIn('project_cash_entries.project_id', clone $scope)
            ->whereNull('voided_at')->where('kind', 'RECEIPT')->whereDate('occurred_on', '<=', ManilaClock::todayKey())->select('project_finances.currency')->selectRaw('SUM(amount_minor) AS received')->groupBy('project_finances.currency')->toBase()->get();
        foreach ($receipts as $row) {
            $item = $data[$row->currency] ?? $this->currency($row->currency);
            $item['unlinked'] = (int) $row->received - ($linked[$row->currency] ?? 0);
            $data[$row->currency] = $item;
        }
        ksort($data);

        return array_values($data);
    }

    /** @return Summary */
    private function currency(string $currency): array
    {
        $empty = ['sources' => 0, 'known' => 0, 'committed' => 0, 'received' => 0, 'unplanned' => 0];

        return ['currency' => $currency, 'series' => ['FUNDING' => $empty, 'CASH' => $empty, 'IN_KIND' => $empty], 'unlinked' => 0, 'inactive' => 0, 'inactive_inkind' => 0, 'unvalued' => 0];
    }
}
