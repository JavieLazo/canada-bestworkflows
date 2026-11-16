<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Projects\Support\WithPeopleList;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\WorkManagement\Application\Commands\OrganizeClient;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Queries\ProjectClients;
use App\Modules\WorkManagement\Application\Queries\ProjectPortfolios;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Contracts\Views\ClientView;
use App\Modules\WorkManagement\Contracts\Views\WorkMemberView;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Clients')]
class ClientsPage extends Component
{
    use WithPeopleList;
    use WithSorting;
    use WithTableFilters;

    #[Locked]
    public ?string $clientId = null;

    #[Locked]
    public bool $membersOnly = false;

    #[Locked]
    public bool $toolsOnly = false;

    public string $name = '';

    public string $description = '';

    #[Url(as: 'layout', except: 'cards')]
    public string $collectionView = 'cards';

    #[Url(except: 'ALL')]
    public string $ownership = 'ALL';

    #[Url(except: 'ALL')]
    public string $contents = 'ALL';

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['name', 'created', 'members', 'projects'];
    }

    public function updatedOwnership(): void
    {
        $this->resetPage();
    }

    public function updatedContents(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->ownership = $this->contents = 'ALL';
        $this->resetPage();
    }

    #[Url(except: 'ALL')]
    public string $portfolio = 'ALL';

    #[Locked]
    public ?string $expectedPortfolioId = null;

    /** Portfolio picker draft, revalidated by OrganizeClient; not #[Locked]. */
    public string $destination = 'NONE';

    public bool $organizeOpen = false;

    /** Member picker draft, revalidated by SetClientMember; not #[Locked]. */
    public string $member = '';

    public bool $editorOpen = false;

    public bool $membersOpen = false;

    #[On('work-context-updated')]
    public function refreshContext(): void
    {
        // Renaming the portfolio keeps the current client-list position.
    }

    public function mount(?string $membersFor = null, ?string $toolsFor = null): void
    {
        $this->sortKey = 'name';
        if ($toolsFor !== null) {
            $client = app(ProjectClients::class)->client($this->access(), $toolsFor);
            abort_unless($client !== null, 403);
            $this->clientId = $client->id;
            $this->toolsOnly = true;
        }
        if ($membersFor !== null) {
            $this->openClient($membersFor);
            $this->membersOnly = true;
        }
    }

    #[On('open-current-client-members')]
    public function openCurrentPeople(): void
    {
        if ($this->toolsOnly && $this->clientId !== null) {
            $this->openClient($this->clientId);
        }
    }

    #[On('edit-current-client')]
    public function editCurrentClient(): void
    {
        if ($this->toolsOnly && $this->clientId !== null) {
            $this->openRename($this->clientId);
        }
    }

    #[On('move-current-client')]
    public function moveCurrentClient(): void
    {
        if ($this->toolsOnly && $this->clientId !== null) {
            $this->openOrganize($this->clientId);
        }
    }

    public function updatedPortfolio(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->dispatch('new-project-client');
    }

    public function openRename(string $clientId): void
    {
        $client = app(ProjectClients::class)->client($this->access(), $clientId);
        abort_unless($client !== null && $client->can_manage, 403);
        $this->resetValidation();
        $this->clientId = $client->id;
        $this->organizeOpen = false;
        $this->name = $client->name;
        $this->description = $client->description ?? '';
        $this->membersOpen = false;
        $this->editorOpen = true;
    }

    public function saveClient(): void
    {
        $actor = $this->access();
        abort_if($this->clientId === null, 404);
        try {
            $client = app(SaveClient::class)->handle($actor, $this->clientId, $this->name, description: $this->description);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The client could not be saved. Please try again.');

            return;
        }
        $this->editorOpen = false;
        if (! $this->toolsOnly) {
            $this->resetPage();
        }
        $this->dispatch('work-context-updated');
        Flux::toast(variant: 'success', text: $client->name.' saved.');
    }

    public function openOrganize(string $clientId): void
    {
        $client = app(ProjectClients::class)->client($this->access(), $clientId);
        abort_unless($client !== null && $client->can_manage, 403);
        $this->resetValidation();
        $this->clientId = $client->id;
        $this->expectedPortfolioId = $client->portfolio_id;
        $this->destination = $client->portfolio_id ?? 'NONE';
        $this->editorOpen = $this->membersOpen = false;
        $this->organizeOpen = true;
    }

    public function organizeClient(): void
    {
        abort_if($this->clientId === null, 404);
        try {
            app(OrganizeClient::class)->handle($this->access(), $this->clientId, $this->destination === 'NONE' ? null : $this->destination, $this->expectedPortfolioId);
        } catch (QueryException $error) {
            report($error);
            Flux::toast(variant: 'danger', text: 'The client could not be organized. Please try again.');

            return;
        }
        $this->organizeOpen = false;
        Flux::toast(variant: 'success', text: 'Client moved.');
        if ($this->toolsOnly) {
            $this->redirectRoute('projects.index', ['client' => $this->clientId, 'portfolio' => $this->destination], navigate: true);
        }
    }

    public function openClient(string $clientId): void
    {
        $client = app(ProjectClients::class)->client($this->access(), $clientId);
        abort_if($client === null, 404);
        $this->resetValidation();
        $this->resetPeople();
        $this->clientId = $client->id;
        $this->organizeOpen = false;
        $this->member = '';
        $this->editorOpen = false;
        $this->membersOpen = true;
    }

    public function addMember(): void
    {
        $actor = $this->access();
        abort_if($this->clientId === null, 404);
        $this->validate(['member' => ['required', 'integer', 'min:1']]);
        try {
            $changed = app(SetClientMember::class)->handle($actor, $this->clientId, (int) $this->member, true);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'Client access could not be saved. Please try again.');

            return;
        }
        $this->member = '';
        if ($changed) {
            Flux::toast(variant: 'success', text: 'Client access added.');
        }
    }

    public function removeMember(int $userId): void
    {
        $actor = $this->access();
        abort_if($this->clientId === null, 404);
        try {
            $changed = app(SetClientMember::class)->handle($actor, $this->clientId, $userId, false);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'Client access could not be removed. Please try again.');

            return;
        }
        if ($changed) {
            Flux::toast(variant: 'success', text: 'Client access removed.');
        }
    }

    public function render(): View
    {
        $actor = $this->access();
        $queries = app(ProjectClients::class);
        $this->collectionView = $this->collectionView === 'list' ? 'list' : 'cards';
        if ($this->membersOnly || $this->toolsOnly) {
            $client = $this->clientId === null ? null : $queries->client($actor, $this->clientId);

            return view('livewire.projects.clients-page', [
                'client' => $client,
                'members' => (! $this->membersOnly && ! $this->membersOpen) || $client === null ? null : $this->people($queries, $actor, $client->id),
                'candidates' => ($this->membersOnly || $this->membersOpen) && $client?->can_manage ? $queries->candidates($actor, $client->id) : collect(),
                'organizingClient' => $this->organizeOpen ? $client : null,
                'contributablePortfolios' => app(ProjectPortfolios::class)->options($actor, activeOnly: true),
            ]);
        }
        $rows = $this->acceptPage($queries->page($actor, $this->search, (int) $this->getPage(), $this->perPage, $this->portfolio, $this->sortKey, $this->sortDir, $this->ownership, $this->contents));
        $client = $this->membersOpen && $this->clientId !== null ? $queries->client($actor, $this->clientId) : null;
        $ownerIds = $rows->getCollection()->map(static fn (ClientView $row): int => $row->owner_id)->unique()->values()->all();

        $portfolios = app(ProjectPortfolios::class)->options($actor);
        $selectedPortfolio = $portfolios->firstWhere('id', $this->portfolio);

        return view('livewire.projects.clients-page', [
            'portfolioCounts' => $selectedPortfolio === null ? ['clients' => 0, 'projects' => 0] : app(ProjectPortfolios::class)->counts($actor, [$selectedPortfolio->id])[$selectedPortfolio->id],
            'selectedPortfolio' => $selectedPortfolio,
            'portfolioUnavailable' => ! in_array($this->portfolio, ['ALL', 'NONE'], true) && $selectedPortfolio === null,
            'managementTab' => 'clients',
            'portfolios' => $portfolios,
            'contributablePortfolios' => app(ProjectPortfolios::class)->options($actor, activeOnly: true),
            'organizingClient' => $this->organizeOpen && $this->clientId !== null ? $queries->client($actor, $this->clientId) : null,
            'totalsByClient' => app(WorkSummary::class)->clients($actor, array_values($rows->getCollection()->pluck('id')->all()), ProjectsData::todayKey()),
            'rows' => $rows,
            'projectCounts' => $queries->projectCounts($actor, array_values($rows->getCollection()->pluck('id')->all())),
            'owners' => $ownerIds === [] ? collect() : app(AccountNames::class)->list($actor, array_values($ownerIds))->keyBy('id'),
            'client' => $client,
            'members' => $client === null ? null : $this->people($queries, $actor, $client->id),
            'candidates' => $client?->can_manage ? $queries->candidates($actor, $client->id) : collect(),
        ]);
    }

    /** @return LengthAwarePaginator<int, WorkMemberView> */
    private function people(ProjectClients $queries, Access $actor, string $id): LengthAwarePaginator
    {
        return $this->acceptPage($queries->people($actor, $id, (int) $this->getPage('peoplePage'), $this->peoplePerPage, $this->peopleSearch, $this->peopleAccess, $this->peopleSort, $this->peopleDirection), 'peoplePage');
    }

    private function access(): Access
    {
        return Access::forActor(auth()->user());
    }
}
