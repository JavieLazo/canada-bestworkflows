<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Models\ProjectClient;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\WorkManagement\Application\PeopleList;
use App\Modules\WorkManagement\Contracts\Views\ClientView;
use App\Modules\WorkManagement\Contracts\Views\WorkMemberView;
use App\Modules\WorkManagement\Infrastructure\ClientScope;
use App\Modules\WorkManagement\Infrastructure\PortfolioScope;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ProjectClients
{
    /** @param list<string> $clientIds
     * @return array<string, int>
     */
    public function projectCounts(Access $actor, array $clientIds): array
    {
        $rows = WorkScope::projects($actor)->whereIn('client_id', $clientIds)
            ->selectRaw('client_id, COUNT(*) AS total')->groupBy('client_id')->pluck('total', 'client_id');
        $counts = [];
        foreach ($rows as $clientId => $count) {
            $counts[(string) $clientId] = (int) $count;
        }

        return $counts;
    }

    /** @return Collection<int, ClientView> */
    public function options(Access $actor, bool $manageOnly = false, string $portfolio = 'ALL'): Collection
    {
        $query = $manageOnly ? ClientScope::manageable($actor) : ClientScope::clients($actor);

        return $this->contextual(PortfolioScope::clients($query, $actor, $portfolio), $actor)->withCount('members')->orderBy('name')->get()->map(static fn (ProjectClient $client): ClientView => Snapshot::of($client, ClientView::class, [
            'can_manage' => ClientScope::owns($actor, $client), 'people_count' => (int) $client->getAttribute('members_count') + 1,
        ]));
    }

    /** @return LengthAwarePaginator<int, ClientView> */
    public function page(Access $actor, string $search, int $page, int $perPage, string $portfolio = 'ALL', string $sort = 'name', string $direction = 'asc', string $ownership = 'ALL', string $contents = 'ALL'): LengthAwarePaginator
    {
        $query = $this->contextual(PortfolioScope::clients(ClientScope::clients($actor), $actor, $portfolio), $actor)->withCount('members');
        $visible = WorkScope::projects($actor)->whereColumn('projects.client_id', 'project_clients.id');
        $query->selectSub((clone $visible)->selectRaw('COUNT(*)'), 'visible_projects_count');
        if (in_array($ownership, ['OWNED', 'SHARED'], true)) {
            $query->where('owner_id', $ownership === 'OWNED' ? '=' : '!=', (int) $actor->userId);
        }
        if (in_array($contents, ['WITH_WORK', 'EMPTY'], true)) {
            $query->whereExists((clone $visible)->selectRaw('1'), 'and', $contents === 'EMPTY');
        }
        SqlText::contains($query, ['project_clients.name', 'project_clients.description'], $search);
        $column = ['name' => 'name', 'created' => 'created_at', 'members' => 'members_count', 'projects' => 'visible_projects_count'][$sort] ?? 'name';
        if ($column === 'name') {
            SqlText::order($query, 'project_clients.name', $direction);
        } else {
            $query->orderBy($column, $direction === 'desc' ? 'desc' : 'asc');
        }
        $query->orderBy('project_clients.id');
        $rows = $query->paginate(max(1, min(100, $perPage)), ['*'], 'page', max(1, $page));
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($rows->perPage(), ['*'], 'page', $rows->lastPage());
        }

        return $rows->through(static fn (ProjectClient $client): ClientView => Snapshot::of($client, ClientView::class, [
            'can_manage' => ClientScope::owns($actor, $client),
            'people_count' => (int) $client->getAttribute('members_count') + 1,
        ]));
    }

    public function client(Access $actor, string $id): ?ClientView
    {
        $client = $this->contextual(ClientScope::clients($actor), $actor)->whereKey($id)->withCount('members')->first();

        return $client === null ? null : Snapshot::of($client, ClientView::class, [
            'can_manage' => ClientScope::owns($actor, $client),
            'people_count' => (int) $client->getAttribute('members_count') + 1,
        ]);
    }

    /** @return Collection<int, AccountLabel> */
    public function members(Access $actor, string $clientId): Collection
    {
        $client = ClientScope::clients($actor)->whereKey($clientId)->first();
        abort_if($client === null, 404);
        $ids = $client->members()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();

        return app(AccountNames::class)->list($actor, array_values([$client->owner_id, ...$ids]));
    }

    /** @return Collection<int, AccountLabel> */
    public function candidates(Access $actor, string $clientId): Collection
    {
        $client = ClientScope::clients($actor)->whereKey($clientId)->first();
        abort_unless($client !== null && ClientScope::owns($actor, $client), 403);
        $existing = $client->members()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();

        return app(ProjectAccounts::class)->participants($actor)
            ->reject(static fn (AccountLabel $account): bool => $account->id === $client->owner_id || in_array($account->id, $existing, true))->values();
    }

    /** @return LengthAwarePaginator<int, WorkMemberView> */
    public function people(Access $actor, string $clientId, int $page = 1, int $perPage = 20, string $search = '', string $access = 'ALL', string $sort = 'name', string $direction = 'asc'): LengthAwarePaginator
    {
        $client = $this->client($actor, $clientId);
        abort_if($client === null, 404);
        $people = $this->members($actor, $clientId)->map(static fn (AccountLabel $person): WorkMemberView => new WorkMemberView(['id' => $person->id, 'user_id' => $person->id,
            'employee_id' => $person->employee_id, 'name' => $person->name, 'active' => $person->is_active, 'access' => $person->id === $client->owner_id ? 'OWNER' : 'CLIENT',
            'access_label' => $person->id === $client->owner_id ? 'Owner' : 'Client access', 'position' => null, 'explicit' => $person->id !== $client->owner_id, 'open_tasks' => 0]));

        return PeopleList::page($people, $search, $access, $sort, $direction, $page, $perPage);
    }

    /** @param Builder<ProjectClient> $query
     * @return Builder<ProjectClient>
     */
    private function contextual(Builder $query, Access $actor): Builder
    {
        return $query->select('project_clients.*')->selectSub(
            PortfolioScope::portfolios($actor)->whereColumn('project_portfolios.id', 'project_clients.portfolio_id')->select('name'),
            'portfolio_name',
        );
    }
}
