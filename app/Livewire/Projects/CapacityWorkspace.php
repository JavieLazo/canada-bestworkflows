<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\WorkManagement\Application\Commands\SaveProjectAvailability;
use App\Modules\WorkManagement\Application\Commands\SaveTaskEffort;
use App\Modules\WorkManagement\Application\Queries\ProjectCapacity;
use App\Modules\WorkManagement\Domain\Projects\CapacityWindow;
use App\Modules\WorkManagement\Domain\Projects\PlanningHours;
use App\Support\Access;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CapacityWorkspace extends Component
{
    use WithTableFilters;

    #[Locked]
    public ?string $projectId = null;

    #[Locked]
    public bool $own = false;

    #[Locked]
    public string $portfolio = 'ALL';

    #[Locked]
    public string $client = 'ALL';

    /** Calendar filter, normalized on the server; deliberately not #[Locked]. */
    public string $from = '';

    public string $peopleSearch = '';

    public string $taskSearch = '';

    public bool $weekOpen = false;

    public bool $issuesOpen = false;

    public bool $availabilityOpen = false;

    public bool $effortOpen = false;

    public bool $effortInline = false;

    #[Locked]
    public string $personId = '';

    #[Locked]
    public string $personName = '';

    #[Locked]
    public string $weekKey = '';

    #[Locked]
    public string $availabilityWeek = '';

    #[Locked]
    public int $availabilityRevision = 0;

    #[Locked]
    public string $effortTaskId = '';

    #[Locked]
    public string $effortEmployeeId = '';

    #[Locked]
    public string $effortWeek = '';

    #[Locked]
    public int $effortRevision = 0;

    #[Locked]
    public string $effortTitle = '';

    #[Locked]
    public bool $effortCanSet = false;

    #[Locked]
    public ?int $storedHours = null;

    public string $hours = '';

    public bool $shared = false;

    /** @return list<string> */
    protected function pageSizePageNames(): array
    {
        return ['capacityPeople'];
    }

    public function mount(?string $projectId = null, bool $own = false, string $portfolio = 'ALL', string $client = 'ALL'): void
    {
        $this->own = $own;
        $this->projectId = $own ? null : $projectId;
        $this->portfolio = $own ? 'ALL' : $portfolio;
        $this->client = $own ? 'ALL' : $client;
        abort_unless($this->queries()->permissions($this->access(), $this->projectId, $own)['view'], 403);
        $this->from = CapacityWindow::from('');
    }

    public function updatedPeopleSearch(): void
    {
        $this->resetPage('capacityPeople');
    }

    public function updatedTaskSearch(): void
    {
        $this->resetPage('capacityTasks');
    }

    public function moveWindow(int $direction): void
    {
        $this->from = $direction === 0 ? CapacityWindow::from('') : CapacityWindow::move($this->from, $direction);
        $this->weekOpen = $this->effortOpen = $this->availabilityOpen = $this->issuesOpen = false;
        $this->effortInline = false;
        $this->resetPage('capacityPeople');
        $this->resetPage('capacityIssues');
    }

    public function openWeek(string $employeeId, string $week): void
    {
        $actor = $this->access();
        $this->queries()->checkPerson($actor, $this->projectId, $this->own, $employeeId);
        CapacityWindow::validateWeek($week);
        $this->personId = $employeeId;
        $this->personName = app(AssignmentEmployees::class)->names([$employeeId])[$employeeId] ?? 'Employee';
        $this->weekKey = $week;
        $this->taskSearch = '';
        $this->resetPage('capacityTasks');
        $this->effortInline = false;
        $this->weekOpen = true;
    }

    public function openAvailability(string $week): void
    {
        $row = $this->queries()->ownAvailability($this->access(), $week);
        $this->resetValidation();
        $this->availabilityWeek = $week;
        $this->availabilityRevision = $row['revision'];
        $this->hours = $row['hours'] === null ? '' : PlanningHours::format($row['hours']);
        $this->shared = $row['shared'];
        $this->effortOpen = false;
        $this->effortInline = false;
        $this->availabilityOpen = true;
    }

    public function saveAvailability(): void
    {
        if ($this->run(fn () => app(SaveProjectAvailability::class)->handle($this->access(), $this->availabilityWeek, $this->hours, $this->shared, $this->availabilityRevision))) {
            $this->availabilityOpen = false;
        }
    }

    public function openEffort(string $taskId, string $employeeId, string $week): void
    {
        $row = $this->queries()->estimate($this->access(), $this->projectId, $this->own, $taskId, $employeeId, $week);
        abort_unless($row['edit'], 403);
        $this->resetValidation();
        $this->effortTaskId = $taskId;
        $this->effortEmployeeId = $employeeId;
        $this->effortWeek = $week;
        $this->effortRevision = (int) ($row['task']->revision ?? 0);
        $this->effortTitle = $row['task']->title;
        $this->effortCanSet = $row['on_crew'] && ! (bool) $row['task']->is_parent;
        $this->storedHours = $row['task']->hours_hundredths === null ? null : (int) $row['task']->hours_hundredths;
        $this->hours = ! $this->effortCanSet || $this->storedHours === null ? '' : PlanningHours::format($this->storedHours);
        $this->availabilityOpen = false;
        $this->effortInline = $this->effortCanSet && $this->weekOpen && $this->personId === $employeeId && $this->weekKey === $week;
        $this->effortOpen = ! $this->effortInline;
    }

    public function saveEffort(): void
    {
        if ($this->run(fn () => app(SaveTaskEffort::class)->handle($this->access(), $this->effortTaskId, $this->effortEmployeeId, $this->effortWeek,
            $this->effortCanSet ? $this->hours : '', $this->effortRevision))) {
            $this->cancelEffort();
        }
    }

    public function cancelEffort(): void
    {
        $this->effortOpen = $this->effortInline = false;
        $this->resetValidation();
        $this->dispatch('capacity-estimate-closed', taskId: $this->effortTaskId);
    }

    public function render(): View
    {
        $actor = $this->access();
        $queries = $this->queries();
        $permissions = $queries->permissions($actor, $this->projectId, $this->own);
        $this->from = CapacityWindow::from($this->from);

        return view('livewire.projects.capacity-workspace', [
            'permissions' => $permissions, 'weeks' => CapacityWindow::weeks($this->from), 'ownEmployee' => $actor->employeeId,
            'people' => ! $permissions['view'] ? null : $this->acceptPage($queries->people($actor, $this->projectId, $this->own, $this->from, (int) $this->getPage('capacityPeople'), $this->peopleSearch, $this->perPage), 'capacityPeople'),
            'needsEstimate' => ! $permissions['view'] ? 0 : $queries->needsEstimate($actor, $this->projectId, $this->own, $this->from),
            'issues' => ! $permissions['view'] ? null : $this->acceptPage($queries->issues($actor, $this->projectId, $this->own, $this->from, (int) $this->getPage('capacityIssues')), 'capacityIssues'),
            'weekTasks' => ! $permissions['view'] || ! $this->weekOpen ? null : $this->acceptPage($queries->tasksForWeek($actor, $this->projectId, $this->own, $this->personId, $this->weekKey, $this->taskSearch, (int) $this->getPage('capacityTasks')), 'capacityTasks'),
        ]);
    }

    private function run(Closure $operation): bool
    {
        try {
            $operation();
        } catch (QueryException $error) {
            report($error);
            Flux::toast(variant: 'danger', text: 'Planning could not be saved. Please try again.');

            return false;
        }
        $this->resetValidation();
        Flux::toast(variant: 'success', text: 'Planning saved.');

        return true;
    }

    private function access(): Access
    {
        return Access::forActor(auth()->user());
    }

    private function queries(): ProjectCapacity
    {
        return new ProjectCapacity($this->portfolio, $this->client);
    }
}
