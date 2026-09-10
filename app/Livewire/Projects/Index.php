<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Enums\AuditEvent;
use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
       against the database on save, so they carry no authorization weight. */
    public bool $adding = false;

    public string $draftName = '';

    public string $draftDescription = '';

    public string $draftSiteId = 'NONE';

    public string $draftStatus = 'PLANNING';

    public string $draftStartDate = '';

    public string $draftDueDate = '';

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

        if (! ProjectsData::managesProjects($actor)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }

        $name = trim($this->draftName);
        if ($name === '' || $this->draftStartDate === '') {
            Flux::toast(variant: 'danger', text: 'A project needs a name and a start date.');

            return;
        }
        if (mb_strlen($name) > 200) {
            Flux::toast(variant: 'danger', text: 'name: must be at most 200 characters');

            return;
        }
        $description = trim($this->draftDescription);
        if (mb_strlen($description) > 4000) {
            Flux::toast(variant: 'danger', text: 'description: must be at most 4000 characters');

            return;
        }

        $dueDate = $this->draftDueDate === '' ? null : $this->draftDueDate;
        $dateError = ProjectPolicy::dateError($this->draftStartDate, $dueDate);
        if ($dateError !== null) {
            Flux::toast(variant: 'danger', text: $dateError);

            return;
        }

        $managerId = $this->draftManagerId === 'NONE' ? null : $this->draftManagerId;
        $statusError = ProjectPolicy::statusError(
            ['status' => 'PLANNING', 'manager_id' => $managerId],
            [],
            $this->draftStatus,
        );
        if ($statusError !== null) {
            Flux::toast(variant: 'danger', text: $statusError);

            return;
        }

        $siteId = $this->draftSiteId === 'NONE' ? null : $this->draftSiteId;
        if ($siteId !== null && WorkSite::query()->whereKey($siteId)->doesntExist()) {
            Flux::toast(variant: 'danger', text: 'Choose an existing work site for the project.');

            return;
        }
        if ($managerId !== null) {
            $managerRow = User::query()->whereKey($managerId)->first();
            if ($managerRow === null || ! (bool) $managerRow->getAttribute('is_active')) {
                Flux::toast(variant: 'danger', text: 'Choose an active account as task leader.');

                return;
            }
        }

        try {
            /** @var Project $created */
            $created = DB::transaction(function () use ($name, $description, $siteId, $managerId, $dueDate): Project {
                // The port of the reference's advisory lock around the code
                // mint (POST /api/projects) — FOR UPDATE serialises two
                // concurrent creators on the rows being counted past.
                $codes = DB::table('projects')->lockForUpdate()->pluck('code')->all();

                $project = new Project;
                $project->fill([
                    'code' => ProjectsData::nextCode('PRJ', $codes),
                    'name' => $name,
                    'description' => $description === '' ? null : $description,
                    'site_id' => $siteId,
                    'status' => $this->draftStatus,
                    'start_date' => $this->draftStartDate,
                    'due_date' => $dueDate,
                ]);
                // Not an actor column, but not mass-assignable either — set the
                // validated value directly, as the compact() insert did.
                $project->setAttribute('manager_id', $managerId === null ? null : (int) $managerId);
                $project->save();

                return $project;
            });
        } catch (QueryException) {
            // The port of respond.ts P2002 — `code` and `name` are both unique.
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        }

        AuditService::record(
            $actor,
            AuditEvent::PROJECT_CREATED,
            'projects',
            $created->id,
            "{$created->code} {$name} opened",
        );

        Flux::toast(variant: 'success', text: "{$name} created as {$created->code}.");
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
        $this->draftStartDate = '';
        $this->draftDueDate = '';
        $this->draftManagerId = 'NONE';
    }

    public function render(): View
    {
        $actor = $this->access();
        $data = ProjectsData::projectsDataset($actor);
        $today = ProjectsData::todayKey();

        /** @var array<string, list<ProjectTask>> $tasksByProject */
        $tasksByProject = $data['tasksByProject'];

        $visible = ProjectsData::visibleProjects(
            $data['projects'],
            $tasksByProject,
            $data['accounts'],
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
        );

        // The stat cards read the WHOLE scoped dataset, not the filtered list
        // (projects-page.tsx:70-77).
        $activeProjects = $data['projects']->where('status', 'ACTIVE')->count();
        $reviewCount = $data['tasks']->filter(fn ($t): bool => TaskPolicy::isAwaitingReview($t->status))->count();
        $overdueCount = $data['tasks']->filter(fn ($t): bool => TaskPolicy::isOverdue(ProjectsData::taskShape($t), $today))->count();
        $dueSoonCount = $data['projects']->filter(
            fn ($p): bool => ProjectPolicy::health(
                ProjectsData::projectShape($p),
                ProjectsData::taskShapes($tasksByProject[$p->id] ?? []),
                $today,
            ) === 'DUE_SOON',
        )->count();
        $unassignedCount = $data['tasks']->filter(fn ($t): bool => $t->assigneeIds() === [])->count();

        return view('livewire.projects.index', [
            'rows' => $this->paginateClamped($visible),
            'visibleCount' => count($visible),
            'tasksByProject' => $tasksByProject,
            'sites' => $data['sites'],
            'accounts' => $data['accounts'],
            'today' => $today,
            'activeProjects' => $activeProjects,
            'reviewCount' => $reviewCount,
            'overdueCount' => $overdueCount,
            'dueSoonCount' => $dueSoonCount,
            'unassignedCount' => $unassignedCount,
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
