<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Support\PositionPicker;
use App\Models\Position;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\EmployeeInput;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Modules\Workforce\Application\EmployeeRules;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use App\Support\EmployeeName;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The hire wizard — the port of
 * `src/app/(protected)/employees/add-employee-dialog.tsx`.
 *
 * Steps mirror the record page, so nothing is asked for twice or missed:
 * thirteen of them, in EmployeeFields::WIZARD order — Personal Details,
 * Addresses, Emergency Contact, Employee Details, Family (the spouse, the
 * parents, the children with their dates of birth), Educational Background,
 * Civil Service Eligibility, Work Experience, Learning and Development,
 * Voluntary Work, Other Information, Declarations, Government IDs & Bank.
 * The Addresses step asks for the residential address in parts (at least
 * one) and whether the permanent one is the same, which starts at Yes and is
 * stored as the answer given. The five list steps draw their rows inline
 * (`rows_<list>`, added and removed with addRow() / removeRow()) and may be
 * left empty; a row left entirely blank is not a row. The legacy one-line
 * address and citizenship, the spouse's name and employment and the college
 * and graduate lines are never asked for: the hire writes the structured
 * parts only.
 *
 * The row ID is assigned by the server inside a transaction
 * (api/employees/route.ts:100-113) and RETRIES ONCE if someone else takes the
 * number first, so the wizard neither asks for it nor previews it: a preview
 * is a number the mint may hand to whoever presses Create first.
 */
class AddEmployeeDialog extends Component
{
    public bool $open = false;

    /**
     * Wizard position. Client-writable by design (the dots navigate); a
     * tampered value only shows another step — `create()` re-validates every
     * section server-side regardless.
     */
    public int $step = 0;

    /**
     * The hire's draft, re-validated whole by CreateEmployee. `department_id`
     * and `unit_id` are not Locked and never saved: they only narrow the
     * Position list; `position_id` is re-resolved by the command
     * (EmployeePosition).
     *
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * Quiet until the field has been visited, or Next was pressed.
     *
     * @var array<string, bool>
     */
    public array $touched = [];

    public bool $showAllErrors = false;

    public function mount(): void
    {
        $this->form = EmployeeFields::blankForm() + PositionPicker::blankDrafts();
    }

    #[On('open-add-employee')]
    public function openDialog(): void
    {
        // DELIBERATE DIVERGENCE — the source reserves the next number here and
        // shows it on step 1 (add-employee-dialog.tsx:93-96). Nothing here
        // renders it, and CreateEmployee mints inside the insert transaction
        // anyway, so reserving it would only promise a number a second hire
        // opened at the same moment could take.
        $this->open = true;
    }

    /** Closing the dialog resets it (add-employee-dialog.tsx:196-199). */
    public function updatedOpen(bool $value): void
    {
        if (! $value) {
            $this->resetWizard();
        }
    }

    public function updated(string $name): void
    {
        // The Livewire stand-in for onBlur: text inputs sync on blur, so the
        // first update marks the field visited.
        if (str_starts_with($name, 'form.')) {
            $this->touched[substr($name, 5)] = true;
        }

        // Department or Unit changed: the Position list follows them, and a
        // choice it no longer lists is dropped (PositionPicker::narrow).
        if ($name === 'form.department_id' || $name === 'form.unit_id') {
            $this->form = PositionPicker::narrow(app(Access::class), $this->form);
        }

        // The Position select binds the choice; its message is the field's.
        if ($name === 'form.'.EmployeePosition::FORM_KEY) {
            $this->touched['position'] = true;
        }

        // A child's name (`form.children.0.name`) visits the one
        // `children` field its message is keyed by.
        if (str_starts_with($name, 'form.children.')) {
            $this->touched['children'] = true;
        }

        // The Addresses step's group messages ("at least one part") are
        // keyed by group, so any part of a group visits that group.
        if (str_starts_with($name, 'form.res_')) {
            $this->touched['residential_address'] = true;
        }

        if (str_starts_with($name, 'form.perm_') || $name === 'form.permanent_address_same') {
            $this->touched['permanent_address'] = true;
        }

        // A list row's input (`form.rows_education.1.school`) visits its own
        // message and the list's, which names the first failing row.
        if (preg_match('/^form\.(rows_[a-z]+)\./', $name, $match) === 1) {
            $this->touched[$match[1]] = true;
        }
    }

    /** The Family step's children: a blank row added, a row removed by position. */
    public function addChild(): void
    {
        $rows = is_array($this->form['children'] ?? null) ? $this->form['children'] : [];

        if (count($rows) >= EmployeeFields::MAX_CHILDREN) {
            return;
        }

        $rows[] = ['name' => '', 'birth_date' => ''];
        $this->form['children'] = $rows;
    }

    public function removeChild(int $index): void
    {
        $rows = is_array($this->form['children'] ?? null) ? $this->form['children'] : [];
        unset($rows[$index]);
        $this->form['children'] = array_values($rows);
        $this->touched['children'] = true;
    }

    /** A list step's rows: a blank draft added, up to the list's cap. */
    public function addRow(string $list): void
    {
        if (! in_array($list, PdsLists::KEYS, true)) {
            return;
        }

        $rows = is_array($this->form['rows_'.$list] ?? null) ? $this->form['rows_'.$list] : [];

        if (count($rows) >= PdsLists::MAX_ROWS) {
            return;
        }

        $rows[] = PdsLists::blankRow($list);
        $this->form['rows_'.$list] = $rows;
    }

    /**
     * A row removed by position. The messages keyed by the rows after it
     * move up with them, so they are visited again from the list down.
     */
    public function removeRow(string $list, int $index): void
    {
        if (! in_array($list, PdsLists::KEYS, true)) {
            return;
        }

        $rows = is_array($this->form['rows_'.$list] ?? null) ? $this->form['rows_'.$list] : [];
        unset($rows[$index]);
        $this->form['rows_'.$list] = array_values($rows);

        foreach (array_keys($this->touched) as $key) {
            if (str_starts_with($key, 'rows_'.$list.'.')) {
                unset($this->touched[$key]);
            }
        }

        $this->touched['rows_'.$list] = true;
    }

    /** An Other info list: a blank line added, up to MAX_ITEMS. */
    public function addItem(string $key): void
    {
        if (! array_key_exists($key, EmployeeFields::ITEM_KINDS)) {
            return;
        }

        $lines = is_array($this->form[$key] ?? null) ? $this->form[$key] : [];

        if (count($lines) >= EmployeeFields::MAX_ITEMS) {
            return;
        }

        $lines[] = '';
        $this->form[$key] = $lines;
    }

    public function removeItem(string $key, int $index): void
    {
        if (! array_key_exists($key, EmployeeFields::ITEM_KINDS)) {
            return;
        }

        $lines = is_array($this->form[$key] ?? null) ? $this->form[$key] : [];
        unset($lines[$index]);
        $this->form[$key] = array_values($lines);
        $this->touched[$key] = true;
    }

    private function resetWizard(): void
    {
        $this->form = EmployeeFields::blankForm() + PositionPicker::blankDrafts();
        $this->touched = [];
        $this->showAllErrors = false;
        $this->step = 0;
    }

    /**
     * One message per offending field on a step, keyed by field
     * (add-employee-dialog.tsx:110-133).
     *
     * @return array<string, string>
     */
    public function stepErrors(int $step): array
    {
        return EmployeeInput::stepErrors($this->form, $step);
    }

    public function goNext(): void
    {
        if ($this->stepErrors($this->step) !== []) {
            $this->showAllErrors = true;

            return;
        }

        $this->showAllErrors = false;
        $this->step = min($this->step + 1, count(EmployeeFields::wizard()) - 1);
    }

    public function goBack(): void
    {
        $this->showAllErrors = false;
        $this->step = max($this->step - 1, 0);
    }

    /** The dots: completed steps are clickable, future ones are not. */
    public function goTo(int $step): void
    {
        if ($step >= 0 && $step <= $this->step && $step < count(EmployeeFields::wizard())) {
            $this->step = $step;
        }
    }

    public function create(): void
    {
        $access = app(Access::class);

        // requireAllDataModule("EMPLOYEES") — api/employees/route.ts:75.
        abort_unless($access->mayReadAllData(ModuleCode::EMPLOYEES), 403);

        // Server-side authority over every step, not just the visible one —
        // the port of the API re-validating the whole body. A failing earlier
        // step is surfaced by jumping to it with its messages shown.
        foreach (array_keys(EmployeeFields::wizard()) as $index) {
            if ($this->stepErrors($index) !== []) {
                $this->step = $index;
                $this->showAllErrors = true;

                return;
            }
        }

        $payload = $this->payload();

        $failure = $this->serverPolicyError($payload);

        if ($failure !== null) {
            Flux::toast(text: $failure, variant: 'danger');
            $this->step = 0;

            return;
        }

        try {
            $created = app(CreateEmployee::class)->handle($access, $this->form);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'That employee could not be saved. Try again.', variant: 'danger');
            $this->step = 0;

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');
            $this->step = 0;

            return;
        }
        Flux::toast(text: EmployeeName::display($created).' added.', variant: 'success');
        $this->dispatch('employee-created');
        $this->resetWizard();
        $this->open = false;
    }

    /**
     * The dialog's payload assembly (add-employee-dialog.tsx:140-155):
     * booleans as booleans, numerics as numbers (blank → 0, or NULL for a
     * `nullable` amount such as Rate Per Day), strings trimmed with '' → null.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return EmployeeInput::payload($this->form);
    }

    /**
     * The API-side guards of POST /api/employees, message for message:
     * schema caps, the calendar policy, and the three existence checks.
     *
     * @param  array<string, mixed>  $payload
     */
    private function serverPolicyError(array $payload): ?string
    {
        return EmployeeRules::creating($payload);
    }

    public function render(): View
    {
        $steps = EmployeeFields::wizard();
        $stepSpec = $steps[$this->step] ?? $steps[0];
        $errors = $this->stepErrors($this->step);

        $shown = [];

        foreach ($errors as $key => $message) {
            if ($this->showAllErrors || ($this->touched[$key] ?? false)) {
                $shown[$key] = $message;
            }
        }

        // Blade never reads a module class, so each list step's wording and
        // fields come from here, with the shown row messages regrouped by
        // row (`rows_education.1.school` → row 1, `school`).
        $lists = [];

        foreach ($stepSpec['lists'] ?? [] as $list) {
            $rowErrors = [];

            foreach ($shown as $key => $message) {
                if (preg_match('/^rows_'.$list.'\.(\d+)\.(\w+)$/', $key, $match) === 1) {
                    $rowErrors[(int) $match[1]][$match[2]] = $message;
                }
            }

            $lists[$list] = [
                'spec' => PdsLists::spec($list),
                'fields' => PdsLists::fields($list),
                'max' => PdsLists::MAX_ROWS,
                'rowErrors' => $rowErrors,
            ];
        }

        return view('livewire.employees.add-employee-dialog', [
            'steps' => $steps,
            'stepSpec' => $stepSpec,
            'sections' => array_values(array_filter(array_map(
                static fn (string $id): ?array => EmployeeFields::section($id),
                $stepSpec['sections'] ?? [],
            ))),
            'lists' => $lists,
            'shownErrors' => $shown,
            'isLast' => $this->step === count($steps) - 1,
            'referenceOptions' => [
                'employment_statuses' => app(EmployeeRecords::class)->statuses(app(Access::class)),
            ],
            'sites' => app(OrganizationDirectory::class)->sites(app(Access::class)),
            // Read only on the step that asks for the position.
            'positionChoices' => in_array('employment', $stepSpec['sections'] ?? [], true) ? PositionPicker::options(app(Access::class), $this->form) : null,
        ]);
    }
}
