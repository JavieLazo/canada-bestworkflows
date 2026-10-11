<?php

declare(strict_types=1);

namespace App\Livewire\Kit;

use App\Domain\Enums\ModuleCode;
use App\Modules\Workforce\Contracts\DirectoryEmployee as EmployeeProfile;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * The multi-select counterpart of {@see EmployeePicker}: tick several
 * employees instead of choosing one. Built for a task's assignees, where a
 * task is one shared unit of work handed to 0..N people.
 *
 * Same idea as the picker — a search box over the SCOPED directory, resolved
 * server-side, never a list handed to the browser — but rendered inline as a
 * scrolling checklist (the payroll "Add employees" recipe) rather than as a
 * combobox, because a set of names does not fit in a trigger.
 *
 * ── API (modules integrate against exactly this) ────────────────────────────
 *
 *     <livewire:kit.employee-checklist
 *         wire:model="taskAssigneeIds"     list<string> of employee ids
 *         name="task-assignees"            identifies THIS control
 *         aria-label="Assignees"
 *         :active-only="true"             default; false includes former staff
 *         :scope-modules="[]"             optional narrower source row scope
 *         :with-filters="false"           report filters and bulk selection
 *         :selection-limit="null"         optional bound on selected employees
 *         :key="'task-assignees-'.$dialogNonce"
 *     />
 *
 * `value` is #[Modelable]: it IS the parent's property, kept in sync both
 * ways by Livewire, so there is no event to listen for — the parent reads its
 * own array. That also means it cannot be #[Locked] (an entangled property
 * must accept the parent's writes). Access INVARIANT 3 is met the other way
 * round, by re-validation:
 *
 *   - `toggle()` ADDS an id only after resolving it through the scoped
 *     directory as an ACTIVE employee unless activeOnly is false; anything
 *     else is dropped on the floor. scopeModules can only narrow that directory.
 *     Removing an id that is already in the list needs no lookup.
 *   - The summary and the checkmarks only ever name ids the actor may read;
 *     a smuggled id renders nothing.
 *   - THE PARENT MUST STILL RE-VALIDATE the list server-side before it
 *     writes (active employee, may be assigned) — exactly as it does for the
 *     single picker's id — because the client can edit the entangled array
 *     directly, bypassing toggle().
 *
 * Selected employees stay selected when the search hides them: the list is a
 * window onto the directory, the selection is the parent's state.
 */
class EmployeeChecklist extends Component
{
    /**
     * The selected employee ids, in the order they were ticked. Entangled with
     * the parent's property through wire:model. Typed loosely because the
     * client can post anything into an entangled array; {@see selectedIds()}
     * is the cleaned list<string> everything else reads.
     *
     * @var array<int, mixed>
     */
    #[Modelable]
    public array $value = [];

    /** Names this control instance in its ids and labels. */
    #[Locked]
    public string $name = 'employees';

    #[Locked]
    public string $placeholder = 'Search employees';

    #[Locked]
    public string $ariaLabel = 'Employees';

    #[Locked]
    public bool $disabled = false;

    /** Task assignments require active staff; historical reports may include former staff. */
    #[Locked]
    public bool $activeOnly = true;

    /**
     * Optional source modules narrow the operational directory's row scope.
     * These are server-owned component options, never client authorization.
     *
     * @var list<string>
     */
    #[Locked]
    public array $scopeModules = [];

    /** Reports opt into filtering and bulk selection; task assignment leaves them off. */
    #[Locked]
    public bool $withFilters = false;

    /** Server-owned limit also applies to single selection, before changing the parent's state. */
    #[Locked]
    public ?int $selectionLimit = null;

    /** The live search text. Plain filter state, deliberately unlocked. */
    public string $search = '';

    public string $position = 'ALL';

    public string $active = 'ALL';

    public string $employmentStatus = 'ALL';

    public string $sort = 'name-asc';

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 120);
        $this->resetValidation('value');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['position', 'active', 'employmentStatus', 'sort'], true)) {
            $this->resetValidation('value');
        }
    }

    /** Changing the chooser window never changes the parent's selected employees. */
    public function resetFilters(): void
    {
        if (! $this->withFilters || $this->disabled) {
            return;
        }

        $this->search = '';
        $this->position = $this->active = $this->employmentStatus = 'ALL';
        $this->sort = 'name-asc';
        $this->resetValidation('value');
    }

    /** Resolve all matches on the server; never accept a client-supplied bulk list. */
    public function selectAll(): void
    {
        if (! $this->withFilters || $this->disabled) {
            return;
        }

        $selected = $this->selectedEmployees()->pluck('id')->all();
        $matching = $this->visibleEmployees()
            ->filter(fn (EmployeeProfile $row): bool => ! $this->activeOnly || $row->is_active)
            ->pluck('id')->all();
        $ids = array_values(array_unique([...$selected, ...$matching]));
        $this->ensureWithinLimit($ids);
        $this->value = $ids;
        $this->resetValidation('value');
    }

    /** Remove only matches in the current scoped window, keeping selections hidden by filters. */
    public function deselectMatching(): void
    {
        if (! $this->withFilters || $this->disabled) {
            return;
        }

        $matching = $this->visibleEmployees()->pluck('id')->all();
        $this->value = array_values(array_diff($this->selectedEmployees()->pluck('id')->all(), $matching));
        $this->resetValidation('value');
    }

    /** Explicitly clear the complete selection, independently of the current filters. */
    public function deselectAll(): void
    {
        $access = app(Access::class);
        if (! $this->withFilters || $this->disabled) {
            return;
        }

        // The same fresh source-scoped read fences every bulk action, even
        // when clearing needs no employee identity from the browser.
        $this->directoryEmployees([], actor: $access);
        $this->value = [];
        $this->resetValidation('value');
    }

    /**
     * Tick or untick one employee. An id being ADDED arrives from the client,
     * so it is re-resolved through the same source-scoped directory as the
     * list and must be active when activeOnly is on, else it is ignored.
     */
    public function toggle(string $id): void
    {
        if ($this->disabled || $id === '') {
            return;
        }

        $selected = $this->selectedIds();

        if (in_array($id, $selected, true)) {
            $this->value = array_values(array_filter($selected, static fn (string $picked): bool => $picked !== $id));
            $this->resetValidation('value');

            return;
        }

        $employee = $this->directoryEmployees([$id])->first();

        if ($employee === null || ($this->activeOnly && ! $employee->is_active)) {
            return;
        }

        $ids = [...$selected, $id];
        $this->ensureWithinLimit($ids);
        $this->value = $ids;
        $this->resetValidation('value');
    }

    public function render(): mixed
    {
        $selected = $this->selectedEmployees();
        $visible = $this->visibleEmployees();
        $directory = $this->withFilters ? $this->directoryEmployees() : collect();
        $positions = $directory->pluck('position')->filter(static fn (?string $position): bool => trim((string) $position) !== '')
            ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $statuses = $directory->pluck('employment_status')->filter(static fn (string $status): bool => $status !== '')
            ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $selectedIds = $this->withFilters ? $selected->pluck('id')->all() : $this->selectedIds();

        return view('livewire.kit.employee-checklist', [
            'visible' => $visible,
            'selected' => $selected,
            'selectedIds' => $selectedIds,
            'summary' => $this->summary($selected),
            'positionOptions' => ['ALL' => 'All positions', 'NONE' => 'No position'] + $positions->mapWithKeys(static fn (string $position): array => [$position => $position])->all(),
            'employmentOptions' => ['ALL' => 'All employment types'] + $statuses->mapWithKeys(static fn (string $status): array => [$status => HrisFormat::employmentStatus($status)])->all(),
            'matchingCount' => $visible->count(),
            'matchedSelectedCount' => $visible->filter(static fn (EmployeeProfile $row): bool => in_array($row->id, $selectedIds, true))->count(),
            'listId' => 'ecl-'.$this->getId().'-list',
            'searchId' => 'ecl-'.$this->getId().'-search',
        ]);
    }

    /**
     * The entangled array as a clean list of strings — the client can hand
     * back anything, so nothing downstream trusts its shape.
     *
     * @return list<string>
     */
    protected function selectedIds(): array
    {
        $ids = [];

        foreach ($this->value as $id) {
            if (is_string($id) && $id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The rows that can be ticked in the source-scoped directory. Task
     * assignments default to active staff, but retain selected former staff
     * so an assignee deactivated since assignment can still be unticked.
     *
     * @return Collection<int, EmployeeProfile>
     */
    protected function visibleEmployees(): Collection
    {
        $selected = $this->selectedIds();

        return $this->directoryEmployees(search: $this->search, filters: $this->withFilters ? [
            'position' => $this->position, 'active' => $this->active,
            'employmentStatus' => $this->employmentStatus, 'sort' => $this->sort,
        ] : [])
            ->filter(fn (EmployeeProfile $row): bool => ! $this->activeOnly || $row->is_active || in_array($row->id, $selected, true))
            ->values();
    }

    /**
     * The selected employees the actor may read, for the summary line. Not
     * filtered by search or by is_active: an already-assigned employee who
     * has since been deactivated is still named here.
     *
     * @return Collection<int, EmployeeProfile>
     */
    protected function selectedEmployees(): Collection
    {
        return $this->directoryEmployees($this->selectedIds());
    }

    /**
     * Apply the source scope BEFORE the directory reads any employee names.
     * The directory keeps its own scope too, so this option never widens it.
     *
     * @param  list<string>|null  $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string}  $filters
     * @return Collection<int, EmployeeProfile>
     */
    private function directoryEmployees(?array $ids = null, string $search = '', array $filters = [], ?Access $actor = null): Collection
    {
        $actor ??= app(Access::class);
        $modules = $this->scopeModules;

        foreach ($modules as $module) {
            if (! in_array($module, [ModuleCode::EMPLOYEES, ModuleCode::PAYROLL, ModuleCode::EDTR, ModuleCode::ROSTER, ModuleCode::LEAVES, ModuleCode::PROJECTS, ModuleCode::TASKS], true)) {
                throw new InvalidArgumentException('Employee checklist scope must use operational directory modules.');
            }
        }

        if ($modules !== []) {
            $allowed = match ($actor->rowVisibility($modules[0], ...array_slice($modules, 1))) {
                Access::ROWS_ALL => null,
                Access::ROWS_OWN => [$actor->ownEmployeeId()],
                Access::ROWS_NONE => [],
            };

            if ($allowed !== null) {
                $ids = $ids === null ? $allowed : array_values(array_intersect($ids, $allowed));
            }
        }

        return app(OperationalDirectory::class)->list($actor, $ids, $search, $filters);
    }

    /** @param list<string> $ids */
    private function ensureWithinLimit(array $ids): void
    {
        if ($this->selectionLimit !== null && count($ids) > $this->selectionLimit) {
            throw ValidationException::withMessages([
                'value' => 'Choose up to '.number_format($this->selectionLimit).' employees. Narrow the filters or deselect employees before adding more.',
            ]);
        }
    }

    /**
     * "2 selected — Ana Garcia, Ben Reyes" or "No one selected".
     *
     * @param  Collection<int, EmployeeProfile>  $selected
     */
    protected function summary(Collection $selected): string
    {
        if ($selected->isEmpty()) {
            return 'No one selected';
        }

        $names = $selected->map(static fn (EmployeeProfile $e): string => EmployeeName::display($e))->all();

        return count($names).' selected — '.implode(', ', $names);
    }
}
