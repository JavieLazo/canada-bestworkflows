<?php

declare(strict_types=1);

namespace App\Livewire\Kit;

use App\Livewire\Kit\Concerns\ReadsEmployeeDirectory;
use App\Models\EmployeeProfile;
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
 * {@see ReadsEmployeeDirectory}, shared with the multi-select
 * {@see EmployeeChecklist} so the two controls can never disagree about who
 * an actor may see. The actor is re-read from the session EVERY request —
 * never cached on a property, never taken from the client.
 */
class EmployeePicker extends Component
{
    use ReadsEmployeeDirectory;

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

        $employee = $this->directoryQuery()->whereKey($id)->first();

        if ($employee === null) {
            return;
        }

        $this->value = (string) $employee->getKey();
        $this->search = '';

        $this->dispatch('kit-employee-picked', name: $this->name, id: $this->value);
    }

    public function render(): mixed
    {
        return view('livewire.kit.employee-picker', [
            'visible' => $this->visibleEmployees(),
            'selected' => $this->selectedEmployee(),
            'listId' => 'epk-'.$this->getId().'-list',
            'buttonId' => $this->triggerId ?? 'epk-'.$this->getId().'-trigger',
        ]);
    }

    /** @return Collection<int, EmployeeProfile> */
    protected function visibleEmployees(): Collection
    {
        $query = $this->applyDirectorySearch($this->directoryQuery(), $this->search);

        return $query->get([
            'id', 'employee_code', 'first_name', 'last_name', 'position', 'is_active', 'photo_path',
        ]);
    }

    protected function selectedEmployee(): ?EmployeeProfile
    {
        if ($this->value === '') {
            return null;
        }

        // Through the SAME scoped query: a value pointing outside the actor's
        // visibility renders as the placeholder, never as a name they may not
        // read.
        return $this->directoryQuery()->whereKey($this->value)->first();
    }
}
