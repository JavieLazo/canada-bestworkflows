<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Organization\Application\Commands\DeleteOrgNode;
use App\Modules\Organization\Application\Commands\MoveOrgNode;
use App\Modules\Organization\Application\Commands\SaveDepartment;
use App\Modules\Organization\Application\Commands\SaveUnit;
use App\Modules\Organization\Application\Queries\StructureRecords;
use App\Modules\Organization\Application\StructureReads;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrgLevel;
use App\Modules\Organization\Contracts\Views\StructureOptionView;
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
 * Departments & Units: the departments, and the units inside each one, in
 * the order HR sets. Positions are placed in them on the Position page.
 *
 * Permissions, as every Records list:
 *  - the PAGE needs RECORDS — route middleware;
 *  - every WRITE needs all_data + RECORDS, re-checked by the Organization
 *    commands each action calls with a fresh Access;
 *  - Delete is refused while a department has units, or while positions are
 *    placed in the row — the menu item says why, and the command refuses
 *    whatever the menu showed.
 */
#[Layout('layouts.app')]
#[Title('Departments & Units')]
class DepartmentsPage extends Component
{
    use WithTableFilters;

    /** Add/Edit dialog visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** Which dialog is open: OrgLevel::DEPARTMENT or OrgLevel::UNIT. Set by actions only → #[Locked]. */
    #[Locked]
    public string $dialogKind = OrgLevel::DEPARTMENT;

    /** The row being edited, or null when adding. Id-bearing → #[Locked]; set via actions only. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formDescription = '';

    /** The head of office named on the Application for Leave. Plain form state; the commands re-check it. */
    public string $formHeadName = '';

    public string $formHeadDesignation = '';

    /**
     * The unit dialog's Department select — a wire:model draft, deliberately
     * not #[Locked]: SaveUnit re-resolves it under the structure mutex and
     * refuses anything that is not a department.
     */
    public string $formDepartmentId = '';

    /** The dialog's inline error — "Name is required." */
    public ?string $formError = null;

    /** Delete confirmation visibility. Plain UI state. */
    public bool $confirmOpen = false;

    /** The row queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    public function openAddDepartment(): void
    {
        $this->openDialog(OrgLevel::DEPARTMENT, null, '', '', '');
    }

    /** Add unit, from a department's row menu: the department is preselected. */
    public function openAddUnit(string $departmentId): void
    {
        $this->openDialog(OrgLevel::UNIT, null, '', '', $departmentId);
    }

    public function startEdit(string $id): void
    {
        $node = app(StructureRecords::class)->find(app(Access::class), $id);

        if ($node === null) {
            Flux::toast(variant: 'danger', text: 'That department or unit no longer exists.');

            return;
        }

        $this->openDialog($node->kind === OrgLevel::UNIT ? OrgLevel::UNIT : OrgLevel::DEPARTMENT, $node->id, $node->name, (string) ($node->description ?? ''), (string) ($node->parent_id ?? ''), (string) ($node->head_name ?? ''), (string) ($node->head_designation ?? ''));
    }

    public function save(): void
    {
        if (trim($this->formName) === '') {
            $this->formError = 'Name is required.';

            return;
        }
        $this->formError = null;
        $access = app(Access::class);
        try {
            $message = $this->dialogKind === OrgLevel::UNIT
                ? app(SaveUnit::class)->handle($access, $this->editingId, $this->formName, $this->formDepartmentId, $this->formDescription, $this->formHeadName, $this->formHeadDesignation)
                : app(SaveDepartment::class)->handle($access, $this->editingId, $this->formName, $this->formDescription, $this->formHeadName, $this->formHeadDesignation);
        } catch (UniqueConstraintViolationException) {
            Flux::toast(variant: 'danger', text: '"'.trim($this->formName).'" is already on the list.');

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
            app(MoveOrgNode::class)->handle($access, $id, $direction);
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
            $message = app(DeleteOrgNode::class)->handle($access, $id);
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

    private function openDialog(string $kind, ?string $id, string $name, string $description, string $departmentId, string $headName = '', string $headDesignation = ''): void
    {
        $this->formHeadName = $headName;
        $this->formHeadDesignation = $headDesignation;
        $this->dialogKind = $kind;
        $this->editingId = $id;
        $this->formName = $name;
        $this->formDescription = $description;
        $this->formDepartmentId = $departmentId;
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function render(): View
    {
        $access = app(Access::class);
        $records = app(StructureRecords::class);
        $paginator = $this->acceptPage($records->page($access, $this->search, (int) $this->getPage(), $this->perPage));

        return view('livewire.records.departments-page', [
            'paginator' => $paginator,
            'headsReady' => StructureReads::headsReady(),
            'editing' => $this->editingId !== null ? $records->find($access, $this->editingId) : null,
            'deleting' => $this->deletingId !== null ? $records->find($access, $this->deletingId) : null,
            'departments' => array_values(array_filter(
                app(OrganizationDirectory::class)->structureOptions($access),
                static fn (StructureOptionView $option): bool => ! $option->unit,
            )),
        ]);
    }
}
