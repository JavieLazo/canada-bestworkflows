<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashEntry;
use App\Models\ProjectCostCategory;
use App\Models\ProjectFinance;
use App\Models\ProjectFinanceMember;
use App\Models\ProjectMember;
use App\Models\ProjectTaskGroup;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\TableOrder;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\BudgetLineView;
use App\Modules\WorkManagement\Contracts\Views\BudgetView;
use App\Modules\WorkManagement\Contracts\Views\CashEntryView;
use App\Modules\WorkManagement\Contracts\Views\CostCategoryView;
use App\Modules\WorkManagement\Contracts\Views\FinanceMemberView;
use App\Modules\WorkManagement\Contracts\Views\FinanceSettingsView;
use App\Modules\WorkManagement\Contracts\Views\FinancialTotals;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Contracts\Views\TaskGroupView;
use App\Modules\WorkManagement\Infrastructure\FinanceScope;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ProjectFinancials
{
    /** @return LengthAwarePaginator<int, ProjectView> */
    public function sourceProjects(Access $actor, string $currency, string $portfolio = 'ALL', string $client = 'ALL', int $page = 1): LengthAwarePaginator
    {
        $query = FinanceScope::projects($actor, portfolio: $portfolio, client: $client)
            ->whereIn('projects.id', ProjectFinance::query()->where('currency', $currency)->select('project_id'))
            ->select('projects.*')->selectRaw(WorkScope::PROJECT_CLIENT_NAME_SQL.' AS client_name')->orderBy('projects.name')->orderBy('projects.id');

        return QueryPage::get($query, $page, 10, ProjectView::class, 'financeSourcePage');
    }

    /** @return Collection<int, ProjectView> */
    public function projectLinks(Access $actor, string $portfolio = 'ALL', string $client = 'ALL'): Collection
    {
        return FinanceScope::projects($actor, portfolio: $portfolio, client: $client)
            ->whereIn('projects.id', ProjectFinance::query()->select('project_id'))->select('projects.*')
            ->selectRaw(WorkScope::PROJECT_CLIENT_NAME_SQL.' AS client_name')->orderBy('projects.name')->orderBy('projects.id')->limit(6)->get()
            ->map(static fn (Project $project): ProjectView => Snapshot::of($project, ProjectView::class));
    }

    public function canView(Access $actor, string $projectId): bool
    {
        return FinanceScope::projects($actor)->whereKey($projectId)->exists();
    }

    /** @return array{view: bool, edit: bool, admin: bool} */
    public function permissions(Access $actor, string $projectId): array
    {
        $view = FinanceScope::projects($actor)->whereKey($projectId)->exists();

        return ['view' => $view, 'edit' => $view && FinanceScope::projects($actor, edit: true)->whereKey($projectId)->exists(),
            'admin' => $view && WorkRules::managesProject($actor, WorkRules::project($actor, $projectId))];
    }

    public function settings(Access $actor, string $projectId): ?FinanceSettingsView
    {
        $row = $this->scoped(ProjectFinance::query(), $actor, $projectId)->first();

        return $row === null ? null : Snapshot::of($row, FinanceSettingsView::class, ['can_change_currency' => ! FinanceRules::hasFinancialEntries($projectId)]);
    }

    /** @return Collection<int, CostCategoryView> */
    public function categories(Access $actor, string $projectId): Collection
    {
        return $this->scoped(ProjectCostCategory::query(), $actor, $projectId)->orderBy('name')->orderBy('id')->get()
            ->map(static fn (ProjectCostCategory $row): CostCategoryView => Snapshot::of($row, CostCategoryView::class));
    }

    /** @return Collection<int, TaskGroupView> */
    public function planningGroups(Access $actor, string $projectId): Collection
    {
        return ProjectTaskGroup::query()->where('project_id', $projectId)
            ->whereIn('project_id', FinanceScope::projects($actor, edit: true)->select('projects.id'))->orderBy('name')->orderBy('id')->get()
            ->map(static fn (ProjectTaskGroup $group): TaskGroupView => Snapshot::of($group, TaskGroupView::class));
    }

    /** @return Collection<int, BudgetView> */
    public function versions(Access $actor, string $projectId): Collection
    {
        return $this->scoped(ProjectBudget::query(), $actor, $projectId)->orderByDesc('version')->get()
            ->map(static fn (ProjectBudget $row): BudgetView => Snapshot::of($row, BudgetView::class));
    }

    public function budget(Access $actor, string $projectId, string $id): ?BudgetView
    {
        $row = $this->scoped(ProjectBudget::query(), $actor, $projectId)->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, BudgetView::class);
    }

    public function line(Access $actor, string $projectId, string $id): ?BudgetLineView
    {
        $row = $this->scoped(ProjectBudgetLine::query(), $actor, $projectId)->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, BudgetLineView::class);
    }

    /** @return LengthAwarePaginator<int, BudgetLineView> */
    public function lines(Access $actor, string $projectId, string $budgetId, int $page = 1, int $perPage = 20, string $search = '', string $category = '', string $sort = 'created', string $direction = 'asc'): LengthAwarePaginator
    {
        $query = $this->scoped(ProjectBudgetLine::query(), $actor, $projectId)->where('budget_id', $budgetId)->when($category !== '', static fn (Builder $query) => $query->where('category_id', $category));
        SqlText::contains($query, ['label', 'notes', 'category_label', 'group_label'], $search);
        TableOrder::apply($query, ['created' => 'created_at', 'name' => 'label', 'basis' => 'basis', 'amount' => 'total_minor'], $sort, $direction, ['name', 'basis']);
        $query->orderBy('id');

        return QueryPage::get($query, $page, max(1, min(100, $perPage)), BudgetLineView::class, 'budgetPage');
    }

    public function draftTotal(Access $actor, string $projectId, string $budgetId): int
    {
        return (int) $this->scoped(ProjectBudgetLine::query(), $actor, $projectId)->where('budget_id', $budgetId)->sum('total_minor');
    }

    public function entry(Access $actor, string $projectId, string $id): ?CashEntryView
    {
        $row = $this->scoped($this->cashQuery(), $actor, $projectId)->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, CashEntryView::class);
    }

    /** @param array{from?: string, to?: string, category?: string} $filters
     * @return LengthAwarePaginator<int, CashEntryView>
     */
    public function entries(Access $actor, string $projectId, string $search, string $kind, bool $showVoided, int $page, int $perPage, array $filters = [], string $sort = 'date', string $direction = 'desc'): LengthAwarePaginator
    {
        $query = $this->scoped($this->cashQuery(), $actor, $projectId);
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->whereDate('occurred_on', $operator, $filters[$key]);
            }
        }
        if (isset($filters['category']) && $filters['category'] !== '') {
            $query->where('category_id', $filters['category']);
        }
        if (in_array($kind, ['RECEIPT', 'EXPENSE'], true)) {
            $query->where('kind', $kind);
        }
        if (! $showVoided) {
            $query->whereNull('voided_at');
        }
        SqlText::contains($query, ['description', 'party', 'reference'], $search);
        TableOrder::apply($query, ['date' => 'occurred_on', 'name' => 'description', 'reference' => 'reference', 'amount' => 'amount_minor'], $sort, $direction, ['name', 'reference']);
        $query->orderByDesc('id');

        return QueryPage::get($query, $page, max(1, min(100, $perPage)), CashEntryView::class, 'entriesPage');
    }

    /** @return Collection<int, FinancialTotals> */
    public function totals(Access $actor, string $asOf, ?string $projectId = null, string $portfolio = 'ALL', string $client = 'ALL'): Collection
    {
        $projects = FinanceScope::projects($actor, portfolio: $portfolio, client: $client)
            ->when($projectId !== null, static fn (Builder $query) => $query->where('projects.id', $projectId));
        [$budgets, $cash] = $this->moneySources($projects, $asOf);
        $rows = ProjectFinance::query()->whereIn('project_finances.project_id', $projects->select('projects.id'))
            ->leftJoinSub($budgets, 'budget_totals', static fn (JoinClause $join) => $join->on('budget_totals.project_id', '=', 'project_finances.project_id'))
            ->leftJoinSub($cash, 'cash_totals', static fn (JoinClause $join) => $join->on('cash_totals.project_id', '=', 'project_finances.project_id'))
            ->select('currency')->selectRaw('COUNT(*) AS enabled_n, COUNT(budget_totals.project_id) AS budgeted_n,
                COALESCE(SUM(budget_totals.total_minor), 0) AS approved_n, COALESCE(SUM(cash_totals.paid), 0) AS paid_n,
                COALESCE(SUM(CASE WHEN budget_totals.project_id IS NOT NULL THEN cash_totals.paid ELSE 0 END), 0) AS budgeted_paid_n,
                COALESCE(SUM(cash_totals.received), 0) AS received_n')->groupBy('currency')->orderBy('currency')->get();

        return $rows->map(static fn (ProjectFinance $row): FinancialTotals => new FinancialTotals($row->currency, (int) $row->getAttribute('enabled_n'),
            (int) $row->getAttribute('budgeted_n'), (int) $row->getAttribute('approved_n'), (int) $row->getAttribute('paid_n'),
            (int) $row->getAttribute('budgeted_paid_n'), (int) $row->getAttribute('received_n')));
    }

    /**
     * Each open project whose money the viewer can see, with its latest
     * approved budget (null until one is approved) and what's been paid so far.
     *
     * @return list<array{project: ProjectView, currency: string, approved: int|null, paid: int}>
     */
    public function openProjects(Access $actor, string $asOf, string $portfolio = 'ALL'): array
    {
        $projects = FinanceScope::projects($actor, portfolio: $portfolio)->whereNotIn('projects.status', ['COMPLETED', 'CANCELLED']);
        [$budgets, $cash] = $this->moneySources($projects, $asOf);

        return array_values($projects->leftJoin('project_finances', 'project_finances.project_id', '=', 'projects.id')
            ->leftJoinSub($budgets, 'budget_totals', static fn (JoinClause $join) => $join->on('budget_totals.project_id', '=', 'projects.id'))
            ->leftJoinSub($cash, 'cash_totals', static fn (JoinClause $join) => $join->on('cash_totals.project_id', '=', 'projects.id'))
            ->select('projects.*')->selectRaw(WorkScope::PROJECT_CLIENT_NAME_SQL.' AS client_name')
            ->selectRaw('project_finances.currency AS money_currency, budget_totals.total_minor AS approved_n, COALESCE(cash_totals.paid, 0) AS paid_n')
            ->orderBy('projects.name')->orderBy('projects.id')->get()
            ->map(static fn (Project $row): array => ['project' => Snapshot::of($row, ProjectView::class), 'currency' => (string) $row->getAttribute('money_currency'),
                'approved' => $row->getAttribute('approved_n') === null ? null : (int) $row->getAttribute('approved_n'), 'paid' => (int) $row->getAttribute('paid_n')])->all());
    }

    /**
     * The latest approved budget, and paid and received cash up to a date,
     * per project in the given scope.
     *
     * @param  Builder<Project>  $projects
     * @return array{Builder<ProjectBudget>, Builder<ProjectCashEntry>}
     */
    private function moneySources(Builder $projects, string $asOf): array
    {
        $latest = ProjectBudget::query()->where('status', 'APPROVED')->whereIn('project_id', (clone $projects)->select('projects.id'))
            ->select('project_id')->selectRaw('MAX(version) AS version')->groupBy('project_id');
        $budgets = ProjectBudget::query()->joinSub($latest, 'latest_budget', static function (JoinClause $join): void {
            $join->on('latest_budget.project_id', '=', 'project_budgets.project_id')->on('latest_budget.version', '=', 'project_budgets.version');
        })->select('project_budgets.project_id', 'total_minor');
        $cash = ProjectCashEntry::query()->whereNull('voided_at')->whereDate('occurred_on', '<=', $asOf)
            ->whereIn('project_id', (clone $projects)->select('projects.id'))->select('project_id')
            ->selectRaw("SUM(CASE WHEN kind = 'EXPENSE' THEN amount_minor ELSE 0 END) AS paid, SUM(CASE WHEN kind = 'RECEIPT' THEN amount_minor ELSE 0 END) AS received")->groupBy('project_id');

        return [$budgets, $cash];
    }

    /** @return list<array{id: string, name: string, budget: int|null, paid: int, budgetWidth: int, paidWidth: int}> */
    public function categoryTotals(Access $actor, string $projectId, string $asOf): array
    {
        $current = $this->scoped(ProjectBudget::query(), $actor, $projectId)->where('status', 'APPROVED')->orderByDesc('version')->first();
        $budget = $current === null ? collect() : $this->scoped(ProjectBudgetLine::query(), $actor, $projectId)->where('budget_id', $current->id)
            ->select('category_id')->selectRaw('SUM(total_minor) AS total')->groupBy('category_id')->pluck('total', 'category_id');
        $paid = $this->scoped(ProjectCashEntry::query(), $actor, $projectId)->where('kind', 'EXPENSE')->whereNull('voided_at')->whereDate('occurred_on', '<=', $asOf)
            ->select('category_id')->selectRaw('SUM(amount_minor) AS total')->groupBy('category_id')->pluck('total', 'category_id');
        $maximum = max(1, (int) ($budget->max() ?? 0), (int) ($paid->max() ?? 0));

        return array_values($this->categories($actor, $projectId)->map(static function (CostCategoryView $category) use ($budget, $paid, $maximum, $current): array {
            $planned = (int) ($budget[$category->id] ?? 0);
            $spent = (int) ($paid[$category->id] ?? 0);

            return ['id' => $category->id, 'name' => $category->name, 'budget' => $current === null ? null : $planned, 'paid' => $spent,
                'budgetWidth' => (int) round($planned / $maximum * 100), 'paidWidth' => (int) round($spent / $maximum * 100)];
        })->sortByDesc(static fn (array $row): int => max($row['budget'] ?? 0, $row['paid']))->all());
    }

    /** @return list<array{month: string, key: string, received: int, paid: int, receivedWidth: int, paidWidth: int}> */
    public function cashTrend(Access $actor, string $projectId, string $asOf): array
    {
        $from = CarbonImmutable::parse($asOf)->startOfMonth()->subMonths(5);
        $rows = $this->scoped(ProjectCashEntry::query(), $actor, $projectId)->whereNull('voided_at')
            ->whereDate('occurred_on', '>=', $from->format('Y-m-d'))->whereDate('occurred_on', '<=', $asOf)->selectRaw("SUBSTR(occurred_on, 1, 7) AS month_key,
                SUM(CASE WHEN kind = 'RECEIPT' THEN amount_minor ELSE 0 END) AS received_n,
                SUM(CASE WHEN kind = 'EXPENSE' THEN amount_minor ELSE 0 END) AS paid_n")->groupBy('month_key')->get()->keyBy('month_key');
        $maximum = max(1, (int) ($rows->max('received_n') ?? 0), (int) ($rows->max('paid_n') ?? 0));
        $trend = [];
        for ($i = 0; $i < 6; $i++) {
            $date = $from->addMonths($i);
            $row = $rows->get($date->format('Y-m'));
            $received = $row === null ? 0 : (int) $row->getAttribute('received_n');
            $paid = $row === null ? 0 : (int) $row->getAttribute('paid_n');
            $trend[] = ['month' => $date->format('M Y'), 'key' => $date->format('Y-m'), 'received' => $received, 'paid' => $paid,
                'receivedWidth' => (int) round($received / $maximum * 100), 'paidWidth' => (int) round($paid / $maximum * 100)];
        }

        return $trend;
    }

    /** @return LengthAwarePaginator<int, FinanceMemberView> */
    public function members(Access $actor, string $projectId, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        abort_unless($this->permissions($actor, $projectId)['admin'], 403);
        $rows = QueryPage::get(ProjectFinanceMember::query()->where('project_id', $projectId)->orderBy('id'), $page,
            max(1, min(100, $perPage)), FinanceMemberView::class, 'financePeoplePage');
        $accounts = app(AccountNames::class)->list($actor, array_values($rows->getCollection()->pluck('user_id')->all()))->keyBy('id');

        return $rows->through(static fn (FinanceMemberView $row): FinanceMemberView => new FinanceMemberView([...$row->toArray(),
            'name' => $accounts->get($row->user_id)->name ?? 'Account #'.$row->user_id]));
    }

    /** @return Collection<int, AccountLabel> */
    public function candidates(Access $actor, string $projectId): Collection
    {
        abort_unless($this->permissions($actor, $projectId)['admin'], 403);
        $project = WorkRules::project($actor, $projectId);
        abort_if($project === null, 404);
        $ids = ProjectMember::query()->where('project_id', $projectId)->pluck('user_id')->all();
        if ($project->manager_id !== null) {
            $ids[] = $project->manager_id;
        }

        $allowed = app(ModuleAccounts::class)->filter($actor, ModuleCode::PROJECTS, array_values($ids));

        return app(ProjectPeople::class)->accounts($actor, $projectId)->whereIn('id', $allowed)->values();
    }

    /** @return Builder<ProjectCashEntry> */
    private function cashQuery(): Builder
    {
        return ProjectCashEntry::query()->select('project_cash_entries.*')->selectSub(
            ProjectCashEntry::query()->from('project_cash_entries as prior')->whereColumn('prior.id', 'project_cash_entries.replaces_id')
                ->whereColumn('prior.project_id', 'project_cash_entries.project_id')->select('prior.void_reason'),
            'correction_reason',
        );
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function scoped(Builder $query, Access $actor, string $projectId): Builder
    {
        return $query->where('project_id', $projectId)->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'));
    }
}
