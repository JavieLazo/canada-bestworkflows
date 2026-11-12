<?php

declare(strict_types=1);

namespace App\Livewire\Kit;

use App\Livewire\Concerns\TableDefaults;
use App\Modules\Workforce\Contracts\DirectoryEmployee as EmployeeProfile;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Port of src/components/EmployeePicker.tsx — pick an employee by typing
 * rather than by scrolling.
 *
 * A plain select is fine for the five statuses in Records Management and
 * hopeless for three hundred people; this is the same control with a search
 * box on top of it — type any part of a code, a name or a job title and the
 * list narrows to what matches. Livewire rather than a blade component because
 * the search runs SERVER-SIDE against the scoped directory, not against a list
 * handed to the browser.
 *
 * ── API (modules integrate against exactly this) ────────────────────────────
 *
 *     <livewire:kit.employee-picker
 *         :value="$form->employeeId"      selected employee id, '' for none
 *         name="assignee"                 identifies THIS picker in the event
 *         :active-only="true"             optional: hide people who have left
 *         :department="$department"       optional: one department (and its units),
 *                                         one unit, or "NONE" (Not placed)
 *         wire:key="assignee-{{ $form->employeeId }}"
 *     />
 *
 * A pick round-trips through `choose()` — which re-checks the id against the
 * actor's scope — and is announced to the parent as a Livewire event:
 *
 *     #[On('kit-employee-picked')]
 *     public function employeePicked(string $name, string $id): void
 *     {
 *         if ($name === 'assignee') { $this->form->employeeId = $id; }
 *     }
 *
 * `value` is #[Locked] (id-bearing), so a parent that changes the selection
 * itself re-mounts the picker via `wire:key`, as the usage above shows.
 *
 * ── Who appears in the list ─────────────────────────────────────────────────
 *
 * The scoped directory query and the multi-term search live in
 * {@see OperationalDirectory}, shared with the multi-select
 * {@see EmployeeChecklist} so the two controls can never disagree about who
 * an actor may see. The actor is re-read from the session EVERY request —
 * never cached on a property, never taken from the client. The list shows
 * the first {@see PAGE_SIZE} matches and says how many there are in all.
 */
class EmployeePicker extends Component
{
    /**
     * The list draws one table page of matches, never the whole directory:
     * the search runs on the server, so typing finds everyone else, and a
     * picker on a 300-person page does not carry 300 rows of markup.
     */
    public const int PAGE_SIZE = TableDefaults::DEFAULT_PAGE_SIZE;

    /** The selected employee's id, or "" for none. Id-bearing → #[Locked]. */
    #[Locked]
    public string $value = '';

    /** Names this picker instance in the `kit-employee-picked` event payload. */
    #[Locked]
    public string $name = 'employee';

    #[Locked]
    public string $placeholder = 'Search or select employee';

    #[Locked]
    public string $ariaLabel = 'Employee';

    /**
     * The id of the visible label element. Given one, the field is announced
     * as its name AND its current value (EmployeePicker.tsx:154-158).
     */
    #[Locked]
    public ?string $labelledBy = null;

    /** Overrides the generated trigger id, for a <label for="..."> pairing. */
    #[Locked]
    public ?string $triggerId = null;

    #[Locked]
    public bool $disabled = false;

    /**
     * Offer ACTIVE employees only, for a control that starts something
     * new — Inventory's issue dialogs. Off by default: the eDTR and
     * the punch station look up people who have left as well. Enforced on
     * the pick and the trigger too, not only on the list, because the id
     * arrives from the client.
     */
    #[Locked]
    public bool $activeOnly = false;

    /**
     * Narrow the list to one department and its units, one unit, or
     * OperationalDirectory::PLACE_NOT_PLACED; PLACE_ALL (the default) lists
     * everyone the actor may see. It only ever narrows: the directory applies
     * it AFTER its own row scope. Enforced on the pick as well as the list,
     * because the id arrives from the client. The selected employee keeps
     * showing on the trigger when they are outside it, so a parent that
     * changes the department does not blank the record already open.
     * #[Locked]: a parent changes it by re-keying the picker.
     */
    #[Locked]
    public string $department = OperationalDirectory::PLACE_ALL;

    /** The live search text. Plain filter state, deliberately unlocked. */
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 120);
    }

    /**
     * Commit a pick. The id arrives from the client, so it is re-resolved
     * through the scoped directory query — an id outside the actor's
     * visibility is dropped on the floor, not selected.
     */
    public function choose(string $id): void
    {
        if ($this->disabled) {
            return;
        }

        $employee = $this->resolve($id);

        if ($employee === null || ! $this->inDepartment($id)) {
            return;
        }

        $this->value = (string) $employee->getKey();
        $this->search = '';

        $this->dispatch('kit-employee-picked', name: $this->name, id: $this->value);
    }

    public function render(): mixed
    {
        $selected = $this->selectedEmployee();
        $page = app(OperationalDirectory::class)->firstPage(app(Access::class), self::PAGE_SIZE, search: $this->search, filters: $this->filters());

        return view('livewire.kit.employee-picker', [
            'visible' => $this->withSelected(new Collection($page->items()), $selected),
            'total' => $page->total(),
            'selected' => $selected,
            'listId' => 'epk-'.$this->getId().'-list',
            'buttonId' => $this->triggerId ?? 'epk-'.$this->getId().'-trigger',
        ]);
    }

    /** @return array{place: string, active?: string} */
    private function filters(): array
    {
        return ['place' => $this->department] + ($this->activeOnly ? ['active' => 'ACTIVE'] : []);
    }

    /**
     * Puts the chosen employee first when they fall outside the page of an
     * EMPTY search — the list as it opens — so the highlight still lands on
     * them and Enter keeps them instead of picking whoever sorts first. Once
     * the user types, the list is the matches in order and the highlight
     * starts at the top, as it always has.
     *
     * @param  Collection<int, EmployeeProfile>  $visible
     * @return Collection<int, EmployeeProfile>
     */
    private function withSelected(Collection $visible, ?EmployeeProfile $selected): Collection
    {
        if ($selected === null || $this->search !== '' || $visible->contains(static fn (EmployeeProfile $e): bool => $e->id === $selected->id)) {
            return $visible;
        }

        $matches = app(OperationalDirectory::class)->list(app(Access::class), [$selected->id], $this->search, $this->filters())->isNotEmpty();

        return $matches ? $visible->prepend($selected)->values() : $visible;
    }

    protected function selectedEmployee(): ?EmployeeProfile
    {
        if ($this->value === '') {
            return null;
        }

        // Through the SAME scoped query: a value pointing outside the actor's
        // visibility renders as the placeholder, never as a name they may not
        // read.
        return $this->resolve($this->value);
    }

    /** Whether an id is inside the department the parent narrowed the list to. */
    private function inDepartment(string $id): bool
    {
        return $this->department === OperationalDirectory::PLACE_ALL
            || app(OperationalDirectory::class)->list(app(Access::class), [$id], filters: ['place' => $this->department])->isNotEmpty();
    }

    /** The employee an id names, within the actor's scope and, when asked, only while active. */
    private function resolve(string $id): ?EmployeeProfile
    {
        $employee = app(OperationalDirectory::class)->find(app(Access::class), $id);

        return $employee === null || ($this->activeOnly && ! $employee->is_active) ? null : $employee;
    }
}
