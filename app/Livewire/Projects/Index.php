<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Projects\ProjectPolicy;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\IdentityAccess\Contracts\AccountLabel as User;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\ClientPeople;
use App\Modules\WorkManagement\Application\Queries\ProjectClients;
use App\Modules\WorkManagement\Application\Queries\ProjectPortfolios;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Contracts\Views\ProjectView as Project;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /projects — the port of projects-page.tsx: the manager's project list with
 * its work-queue cards, filters, sortable table and the New-project dialog
 * (new-project-dialog.tsx).
 *
 * Reads follow GET /api/projects and GET /api/tasks; the create follows
 * POST /api/projects — requireAllDataModule("PROJECTS"), re-checked inside the
 * action because a Livewire method is reachable without the page load.
 */
#[Title('Project Management')]
class Index extends Component
{
    use WithSorting;
    use WithTableFilters;

    private const array QUEUES = ['ALL', 'REVIEW', 'OVERDUE', 'DUE_SOON', 'UNASSIGNED'];

    /* Filters — plain filter state, deliberately unlocked (search comes from
       WithTableFilters). */
    #[Url(except: 'ALL')]
    public string $status = 'ALL';

    public string $site = 'ALL';

    public string $manager = 'ALL';

    #[Url(except: 'ALL')]
    public string $queue = 'ALL';

    #[Url(as: 'client', except: 'ALL')]
    public string $client = 'ALL';

    #[Url(except: 'ALL')]
    public string $portfolio = 'ALL';

    #[Url(as: 'layout', except: 'cards')]
    public string $projectView = 'cards';

    #[Url(except: 'overview')]
    public string $section = 'overview';

    /** New-project draft, revalidated by CreateProject; not #[Locked]. */
    public string $draftClientId = 'NONE';

    /* New-project dialog. The two selected ids (siteId / managerId) are DATA
       the form legitimately picks — the reference API accepts both from the
       request body ("manager_id … names who is accountable rather than who
       wrote the row", Project model docblock) — and both are re-validated
       against the database on save (the leader through
       ProjectAccounts::canLead(), since being named one grants the
       project's run of the place), so the DRAFT carries no authorization
       weight. */
    public bool $adding = false;

    public string $draftName = '';

    public string $draftDescription = '';

    /** Dialog draft — re-validated against the database in createProject(), so not #[Locked] (see the note above $adding). */
    public string $draftSiteId = 'NONE';

    public string $draftStatus = 'PLANNING';

    public string $draftStartDate = '';

    public string $draftDueDate = '';

    /** Dialog draft — re-validated as an active account in createProject(), so not #[Locked] (see the note above $adding). */
    public string $draftManagerId = 'NONE';

    public function mount(): void
    {
        $this->sortKey = 'code';
        $this->draftStartDate = ProjectsData::todayKey();
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['code', 'name', 'manager', 'status'];
    }

    /* The reference resets to page 1 when the status / site / manager filters
       move (projects-page.tsx:273-320) but NOT for the queue cards or the
       queue select, and not for the search box (the clamp covers those). */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSite(): void
    {
        $this->resetPage();
    }

    public function updatedManager(): void
    {
        $this->resetPage();
    }

    public function updatedQueue(mixed $value): void
    {
        if (! in_array($value, self::QUEUES, true)) {
            $this->queue = 'ALL';
        }
    }

    /** The five stat cards double as queue filters (projects-page.tsx:79-81). */
    public function setQueue(string $queue): void
    {
        if (in_array($queue, self::QUEUES, true)) {
            if ($this->section === 'overview') {
                $this->clearFilters();
            }
            $this->queue = $queue;
            $this->section = 'projects';
            $this->resetPage();
        }
    }

    public function showProjectStatus(string $status): void
    {
        if (! array_key_exists($status, ProjectPolicy::STATUSES)) {
            return;
        }
        $this->clearFilters();
        $this->status = $status;
        $this->section = 'projects';
    }

    public function openCreateProject(): void
    {
        $actor = $this->access();
        $this->resetDraft();
        $clients = app(ProjectClients::class)->options($actor, manageOnly: true, portfolio: $this->portfolio);
        if ($this->client !== 'ALL' && $clients->contains('id', $this->client)) {
            $this->draftClientId = $this->client;
        } elseif (! ProjectsData::managesProjects($actor) || ! in_array($this->portfolio, ['ALL', 'NONE'], true)) {
            $this->draftClientId = $clients->first()->id ?? 'NONE';
        }
        $this->updatedDraftClientId();
        $this->adding = true;
    }

    public function updatedDraftClientId(): void
    {
        $this->draftManagerId = $this->draftClientId === 'NONE' ? 'NONE' : ($this->access()->userId ?? 'NONE');
    }

    public function updatedPortfolio(): void
    {
        $this->client = 'ALL';
        $this->clearFilters();
    }

    public function updatedClient(): void
    {
        $this->resetPage();
    }

    #[On('work-context-updated')]
    public function refreshContext(): void
    {
        // Renaming the open context keeps the current list position.
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = $this->site = $this->manager = $this->queue = 'ALL';
        $this->resetPage();
    }

    /** Closing the dialog resets the form (new-project-dialog.tsx:142-145). */
    public function updatedAdding(mixed $value): void
    {
        if (! $value) {
            $this->resetDraft();
        }
    }

    /**
     * new-project-dialog.tsx `submit()` + POST /api/projects, in the same
     * order the reference user hits the messages.
     */
    public function createProject(): void
    {
        $actor = $this->access();
        try {
            $created = app(CreateProject::class)->handle($actor, new ProjectDraft($this->draftName, $this->draftDescription, $this->draftSiteId, $this->draftStatus, $this->draftStartDate, $this->draftDueDate, $this->draftManagerId, $this->draftClientId === 'NONE' ? null : $this->draftClientId));
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: "{$created->name} created as project {$created->code}.");
        $this->resetDraft();
        $this->adding = false;
        $this->redirectRoute('projects.show', ['project' => $created->id], navigate: true);
    }

    /** Back to the dialog's pristine draft (new-project-dialog.tsx:142-145). */
    private function resetDraft(): void
    {
        $this->draftName = '';
        $this->draftDescription = '';
        $this->draftSiteId = 'NONE';
        $this->draftClientId = 'NONE';
        $this->draftStatus = 'PLANNING';
        // Today, as the reference resets it (new-project-dialog.tsx:73) and
        // as mount() seeds it: the start date is required, so a blank one
        // would refuse every later create in the session until retyped.
        $this->draftStartDate = ProjectsData::todayKey();
        $this->draftDueDate = '';
        $this->draftManagerId = 'NONE';
    }

    public function render(): View
    {
        $actor = $this->access();
        $this->projectView = $this->projectView === 'list' ? 'list' : 'cards';
        $today = ProjectsData::todayKey();
        $this->section = in_array($this->section, ['overview', 'projects', 'capacity', 'members'], true) ? $this->section : 'overview';
        if ($this->section === 'members' && $this->client === 'ALL' && in_array($this->portfolio, ['ALL', 'NONE'], true)) {
            $this->section = 'overview';
        }
        $summary = app(WorkSummary::class);
        $overview = $this->section === 'overview' ? $summary->overview($actor, $today, $this->client, $this->portfolio) : null;
        $rows = $this->section === 'projects' ? $this->acceptPage(app(WorkLists::class)->projectPage(
            $actor,
            [
                'q' => $this->search,
                'client' => $this->client,
                'portfolio' => $this->portfolio,
                'status' => $this->status,
                'site' => $this->site,
                'manager' => $this->manager,
                'queue' => $this->queue,
            ],
            $this->sortKey,
            $this->sortDir,
            $today,
            (int) $this->getPage(),
            $this->perPage,
        )) : null;

        // The stat cards read the WHOLE scoped dataset, not the filtered list
        // (projects-page.tsx:70-77).
        $summary = app(WorkSummary::class);
        $counters = $summary->counters($actor, $today, $this->client === 'ALL' ? null : $this->client, $this->portfolio);
        $ids = ($rows?->getCollection() ?? $overview['attention'] ?? collect())->map(static fn (Project $project): string => $project->id)->values()->all();

        $creatableClients = app(ProjectClients::class)->options($actor, manageOnly: true, portfolio: $this->portfolio);

        $portfolioQueries = app(ProjectPortfolios::class);
        $selectedPortfolio = in_array($this->portfolio, ['ALL', 'NONE'], true) ? null : $portfolioQueries->portfolio($actor, $this->portfolio);
        $visibleClients = app(ProjectClients::class)->options($actor, portfolio: $this->portfolio);
        $selectedClient = $this->client === 'ALL' ? null : app(ProjectClients::class)->client($actor, $this->client);
        $contextPortfolio = $selectedPortfolio;
        if ($contextPortfolio === null && $selectedClient?->portfolio_id !== null && $selectedClient->portfolio_name !== null) {
            $contextPortfolio = $portfolioQueries->portfolio($actor, $selectedClient->portfolio_id);
        }

        $overviewClients = $overview === null ? collect() : ($this->client === 'ALL'
            ? app(ProjectClients::class)->page($actor, '', 1, 6, $this->portfolio)->getCollection()
            : $visibleClients->where('id', $this->client)->values());
        $clientIds = array_values($overviewClients->pluck('id')->all());

        return view('livewire.projects.index', [
            'contextCounts' => $selectedClient !== null ? ['clients' => 1, 'projects' => app(ProjectClients::class)->projectCounts($actor, [$selectedClient->id])[$selectedClient->id] ?? 0] : ($selectedPortfolio !== null ? $portfolioQueries->counts($actor, [$selectedPortfolio->id])[$selectedPortfolio->id] : ['clients' => 0, 'projects' => 0]),
            'selectedPortfolio' => $selectedPortfolio,
            'contextPortfolio' => $contextPortfolio,
            'selectedClient' => $selectedClient,
            'clientUnavailable' => $this->client !== 'ALL' && ($selectedClient === null || ($this->portfolio !== 'ALL' && $selectedClient->portfolio_id !== ($this->portfolio === 'NONE' ? null : $this->portfolio))),
            'canAddClient' => $selectedClient === null && (in_array($this->portfolio, ['ALL', 'NONE'], true) || ($selectedPortfolio !== null && $selectedPortfolio->archived_at === null)),
            'scopeParameters' => ['portfolio' => $contextPortfolio->id ?? $this->portfolio, 'client' => $selectedClient->id ?? 'ALL'],
            'sectionTitle' => ['overview' => 'Overview', 'projects' => 'Projects', 'capacity' => 'Availability', 'members' => 'Members'][$this->section],

            'portfolioUnavailable' => ! in_array($this->portfolio, ['ALL', 'NONE'], true) && $selectedPortfolio === null,
            'overviewClients' => $overviewClients,
            'overviewClientCounts' => $clientIds === [] ? [] : app(ProjectClients::class)->projectCounts($actor, $clientIds),
            'overviewClientTotals' => $clientIds === [] ? [] : $summary->clients($actor, $clientIds, $today),
            'rows' => $rows,
            'overview' => $overview,
            'activeFilterCount' => count(array_filter([$this->status, $this->site, $this->manager, $this->queue], static fn (string $value): bool => $value !== 'ALL')) + (trim($this->search) === '' ? 0 : 1),
            'visibleCount' => $rows?->total() ?? ($overview === null ? 0 : array_sum($overview['projects'])),
            'totalsByProject' => $summary->projects($actor, array_values($ids), $today),
            'sites' => app(OrganizationDirectory::class)->sites($actor),
            'accounts' => app(AccountNames::class)->list($actor),
            // Who the Task Leader picker offers: accounts whose role can open
            // Project Management (CreateProject re-checks). Only a manager
            // names leaders, so nobody else is asked for the list.
            'leaderIds' => $this->draftClientId !== 'NONE' && $creatableClients->contains('id', $this->draftClientId)
                ? app(ClientPeople::class)->leaders($actor, $this->draftClientId)->pluck('id')->all()
                : (ProjectsData::managesProjects($actor) && $this->draftClientId === 'NONE' ? app(ProjectAccounts::class)->leaderIds($actor) : []),
            'clients' => $visibleClients->keyBy('id'),
            'creatableClients' => $creatableClients,
            'canCreateInternal' => in_array($this->portfolio, ['ALL', 'NONE'], true) && ProjectsData::managesProjects($actor),
            'today' => $today,
            'activeProjects' => $counters['active'],
            'reviewCount' => $counters['review'],
            'overdueCount' => $counters['overdue'],
            'dueSoonCount' => $counters['dueSoon'],
            'unassignedCount' => $counters['unassigned'],
            'exportUrl' => route('projects.export', array_filter([
                'q' => $this->search,
                'client' => $this->client,
                'portfolio' => $this->portfolio,
                'status' => $this->status,
                'site' => $this->site,
                'manager' => $this->manager,
                'queue' => $this->queue,
                'sort' => $this->sortKey,
                'dir' => $this->sortDir,
            ], static fn (string $v): bool => $v !== '')),
        ]);
    }

    /** Rebuilt from the session on EVERY request — never cached on a property. */
    private function access(): Access
    {
        return Access::forActor(auth()->user());
    }
}
