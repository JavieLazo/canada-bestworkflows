<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectFinance;
use App\Models\ProjectFunding as Funding;
use App\Models\ProjectFundingInstallment;
use App\Models\ProjectReportingPeriod;
use App\Modules\WorkManagement\Application\ReportSnapshotData;
use App\Modules\WorkManagement\Application\TableOrder;
use App\Modules\WorkManagement\Contracts\Views\CashAllocationView;
use App\Modules\WorkManagement\Contracts\Views\ContributionDeliveryView;
use App\Modules\WorkManagement\Contracts\Views\ContributionView;
use App\Modules\WorkManagement\Contracts\Views\FundingInstallmentView;
use App\Modules\WorkManagement\Contracts\Views\FundingView;
use App\Modules\WorkManagement\Infrastructure\FinanceScope;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ProjectFunding
{
    /** @return list<array{value: string, label: string}> */
    public function choices(Access $actor, string $projectId): array
    {
        abort_unless(FinanceScope::projects($actor)->whereKey($projectId)->exists(), 403);
        $rows = [];
        foreach (Funding::query()->where('project_id', $projectId)->orderBy('name')->limit(200)->get() as $row) {
            $rows[] = ['value' => 'FUNDING:'.$row->id, 'label' => $row->name.' · Funding'];
        }
        foreach (ProjectContribution::query()->where('project_id', $projectId)->where('kind', 'CASH')->orderBy('name')->limit(200)->get() as $row) {
            $rows[] = ['value' => 'CONTRIBUTION:'.$row->id, 'label' => $row->name.' · Cash contribution'];
        }

        return $rows;
    }

    /** @return Collection<int, FundingInstallmentView> */
    public function installmentChoices(Access $actor, string $projectId): Collection
    {
        return ProjectFundingInstallment::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))->orderBy('name')->limit(500)->get()
            ->map(static fn (ProjectFundingInstallment $row): FundingInstallmentView => Snapshot::of($row, FundingInstallmentView::class));
    }

    /** @return array{planned_minor: int|null, received_minor: int, paid_minor: int, variance_minor: int|null, unallocated_costs: int, actual_through: string} */
    public function periodVariance(Access $actor, string $projectId, string $periodId, ?string $budgetId): array
    {
        abort_unless(FinanceScope::projects($actor)->whereKey($projectId)->exists(), 403);
        $period = ProjectReportingPeriod::query()->where('project_id', $projectId)->whereKey($periodId)->firstOrFail();
        $asOf = min($period->ends_on, LocalClock::todayKey());
        $budget = $budgetId === null ? null : ProjectBudget::query()->where('project_id', $projectId)->whereKey($budgetId)->whereIn('status', ['APPROVED', 'DRAFT'])->first();
        $lines = ProjectBudgetLine::query()->where('budget_id', $budget->id ?? '__none__');
        $periodLines = (clone $lines)->where('period_id', $period->id);
        $planned = $periodLines->exists() ? (int) $periodLines->sum('total_minor') : null;
        $paid = (int) ProjectCashEntry::query()->where('project_id', $projectId)->whereNull('voided_at')->where('kind', 'EXPENSE')->whereDate('occurred_on', '>=', $period->starts_on)->whereDate('occurred_on', '<=', $asOf)->sum('amount_minor');
        $received = (int) ProjectCashEntry::query()->where('project_id', $projectId)->whereNull('voided_at')->where('kind', 'RECEIPT')->whereDate('occurred_on', '>=', $period->starts_on)->whereDate('occurred_on', '<=', $asOf)->sum('amount_minor');

        return ['planned_minor' => $planned, 'received_minor' => $received, 'paid_minor' => $paid, 'variance_minor' => $planned === null ? null : $paid - $planned, 'unallocated_costs' => (clone $lines)->whereNull('period_id')->count(), 'actual_through' => $asOf];
    }

    /** @return LengthAwarePaginator<int, FundingView> */
    public function fundings(Access $actor, string $projectId, int $page = 1, ?string $from = null, ?string $to = null, string $search = '', string $status = 'ALL', string $sort = 'name', string $direction = 'asc', int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->fundingQuery($actor, $projectId, $from, $to);

        SqlText::contains($query, ['name', 'provider', 'notes'], $search);
        if (in_array($status, ['ACTIVE', 'DISCONTINUED'], true)) {
            $query->where('status', $status);
        }
        TableOrder::apply($query, ['name' => 'name', 'committed' => 'committed_minor', 'received' => 'received_minor', 'date' => 'expected_on'], $sort, $direction, ['name']);

        return QueryPage::get($query->orderBy('id'), $page, max(1, min(100, $perPage)), FundingView::class, 'fundingPage');
    }

    public function funding(Access $actor, string $projectId, string $id): ?FundingView
    {
        $row = Funding::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, FundingView::class);
    }

    /** @return LengthAwarePaginator<int, FundingInstallmentView> */
    public function installments(Access $actor, string $projectId, string $fundingId, int $page = 1, string $search = '', string $direction = 'asc'): LengthAwarePaginator
    {
        $query = ProjectFundingInstallment::query()->where('project_id', $projectId)->where('funding_id', $fundingId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))
            ->select('project_funding_installments.*')->selectSub($this->linkedReceipts($actor, $projectId, null, null)->where('source_kind', 'FUNDING')
            ->whereColumn('installment_id', 'project_funding_installments.id')->selectRaw('SUM(amount_minor)'), 'received_minor');

        SqlText::contains($query, ['name', 'conditions'], $search);

        return QueryPage::get($query->orderBy('planned_on', $direction === 'desc' ? 'desc' : 'asc')->orderBy('id'), $page, 20, FundingInstallmentView::class, 'installmentsPage');
    }

    public function installment(Access $actor, string $projectId, string $id): ?FundingInstallmentView
    {
        $row = ProjectFundingInstallment::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, FundingInstallmentView::class);
    }

    /** @return LengthAwarePaginator<int, ContributionView> */
    public function contributions(Access $actor, string $projectId, int $page = 1, ?string $from = null, ?string $to = null, string $search = '', string $status = 'ALL', string $sort = 'name', string $direction = 'asc', int $perPage = 20): LengthAwarePaginator
    {
        $base = $this->contributionQuery($actor, $projectId, $from, $to);

        SqlText::contains($base, ['name', 'contributor', 'notes'], $search);
        if (in_array($status, ['ACTIVE', 'DISCONTINUED'], true)) {
            $base->where('status', $status);
        }
        TableOrder::apply($base, ['name' => 'name', 'committed' => 'committed_minor', 'received' => 'received_minor', 'date' => 'expected_on'], $sort, $direction, ['name']);

        return QueryPage::get($base->orderBy('id'), $page, max(1, min(100, $perPage)), ContributionView::class, 'contributionsPage');
    }

    public function contribution(Access $actor, string $projectId, string $id): ?ContributionView
    {
        $row = ProjectContribution::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, ContributionView::class);
    }

    /** @return LengthAwarePaginator<int, ContributionDeliveryView> */
    public function deliveries(Access $actor, string $projectId, string $contributionId, int $page = 1, bool $voided = false, string $search = '', string $direction = 'desc'): LengthAwarePaginator
    {
        $query = ProjectContributionDelivery::query()->where('project_id', $projectId)->where('contribution_id', $contributionId)
            ->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))->when(! $voided, static fn (Builder $query) => $query->whereNull('voided_at'));

        SqlText::contains($query, ['reference', 'notes', 'valuation_basis'], $search);

        return QueryPage::get($query->orderBy('occurred_on', $direction === 'asc' ? 'asc' : 'desc')->orderByDesc('id'), $page, 20, ContributionDeliveryView::class, 'deliveriesPage');
    }

    public function delivery(Access $actor, string $projectId, string $id): ?ContributionDeliveryView
    {
        $row = ProjectContributionDelivery::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, ContributionDeliveryView::class);
    }

    /** @return Collection<int, CashAllocationView> */
    public function allocations(Access $actor, string $projectId, string $entryId, ?int $revision = null): Collection
    {
        $entry = ProjectCashEntry::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))->whereKey($entryId)->first();
        abort_if($entry === null, 403);

        return ProjectCashAllocation::query()->where('entry_id', $entry->id)->where('revision', $revision ?? $entry->allocation_revision)->orderBy('id')->get()
            ->map(static fn (ProjectCashAllocation $row): CashAllocationView => Snapshot::of($row, CashAllocationView::class));
    }

    public function cashPosition(Access $actor, string $projectId, ?string $asOf = null): ?int
    {
        abort_unless(FinanceScope::projects($actor)->whereKey($projectId)->exists(), 403);
        $settings = ProjectFinance::query()->where('project_id', $projectId)->first();
        $asOf ??= LocalClock::todayKey();
        if ($settings === null || $settings->opening_minor === null || $settings->opening_on === null || $settings->opening_on > $asOf) {
            return null;
        }
        $entries = ProjectCashEntry::query()->where('project_id', $projectId)->whereNull('voided_at')->whereDate('occurred_on', '>=', $settings->opening_on)->whereDate('occurred_on', '<=', $asOf);

        return $settings->opening_minor + (int) (clone $entries)->where('kind', 'RECEIPT')->sum('amount_minor') - (int) (clone $entries)->where('kind', 'EXPENSE')->sum('amount_minor');
    }

    /** @return Builder<Funding> */
    private function fundingQuery(Access $actor, string $projectId, ?string $from, ?string $to): Builder
    {
        $query = Funding::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))
            ->select('project_fundings.*')->selectSub($this->linkedReceipts($actor, $projectId, $from, $to)->where('source_kind', 'FUNDING')->whereColumn('source_id', 'project_fundings.id')->selectRaw('SUM(amount_minor)'), 'received_minor');

        return $query;
    }

    /** @return Builder<ProjectContribution> */
    private function contributionQuery(Access $actor, string $projectId, ?string $from, ?string $to): Builder
    {
        $cash = $this->linkedReceipts($actor, $projectId, $from, $to)->where('source_kind', 'CONTRIBUTION')->whereColumn('source_id', 'project_contributions.id');
        $deliveries = ProjectContributionDelivery::query()->whereColumn('contribution_id', 'project_contributions.id')->whereNull('voided_at')
            ->where('occurred_on', '<=', $to ?? LocalClock::todayKey())->when($from !== null, static fn (Builder $query) => $query->where('occurred_on', '>=', $from));
        $query = ProjectContribution::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))->select('project_contributions.*')
            ->selectSub((clone $cash)->selectRaw('SUM(amount_minor)'), 'cash_received_minor')
            ->selectSub((clone $deliveries)->selectRaw('SUM(value_minor)'), 'inkind_received_minor')
            ->selectSub((clone $deliveries)->selectRaw('SUM(quantity_scaled)'), 'received_quantity');
        $base = ProjectContribution::query()->fromSub($query, 'project_contributions')->select('project_contributions.*')
            ->selectRaw("CASE WHEN kind = 'CASH' THEN cash_received_minor ELSE inkind_received_minor END AS received_minor");

        return $base;
    }

    /** @return list<array<string, mixed>> */
    public function reportRows(Access $actor, string $projectId, ?string $from, string $to, bool $contributions): array
    {
        $query = $contributions ? $this->contributionQuery($actor, $projectId, $from, $to) : $this->fundingQuery($actor, $projectId, $from, $to);
        $rows = [];
        foreach ($query->orderBy('id')->limit(ReportSnapshotData::MAX_ROWS + 1)->get() as $row) {
            $rows[] = $row->attributesToArray();
        }

        return $rows;
    }

    /** @return Builder<ProjectCashAllocation> */
    private function linkedReceipts(Access $actor, string $projectId, ?string $from, ?string $to): Builder
    {
        return ProjectCashAllocation::query()->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))
            ->whereExists(static function ($query) use ($from, $to): void {
                $query->selectRaw('1')->from('project_cash_entries')->whereColumn('id', 'project_cash_allocations.entry_id')->whereColumn('allocation_revision', 'project_cash_allocations.revision')
                    ->whereNull('voided_at')->where('kind', 'RECEIPT')->whereDate('occurred_on', '<=', $to ?? LocalClock::todayKey())
                    ->when($from !== null, static fn ($query) => $query->whereDate('occurred_on', '>=', $from));
            });
    }
}
