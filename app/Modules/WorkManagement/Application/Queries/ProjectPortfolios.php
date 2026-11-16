<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\ProjectPortfolio;
use App\Models\ProjectPortfolioMember;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\WorkManagement\Application\PeopleList;
use App\Modules\WorkManagement\Contracts\Views\PortfolioView;
use App\Modules\WorkManagement\Contracts\Views\WorkMemberView;
use App\Modules\WorkManagement\Infrastructure\ClientScope;
use App\Modules\WorkManagement\Infrastructure\PortfolioScope;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ProjectPortfolios
{
    /** @return Collection<int, PortfolioView> */
    public function options(Access $actor, bool $activeOnly = false): Collection
    {
        return PortfolioScope::portfolios($actor)->when($activeOnly, static fn ($query) => $query->whereNull('archived_at'))
            ->withCount('members')->orderBy('name')->orderBy('id')->get()
            ->map(static fn (ProjectPortfolio $row): PortfolioView => self::view($actor, $row));
    }

    /** @return LengthAwarePaginator<int, PortfolioView> */
    public function page(Access $actor, string $search, bool $archived, int $page, int $perPage, string $sort = 'name', string $direction = 'asc', string $ownership = 'ALL', string $contents = 'ALL'): LengthAwarePaginator
    {
        $query = PortfolioScope::portfolios($actor)->withCount('members');
        $query->selectSub(ClientScope::clients($actor)->whereColumn('portfolio_id', 'project_portfolios.id')->selectRaw('COUNT(*)'), 'visible_clients_count');
        $query->selectSub(WorkScope::projects($actor)->whereIn('client_id', ClientScope::clients($actor)->whereColumn('portfolio_id', 'project_portfolios.id')->select('project_clients.id'))->selectRaw('COUNT(*)'), 'visible_projects_count');
        if (in_array($ownership, ['OWNED', 'SHARED'], true)) {
            $query->where('owner_id', $ownership === 'OWNED' ? '=' : '!=', (int) $actor->userId);
        }
        if (in_array($contents, ['WITH_WORK', 'EMPTY'], true)) {
            $query->whereExists(ClientScope::clients($actor)->whereColumn('portfolio_id', 'project_portfolios.id')->selectRaw('1'), 'and', $contents === 'EMPTY');
        }
        $archived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        SqlText::contains($query, ['name', 'description'], $search);
        $column = ['name' => 'name', 'created' => 'created_at', 'members' => 'members_count', 'clients' => 'visible_clients_count', 'projects' => 'visible_projects_count'][$sort] ?? 'name';
        if ($column === 'name') {
            SqlText::order($query, $column, $direction);
        } else {
            $query->orderBy($column, $direction === 'desc' ? 'desc' : 'asc');
        }
        $query->orderBy('id');
        $rows = $query->paginate(max(1, min(100, $perPage)), ['*'], 'page', max(1, $page));
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($rows->perPage(), ['*'], 'page', $rows->lastPage());
        }

        return $rows->through(static fn (ProjectPortfolio $row): PortfolioView => self::view($actor, $row));
    }

    public function portfolio(Access $actor, string $id): ?PortfolioView
    {
        $row = PortfolioScope::portfolios($actor)->whereKey($id)->withCount('members')->first();

        return $row === null ? null : self::view($actor, $row);
    }

    /** @param list<string> $portfolioIds
     * @return array<string, array{clients: int, projects: int}>
     */
    public function counts(Access $actor, array $portfolioIds): array
    {
        $clients = ClientScope::clients($actor)->whereIn('portfolio_id', $portfolioIds)
            ->whereIn('portfolio_id', PortfolioScope::portfolios($actor)->select('id'));
        $clientCounts = (clone $clients)->selectRaw('portfolio_id, COUNT(*) AS n')->groupBy('portfolio_id')->pluck('n', 'portfolio_id');
        $projectCounts = WorkScope::projects($actor)->join('project_clients', 'project_clients.id', '=', 'projects.client_id')
            ->whereIn('projects.client_id', $clients->select('project_clients.id'))
            ->selectRaw('project_clients.portfolio_id, COUNT(*) AS n')->groupBy('project_clients.portfolio_id')->pluck('n', 'portfolio_id');
        $counts = [];
        foreach ($portfolioIds as $id) {
            $counts[$id] = ['clients' => (int) ($clientCounts[$id] ?? 0), 'projects' => (int) ($projectCounts[$id] ?? 0)];
        }

        return $counts;
    }

    /** @return LengthAwarePaginator<int, AccountLabel> */
    public function members(Access $actor, string $portfolioId, int $page = 1, int $perPage = 10): LengthAwarePaginator
    {
        abort_unless(PortfolioScope::portfolios($actor)->whereKey($portfolioId)->exists(), 404);
        $query = ProjectPortfolioMember::query()->where('portfolio_id', $portfolioId)->orderBy('id');
        $rows = $query->paginate(max(1, min(100, $perPage)), ['*'], 'peoplePage', max(1, $page));
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($rows->perPage(), ['*'], 'peoplePage', $rows->lastPage());
        }
        $ids = array_values($rows->getCollection()->pluck('user_id')->all());
        $accounts = app(AccountNames::class)->list($actor, $ids)->keyBy('id');

        return $rows->through(static fn (ProjectPortfolioMember $member): AccountLabel => $accounts->get($member->user_id)
            ?? new AccountLabel($member->user_id, 'Account #'.$member->user_id, null, false));
    }

    /** @return Collection<int, AccountLabel> */
    public function candidates(Access $actor, string $portfolioId): Collection
    {
        $portfolio = PortfolioScope::portfolios($actor)->whereKey($portfolioId)->first();
        abort_unless($portfolio !== null && PortfolioScope::owns($actor, $portfolio), 403);
        $existing = $portfolio->members()->pluck('user_id')->all();

        return app(ModuleAccounts::class)->holders($actor, ModuleCode::PROJECTS)
            ->reject(static fn (AccountLabel $person): bool => $person->id === $portfolio->owner_id || in_array($person->id, $existing, true))->values();
    }

    /** @return LengthAwarePaginator<int, WorkMemberView> */
    public function people(Access $actor, string $portfolioId, int $page = 1, int $perPage = 20, string $search = '', string $access = 'ALL', string $sort = 'name', string $direction = 'asc'): LengthAwarePaginator
    {
        $portfolio = $this->portfolio($actor, $portfolioId);
        abort_if($portfolio === null, 404);
        $ids = ProjectPortfolioMember::query()->where('portfolio_id', $portfolioId)->pluck('user_id')->map(intval(...))->all();
        $labels = app(AccountNames::class)->list($actor, array_values(array_unique([$portfolio->owner_id, ...$ids])));
        $people = $labels->map(static fn (AccountLabel $person): WorkMemberView => new WorkMemberView(['id' => $person->id, 'user_id' => $person->id,
            'employee_id' => $person->employee_id, 'name' => $person->name, 'active' => $person->is_active, 'access' => $person->id === $portfolio->owner_id ? 'OWNER' : 'CONTRIBUTOR',
            'access_label' => $person->id === $portfolio->owner_id ? 'Owner' : 'Contributor', 'position' => null, 'explicit' => $person->id !== $portfolio->owner_id, 'open_tasks' => 0]));

        return PeopleList::page($people, $search, $access, $sort, $direction, $page, $perPage);
    }

    private static function view(Access $actor, ProjectPortfolio $row): PortfolioView
    {
        return Snapshot::of($row, PortfolioView::class, [
            'can_manage' => PortfolioScope::owns($actor, $row), 'people_count' => (int) $row->getAttribute('members_count') + 1,
        ]);
    }
}
