<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Workforce\Application\Commands\DeletePdsRow;
use App\Modules\Workforce\Application\Commands\SavePdsRow;
use App\Modules\Workforce\Application\Commands\SubmitRecordChange;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\Queries\PdsRows;
use App\Modules\Workforce\Contracts\Views\PdsRowView;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/** Personal-data lists support management editing and independently reviewed My Profile proposals. */
class PdsList extends Component
{
    use WithTableFilters;

    #[Locked]
    public string $employeeId;

    #[Locked]
    public bool $own = false;

    /** Which list this card is; whitelisted in mount() and never changed. */
    #[Locked]
    public string $list;

    /** Plain UI state: the add/edit dialog is open. */
    public bool $dialogOpen = false;

    /** The row being edited, or null when adding. */
    #[Locked]
    public ?string $editingId = null;

    /** @var array<string, string> the dialog draft; SavePdsRow re-validates every field */
    public array $form = [];

    /** @var array<string, string> */
    public array $formErrors = [];

    /** Plain UI state: the delete confirm is open. */
    public bool $confirmOpen = false;

    /** The row queued for deletion. */
    #[Locked]
    public ?string $deletingId = null;

    /** The queued row's name, shown in the confirm only. Display text: deleteConfirmed() acts on $deletingId alone. */
    public string $deletingLabel = '';

    public function mount(string $employeeId, string $list, bool $own = false): void
    {
        abort_unless(in_array($list, PdsLists::KEYS, true), 404);

        $this->own = $own;
        $access = app(Access::class);
        $this->employeeId = $own ? ($access->hasEmployeeLink() ? $access->ownEmployeeId() : '') : $employeeId;
        $this->list = $list;
        $this->resetDialog();
    }

    private function targetEmployee(Access $access): string
    {
        if ($this->own) {
            abort_unless($access->hasModule(ModuleCode::PROFILE), 403);

            return $access->hasEmployeeLink() ? $access->ownEmployeeId() : '';
        }

        return $this->employeeId;
    }

    private function requiresApproval(): bool
    {
        return $this->own;
    }

    private function canEdit(Access $access): bool
    {
        return $this->requiresApproval()
            ? $access->hasModule(ModuleCode::PROFILE) && $access->hasEmployeeLink() && $access->ownEmployeeId() === $this->employeeId
            : $access->mayReadAllData(ModuleCode::EMPLOYEES);
    }

    /**
     * Opens the dialog on a blank row. On Education it starts on `$level`
     * when that is a known level — the level an empty row, a legacy row or
     * "Save & add another" was on.
     */
    public function openAdd(?string $level = null): void
    {
        $access = app(Access::class);
        if (! $this->canEdit($access)) {
            abort(403);
        }

        $this->editingId = null;
        $this->form = PdsLists::blankRow($this->list, $this->list === 'education' ? $level : null);
        $this->formErrors = [];
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $access = app(Access::class);
        if (! $this->canEdit($access)) {
            abort(403);
        }

        $row = app(PdsRows::class)->find($access, $this->targetEmployee($access), $this->list, $id);

        if ($row === null) {
            Flux::toast(text: 'That '.PdsLists::spec($this->list)['one'].' was removed.', variant: 'danger');

            return;
        }

        $this->form = self::draft($this->list, $row);
        $this->formErrors = [];
        $this->editingId = $id;
        $this->dialogOpen = true;
    }

    /**
     * Saves the dialog. `$another` is "Save & add another": the dialog stays
     * open on a blank row, on the same level for a school.
     */
    public function save(bool $another = false): void
    {
        $access = app(Access::class);
        if (! $this->canEdit($access)) {
            abort(403);
        }

        $this->formErrors = PdsLists::errors($this->list, $this->form);

        if ($this->formErrors !== []) {
            return;
        }

        try {
            if ($this->requiresApproval()) {
                app(SubmitRecordChange::class)->pds($access, $this->list, $this->editingId, $this->form);
                $this->dialogOpen = false;
                $this->resetDialog();
                $this->dispatch('record-change-submitted');
                Flux::toast(text: 'Change request submitted. The official record stays unchanged until approved.', variant: 'success');

                return;
            }
            $one = app(SavePdsRow::class)->handle($access, $this->targetEmployee($access), $this->list, $this->editingId, $this->form);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'That could not be saved. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        if ($one === null) {
            return;
        }

        Flux::toast(text: ucfirst($one).' saved.', variant: 'success');

        if ($another) {
            $this->openAdd($this->list === 'education' ? (string) ($this->form['level'] ?? '') : null);

            return;
        }

        $this->dialogOpen = false;
        $this->resetDialog();
    }

    public function confirmDelete(string $id): void
    {
        $access = app(Access::class);
        if (! $this->canEdit($access)) {
            abort(403);
        }

        $row = app(PdsRows::class)->find($access, $this->targetEmployee($access), $this->list, $id);

        if ($row === null) {
            Flux::toast(text: 'That '.PdsLists::spec($this->list)['one'].' was removed.', variant: 'danger');

            return;
        }

        $this->deletingId = $id;
        $this->deletingLabel = PdsLists::label($this->list, $row->toArray());
        $this->confirmOpen = true;
    }

    public function deleteConfirmed(): void
    {
        $access = app(Access::class);
        if (! $this->canEdit($access)) {
            abort(403);
        }

        if ($this->deletingId === null) {
            return;
        }

        try {
            if ($this->requiresApproval()) {
                app(SubmitRecordChange::class)->pds($access, $this->list, $this->deletingId, [], delete: true);
                $this->dispatch('record-change-submitted');
                Flux::toast(text: 'Removal submitted for approval.', variant: 'success');
            } else {
                $label = app(DeletePdsRow::class)->handle($access, $this->targetEmployee($access), $this->list, $this->deletingId);
                if ($label !== null) {
                    Flux::toast(text: $label.' deleted.', variant: 'success');
                }
            }
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'That could not be submitted. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->confirmOpen = false;
        $this->resetConfirm();
    }

    /** Closing the dialog drops the draft. */
    public function updatedDialogOpen(bool $value): void
    {
        if (! $value) {
            $this->resetDialog();
        }
    }

    /** Closing the confirm forgets the queued row. */
    public function updatedConfirmOpen(bool $value): void
    {
        if (! $value) {
            $this->resetConfirm();
        }
    }

    private function resetDialog(): void
    {
        $this->editingId = null;
        // Hidden date and money controls need their bound keys before Alpine initializes.
        $this->form = PdsLists::blankRow($this->list);
        $this->formErrors = [];

    }

    private function resetConfirm(): void
    {
        $this->deletingId = null;
        $this->deletingLabel = '';

    }

    /**
     * A stored row as the dialog's draft: dates are already `YYYY-MM-DD`,
     * a year or an amount prints as typed (25000.0 → "25000"), Gov't
     * service reads Y / N, and a blank is "".
     *
     * @return array<string, string>
     */
    private static function draft(string $list, PdsRowView $row): array
    {
        $draft = [];

        foreach (PdsLists::fields($list) as $field) {
            $value = $row->getAttribute($field['key']);

            $draft[$field['key']] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'Y' : 'N',
                is_scalar($value) => (string) $value,
                default => '',
            };
        }

        return $draft;
    }

    public function render(): View
    {
        $access = app(Access::class);
        $pageName = $this->list.'Page';
        $page = app(PdsRows::class)->page($access, $this->targetEmployee($access), $this->list, (int) $this->getPage($pageName), $this->perPage);

        return view('livewire.employees.pds-list', [
            'rows' => $this->acceptPage($page['rows'], $pageName),
            'name' => $page['name'],
            'noun' => $page['noun'],
            'legacy' => $page['legacy'],
            'spec' => PdsLists::spec($this->list),
            'fields' => PdsLists::fields($this->list),
            'subtitle' => PdsLists::subtitle($this->list, $page['name']),
            'canEdit' => $this->canEdit($access),
            'requiresApproval' => $this->requiresApproval(),
        ]);
    }
}
