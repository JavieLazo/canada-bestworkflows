<?php

declare(strict_types=1);

namespace App\Livewire\Kit;

use App\Livewire\Kit\Concerns\ReadsEmployeeDirectory;
use App\Models\EmployeeProfile;
use App\Support\EmployeeName;
use Illuminate\Support\Collection;
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
 *     directory as an ACTIVE employee; anything else is dropped on the floor.
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
    use ReadsEmployeeDirectory;

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

    /** The live search text. Plain filter state, deliberately unlocked. */
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 120);
    }

    /**
     * Tick or untick one employee. An id being ADDED arrives from the client,
     * so it is re-resolved through the scoped directory and must be active —
     * the rows the list shows — else it is ignored.
     */
    public function toggle(string $id): void
    {
        if ($this->disabled || $id === '') {
            return;
        }

        $selected = $this->selectedIds();

        if (in_array($id, $selected, true)) {
            $this->value = array_values(array_filter($selected, static fn (string $picked): bool => $picked !== $id));

            return;
        }

        $known = $this->directoryQuery()
            ->whereKey($id)
            ->where('is_active', true)
            ->exists();

        if (! $known) {
            return;
        }

        $this->value = [...$selected, $id];
    }

    public function render(): mixed
    {
        $selected = $this->selectedEmployees();

        return view('livewire.kit.employee-checklist', [
            'visible' => $this->visibleEmployees(),
            'selected' => $selected,
            'selectedIds' => $this->selectedIds(),
            'summary' => $this->summary($selected),
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
     * The rows that can be ticked: ACTIVE employees in the scoped directory
     * matching the search — plus anyone already selected who matches, active
     * or not, so an assignee deactivated since they were assigned can still
     * be unticked.
     *
     * @return Collection<int, EmployeeProfile>
     */
    protected function visibleEmployees(): Collection
    {
        $selected = $this->selectedIds();

        $query = $this->applyDirectorySearch($this->directoryQuery(), $this->search)
            ->where(function ($q) use ($selected): void {
                $q->where('is_active', true);

                if ($selected !== []) {
                    $q->orWhereIn('id', $selected);
                }
            });

        return $query->get([
            'id', 'employee_code', 'first_name', 'last_name', 'suffix', 'position', 'is_active',
        ]);
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
        $ids = $this->selectedIds();

        if ($ids === []) {
            return new Collection;
        }

        return $this->directoryQuery()->whereKey($ids)->get([
            'id', 'employee_code', 'first_name', 'last_name', 'suffix', 'position', 'is_active',
        ]);
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
