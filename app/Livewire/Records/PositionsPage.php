<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Organization\Application\Commands\DeletePosition;
use App\Modules\Organization\Application\Commands\MovePosition;
use App\Modules\Organization\Application\Commands\SavePosition;
use App\Modules\Organization\Application\Queries\PositionRecords;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\Views\StructureOptionView;
use App\Modules\Workforce\Application\Queries\PositionHolders;
use App\Platform\ReadModels\Record;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Position: the job titles offered when hiring or editing an employee
 * record, each placed in a department or in one of its units. The same
 * title may be entered in more than one place.
 *
 * Permissions, as every Records list:
 *  - the PAGE needs RECORDS — route middleware;
 *  - every WRITE needs all_data + RECORDS, re-checked by the Organization
 *    commands each action calls with a fresh Access;
 *  - the holder count behind "Delete · held by N" covers every employee
 *    record linked to the position, active and inactive, unscoped
 *    (Workforce's PositionHolders, a RECORDS read): Delete is disabled while
 *    anyone holds it, and DeletePosition refuses it whatever the page showed.
 *    The page shows no Held by column.
 */
#[Layout('layouts.app')]
#[Title('Position')]
class PositionsPage extends Component
{
    use WithTableFilters;

    /**
     * "Filter by department": 'ALL', 'NONE' (Not placed), a department (its
     * own positions and its units') or one unit. Plain filter state over a
     * catalog with no row scope; PositionRecords matches an unknown id to
     * nothing.
     */
    public string $department = 'ALL';

    /** Add/Edit dialog visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** The position being edited, or null when adding. Id-bearing → #[Locked]; set via actions only. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formDescription = '';

    /**
     * The dialog's Department select ('' = Not placed) — a wire:model draft,
     * deliberately not #[Locked]: SavePosition re-resolves the placement
     * under the structure mutex and refuses one that no longer exists.
     */
    public string $formDepartmentId = '';

    /**
     * The dialog's Unit select ('' = in the department itself) — a wire:model
     * draft, deliberately not #[Locked]: save() keeps it only while it is a
     * unit of the chosen department, and SavePosition re-resolves it.
     */
    public string $formUnitId = '';

    /** The dialog's inline error — "Name is required." */
    public ?string $formError = null;

    /** Delete confirmation visibility. Plain UI state. */
    public bool $confirmOpen = false;

    /** The position queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    /** A new department shows only its own units: a unit from the old one is dropped. */
    public function updatedFormDepartmentId(): void
    {
        if (! in_array($this->formUnitId, array_column($this->unitsOf($this->formDepartmentId), 'id'), true)) {
            $this->formUnitId = '';
        }
    }

    public function openAdd(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formDescription = '';
        $this->formDepartmentId = '';
        $this->formUnitId = '';
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $position = app(PositionRecords::class)->find(app(Access::class), $id);

        if ($position === null) {
            Flux::toast(variant: 'danger', text: 'That position no longer exists.');

            return;
        }

        $this->editingId = $position->id;
        $this->formName = $position->name;
        $this->formDescription = (string) ($position->description ?? '');
        $this->formDepartmentId = (string) ($position->department_id ?? '');
        $this->formUnitId = (string) ($position->unit_id ?? '');
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function save(): void
    {
        $name = trim($this->formName);
        if ($name === '') {
            $this->formError = 'Name is required.';

            return;
        }
        $this->formError = null;
        $access = app(Access::class);
        $unit = in_array($this->formUnitId, array_column($this->unitsOf($this->formDepartmentId), 'id'), true) ? $this->formUnitId : '';
        $place = $unit !== '' ? $unit : ($this->formDepartmentId !== '' ? $this->formDepartmentId : null);
        try {
            $message = app(SavePosition::class)->handle($access, $this->editingId, $name, $this->formDescription, $place);
        } catch (UniqueConstraintViolationException) {
            Flux::toast(variant: 'danger', text: "\"{$name}\" is already on the list.");

            return;
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The record could not be saved. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->dialogOpen = false;
        $this->editingId = null;
    }

    public function move(string $id, string $direction): void
    {
        $access = app(Access::class);
        try {
            app(MovePosition::class)->handle($access, $id, $direction);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The order could not be changed. Please try again.');
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingId = $id;
        $this->confirmOpen = true;
    }

    public function deleteConfirmed(): void
    {
        $id = $this->deletingId;
        $this->confirmOpen = false;
        $this->deletingId = null;
        if ($id === null) {
            return;
        }
        $access = app(Access::class);
        try {
            $message = app(DeletePosition::class)->handle($access, $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The record could not be deleted. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * The chosen department's units, from the structure the page already
     * shows; none for Not placed or an id that is not a department.
     *
     * @return list<array{id: string, name: string}>
     */
    private function unitsOf(string $departmentId): array
    {
        if ($departmentId === '') {
            return [];
        }
        $units = [];
        foreach (app(OrganizationDirectory::class)->structureOptions(app(Access::class)) as $option) {
            if ($option->unit && $option->department_id === $departmentId) {
                $units[] = ['id' => $option->id, 'name' => $option->name];
            }
        }

        return $units;
    }

    public function render(): View
    {
        $access = app(Access::class);
        $records = app(PositionRecords::class);
        $positions = $this->acceptPage($records->page($access, $this->search, $this->department, (int) $this->getPage(), $this->perPage));
        $editing = $this->editingId !== null ? $records->find($access, $this->editingId) : null;
        $deleting = $this->deletingId !== null ? $records->find($access, $this->deletingId) : null;

        $ids = array_map(static fn (Record $row): string => (string) $row->id, $positions->items());
        if ($editing !== null) {
            $ids[] = $editing->id;
        }
        $holders = app(PositionHolders::class)->counts($access, array_values($ids));
        $options = app(OrganizationDirectory::class)->structureOptions($access);

        return view('livewire.records.positions-page', [
            'positions' => $positions,
            'holders' => $holders,
            'editing' => $editing,
            'editingHolders' => $editing === null ? 0 : array_sum($holders[$editing->id] ?? []),
            'deleting' => $deleting,
            'placeOptions' => $options,
            'departments' => array_values(array_filter($options, static fn (StructureOptionView $option): bool => ! $option->unit)),
            'units' => $this->unitsOf($this->formDepartmentId),
        ]);
    }
}
