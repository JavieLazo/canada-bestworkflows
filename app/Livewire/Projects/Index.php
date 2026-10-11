<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\IdentityAccess\Contracts\AccountLabel as User;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Contracts\Views\ProjectView as Project;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Title;
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
    public string $status = 'ALL';

    public string $site = 'ALL';

    public string $manager = 'ALL';

    public string $queue = 'ALL';

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
            $this->queue = $queue;
        }
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
            $created = app(CreateProject::class)->handle($actor, new ProjectDraft($this->draftName, $this->draftDescription, $this->draftSiteId, $this->draftStatus, $this->draftStartDate, $this->draftDueDate, $this->draftManagerId));
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
    }

    /** Back to the dialog's pristine draft (new-project-dialog.tsx:142-145). */
    private function resetDraft(): void
    {
        $this->draftName = '';
        $this->draftDescription = '';
        $this->draftSiteId = 'NONE';
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
        $today = ProjectsData::todayKey();
        $rows = $this->acceptPage(app(WorkLists::class)->projectPage(
            $actor,
            [
                'q' => $this->search,
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
        ));

        // The stat cards read the WHOLE scoped dataset, not the filtered list
        // (projects-page.tsx:70-77).
        $summary = app(WorkSummary::class);
        $counters = $summary->counters($actor, $today);
        $ids = $rows->getCollection()->map(static fn (Project $project): string => $project->id)->values()->all();

        return view('livewire.projects.index', [
            'rows' => $rows,
            'visibleCount' => $rows->total(),
            'totalsByProject' => $summary->projects($actor, array_values($ids), $today),
            'sites' => app(OrganizationDirectory::class)->sites($actor),
            'accounts' => app(AccountNames::class)->list($actor),
            // Who the Task Leader picker offers: accounts whose role can open
            // Project Management (CreateProject re-checks). Only a manager
            // names leaders, so nobody else is asked for the list.
            'leaderIds' => ProjectsData::managesProjects($actor) ? app(ProjectAccounts::class)->leaderIds($actor) : [],
            'today' => $today,
            'activeProjects' => $counters['active'],
            'reviewCount' => $counters['review'],
            'overdueCount' => $counters['overdue'],
            'dueSoonCount' => $counters['dueSoon'],
            'unassignedCount' => $counters['unassigned'],
            'exportUrl' => route('projects.export', array_filter([
                'q' => $this->search,
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
