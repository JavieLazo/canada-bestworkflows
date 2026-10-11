<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Support\PositionPicker;
use App\Modules\IdentityAccess\Application\Queries\AccountLinks;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\Commands\DeleteEmployee;
use App\Modules\Workforce\Application\Commands\SubmitRecordChange;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\Commands\UpdatePortrait;
use App\Modules\Workforce\Application\EmployeeInput;
use App\Modules\Workforce\Application\Queries\EmployeePdsWorkbook;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Modules\Workforce\Application\Queries\EmploymentObligations;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView as EmployeeProfile;
use App\Modules\Workforce\Domain\Records\OwnRecordFields;
use App\Platform\Files\PortraitUrl;
use App\Support\Access;
use App\Support\Portrait;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The full employee record is shared by management and My Profile. Permitted
 * My Profile edits require independent approval; Employee Records grants
 * management direct editing, including their linked employee record.
 */
#[Title('Employee record')]
class Show extends Component
{
    use WithFileUploads;

    /** The tabs in strip order, value => label. */
    public const array TABS = [
        'profile' => 'Profile',
        'family' => 'Family',
        'education' => 'Education',
        'eligibility' => 'Eligibility',
        'experience' => 'Work experience',
        'training' => 'Training',
        'voluntary' => 'Voluntary work',
        'other' => 'Other info',
        'declarations' => 'Declarations',
        'documents' => 'Documents',
        'inventory' => 'Issued items',
    ];

    /** The tabs drawn by the list card (PdsList), one list each. */
    public const array LIST_TABS = ['education', 'eligibility', 'experience', 'training', 'voluntary'];

    /**
     * The Profile tab's columns, top to bottom; the record's order, not the
     * wizard's. Emergency Contact sits on the wide right column, where its
     * address and its Cancel / Save buttons have room beside the title.
     */
    public const array PROFILE_LEFT = ['personal'];

    public const array PROFILE_RIGHT = ['employment', 'addresses', 'emergency', 'statutory'];

    /** The tab each editable section is drawn on. */
    private const array SECTION_TAB = [
        'personal' => 'profile',
        'addresses' => 'profile',
        'emergency' => 'profile',
        'employment' => 'profile',
        'statutory' => 'profile',
        'spouse' => 'family',
        'parents' => 'family',
        'children' => 'family',
        'skills' => 'other',
        'distinctions' => 'other',
        'memberships' => 'other',
        'declarations' => 'declarations',
    ];

    #[Locked]
    public string $employeeId = '';

    #[Locked]
    public bool $own = false;

    public bool $portraitOpen = false;

    public bool $portraitRemoved = false;

    /** Which tab is showing. Plain UI state; anything unknown collapses back to Profile. */
    public string $tab = 'profile';

    public ?string $editingSection = null;

    /**
     * The open section's draft. Not Locked: every value is re-validated by
     * the owning employee command. Employee Details also carries `department_id` and
     * `unit_id`, which only narrow the Position list and are never saved,
     * and `position_id`, which the command re-resolves (EmployeePosition).
     *
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * Field-keyed messages for the section being edited.
     *
     * @var array<string, string>
     */
    public array $formErrors = [];

    public bool $deleteConfirmOpen = false;

    /** Not Locked: this is a confirmation draft rechecked against outstanding obligations by UpdateEmployee. */
    public bool $acknowledgeObligations = false;

    public string $obligationReason = '';

    /**
     * The picked portrait (Livewire temporary upload).
     *
     * @var TemporaryUploadedFile|null
     */
    public $photo = null;

    public function mount(string $employee = '', bool $own = false): void
    {
        $this->own = $own;
        $access = app(Access::class);
        $this->employeeId = $own && $access->hasEmployeeLink() ? $access->ownEmployeeId() : $employee;

        // Deep links follow this entry point and the actor's current grants.
        $this->updatedTab((string) request()->query('tab', ''));
    }

    /**
     * The strip's buttons and the narrow-screen Section select. An unknown
     * value collapses to Profile; leaving the tab of the section being
     * edited drops that edit, so no hidden draft lingers, while choosing the
     * tab already showing keeps it.
     */
    public function updatedTab(string $value): void
    {
        $this->tab = array_key_exists($value, $this->availableTabs(app(Access::class))) ? $value : 'profile';

        if ($this->editingSection !== null && (self::SECTION_TAB[$this->editingSection] ?? 'profile') !== $this->tab) {
            $this->cancelEditing();
        }
    }

    /**
     * The record, through the same visibility tiers as the list: everyone for
     * `all_data` + EMPLOYEES/PAYROLL, own record otherwise, nothing for an
     * unlinked account. With the details row and the addresses, which the
     * cards and the 201 sheet show.
     */
    private function employee(): ?EmployeeProfile
    {
        $access = app(Access::class);
        if ($this->own) {
            abort_unless($access->hasModule(ModuleCode::PROFILE), 403);

            return app(EmployeeRecords::class)->own($access, details: true);
        }

        return app(EmployeeRecords::class)->find($access, $this->employeeId, details: true);
    }

    private function isHr(Access $access): bool
    {
        return $access->mayReadAllData(ModuleCode::EMPLOYEES);
    }

    private function requiresApproval(): bool
    {
        return $this->own;
    }

    private function canEdit(Access $access): bool
    {
        return $this->requiresApproval()
            ? $access->hasModule(ModuleCode::PROFILE) && $access->hasEmployeeLink() && $access->ownEmployeeId() === $this->employeeId
            : $this->isHr($access);
    }

    private function canEditSection(Access $access, string $section): bool
    {
        return $this->canEdit($access)
            && (! $this->requiresApproval() || OwnRecordFields::allowsSection($section));
    }

    /** @return array<string, string> */
    private function availableTabs(Access $access): array
    {
        $tabs = self::TABS;
        if ($this->requiresApproval() || ! $this->isHr($access)) {
            unset($tabs['documents']);
        }

        return $tabs;
    }

    /* ── Section editing ─────────────────────────────────────────────────── */

    public function startEdit(string $sectionId): void
    {
        $access = app(Access::class);
        $this->acknowledgeObligations = false;
        $this->obligationReason = '';
        $employee = $this->employee();

        $section = EmployeeFields::section($sectionId);

        if ($employee === null || $section === null) {
            return;
        }

        if (! $this->canEditSection($access, $sectionId)) {
            return;
        }

        // Department and Unit start where the linked position sits; they
        // only narrow the Position list (PositionPicker).
        $this->form = EmployeeFields::toForm($employee) + PositionPicker::drafts($employee);
        $this->formErrors = [];

        $this->editingSection = $sectionId;
        $this->tab = self::SECTION_TAB[$sectionId] ?? 'profile';
    }

    public function cancelEditing(): void
    {
        $this->editingSection = null;

        $this->formErrors = [];
    }

    /**
     * The Children card's rows, a name and a date of birth each: a row is
     * added blank and removed by its position; the draft is re-checked and
     * re-normalised on Save (EmployeeInput::childrenError / children, then
     * UpdateEmployee).
     */
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
    }

    /**
     * An Other info card's lines: a line is added blank and removed by its
     * position. Form state only — the key is whitelisted against the three
     * lists, and the draft is re-checked on Save (EmployeeInput::linesError
     * / lines, then UpdateEmployee).
     */
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
    }

    /**
     * Email is checked as the field is left (employee-detail.tsx:100-110).
     * DELIBERATE DIVERGENCE — so are the two contact numbers, through the
     * same per-answer rule the wizard applies; the reference has no phone
     * rule at all (see PhoneRule). Everything else waits for Save.
     */
    public function updated(string $name): void
    {
        if (! str_starts_with($name, 'form.')) {
            return;
        }

        $key = substr($name, 5);

        // Department or Unit changed: the Position list follows, and a
        // choice it no longer lists is dropped (PositionPicker::narrow).
        if ($key === 'department_id' || $key === 'unit_id') {
            $this->form = PositionPicker::narrow(app(Access::class), $this->form);

            return;
        }

        $field = $this->contactField($key);

        if ($field === null) {
            return;
        }

        $message = EmployeeInput::fieldError($field, EmployeeInput::text($this->form[$key] ?? ''));

        if ($message !== null) {
            $this->formErrors[$key] = $message;
        } else {
            unset($this->formErrors[$key]);
        }
    }

    /**
     * The spec of an email or tel field — the two kinds checked on blur — or
     * null for any other key.
     *
     * @return array<string, mixed>|null
     */
    private function contactField(string $key): ?array
    {
        foreach (EmployeeFields::allFields() as $field) {
            if ($field['key'] === $key && in_array($field['type'] ?? null, ['email', 'tel'], true)) {
                return $field;
            }
        }

        return null;
    }

    public function saveSection(string $sectionId): void
    {
        $access = app(Access::class);
        $employee = $this->employee();
        $section = EmployeeFields::section($sectionId);

        if ($employee === null || $section === null) {
            return;
        }

        if (! $this->canEditSection($access, $sectionId)) {
            abort(403);
        }

        // ── The section-level checks the page runs before submitting ───────
        // DELIBERATE DIVERGENCE — the reference checks only the section's
        // email here (employee-detail.tsx:108-112); this runs every
        // answer's shape through the wizard's own per-answer rule, so the
        // message lands under the field instead of in a toast. UpdateEmployee
        // repeats the checks and is the authority.
        $sectionErrors = [];

        foreach ($section['fields'] as $field) {
            // A field waiting on another answer (Specify, the dual
            // citizenship parts, the permanent parts) is checked only while
            // it is asked; UpdateEmployee clears it otherwise.
            if (isset($field['derived']) || ! EmployeeFields::shown($field, $this->form)) {
                continue;
            }

            $key = (string) $field['key'];
            // The children and the Other info lines are lists, checked as a
            // whole; a declaration's answer goes through fieldError, whose
            // option check covers it. Every other field is one text.
            $message = match (true) {
                EmployeeFields::isChildren($field) => EmployeeInput::childrenError($this->form[$key] ?? null),
                EmployeeFields::isLines($field) => EmployeeInput::linesError($this->form[$key] ?? null),
                default => EmployeeInput::fieldError($field, EmployeeInput::text($this->form[$key] ?? '')),
            };

            if ($message !== null) {
                $sectionErrors[$key] = $message;
            }
        }

        $sectionErrors += EmployeeInput::conditionalErrors($section, $this->form);

        if ($sectionId === 'employment' && EmployeeInput::text($this->form['work_site_id'] ?? '') === '') {
            $sectionErrors['work_site_id'] = 'Assigned Worksite is required.';
        }

        if ($sectionId === 'addresses') {
            $kept = EmployeeFields::keptLegacyAddressError($employee, $this->form);

            if ($kept !== null) {
                $sectionErrors['residential_address'] = $kept;
            }
        }

        $this->formErrors = $sectionErrors;

        if ($sectionErrors !== []) {
            return;
        }

        try {
            if ($this->requiresApproval()) {
                app(SubmitRecordChange::class)->section($access, $sectionId, $this->form);
                $this->cancelEditing();
                $this->dispatch('record-change-submitted');
                Flux::toast(text: 'Change request submitted. Your record stays unchanged until approved.', variant: 'success');

                return;
            }
            $title = app(UpdateEmployee::class)->handle($access, $employee->id, $sectionId, $this->form, $this->acknowledgeObligations, $this->obligationReason);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'That employee could not be saved. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        if ($title === null) {
            return;
        }
        $this->editingSection = null;
        Flux::toast(text: "{$title} saved.", variant: 'success');
    }

    /* ── Portrait ────────────────────────────────────────────────────────── */

    /**
     * Intake per lib/portrait.ts: accept up to 4 MB, but store a ≤512px JPEG
     * redrawn over white with raw GD — never base64 in a column.
     */
    public function updatedPhoto(): void
    {
        if ($this->photo === null) {
            return;
        }
        $access = app(Access::class);
        $employee = $this->employee();
        if ($employee === null) {
            return;
        }
        abort_unless($this->canEdit($access), 403);
        if ($this->requiresApproval()) {
            $this->portraitRemoved = false;

            $this->portraitOpen = true;

            return;
        }
        $upload = $this->photo;
        $this->photo = null;
        try {
            $changed = app(UpdatePortrait::class)->handle($access, $employee->id, $upload);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The photo could not be saved.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        if ($changed) {
            Flux::toast(text: 'Photo updated.', variant: 'success');
        }
    }

    public function stagePortraitRemoval(): void
    {
        $access = app(Access::class);
        abort_unless($this->canEdit($access) && $this->requiresApproval(), 403);
        $this->photo = null;
        $this->portraitRemoved = true;

        $this->portraitOpen = true;
    }

    public function submitPortrait(): void
    {
        $access = app(Access::class);
        abort_unless($this->canEdit($access) && $this->requiresApproval(), 403);
        if ($this->photo === null && ! $this->portraitRemoved) {
            return;
        }
        try {
            app(SubmitRecordChange::class)->portrait($access, $this->photo);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The portrait request could not be submitted. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        $this->photo = null;
        $this->portraitRemoved = false;
        $this->portraitOpen = false;
        $this->dispatch('record-change-submitted');
        Flux::toast(text: 'Portrait change submitted for approval.', variant: 'success');
    }

    public function updatedPortraitOpen(bool $value): void
    {
        if (! $value) {
            $this->photo = null;
            $this->portraitRemoved = false;

        }
    }

    /* ── Delete ──────────────────────────────────────────────────────────── */

    public function deleteEmployee(): void
    {
        $access = app(Access::class);
        abort_unless($this->isHr($access) && ! $this->requiresApproval(), 403);
        try {
            $name = app(DeleteEmployee::class)->handle($access, $this->employeeId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The employee could not be deleted. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        if ($name === null) {
            return;
        }
        $this->deleteConfirmOpen = false;
        Flux::toast(text: "{$name} deleted.", variant: 'success');
        $this->redirect(route('employees.index'));
    }

    /* ── Render ──────────────────────────────────────────────────────────── */

    /** The Personal Data Sheet downloads as an editable workbook from the authorized employee record. */
    public function printPds(): StreamedResponse
    {
        $access = app(Access::class);
        abort_unless(! $this->requiresApproval() && $this->employee() !== null, 403);
        $workbook = app(EmployeePdsWorkbook::class)->download($access, $this->employeeId);

        return response()->streamDownload(static function () use ($workbook): void {
            echo $workbook['content'];
        }, $workbook['filename'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $employee = $this->employee();

        if ($employee === null) {
            return view('livewire.employees.show', ['employee' => null]);
        }

        $this->updatedTab($this->tab);
        if ($this->editingSection !== null && ! $this->canEditSection($access, $this->editingSection)) {
            $this->cancelEditing();
        }
        $requiresApproval = $this->requiresApproval();
        $sites = app(OrganizationDirectory::class)->sites($access);

        // Read-mode display text, precomputed per field key.
        $display = [];
        $editableSections = [];
        $readOnlyFields = [];

        foreach (EmployeeFields::sections() as $section) {
            $editableSections[$section['id']] = $this->canEditSection($access, $section['id']);
            $readOnlyFields[$section['id']] = [];
            foreach ($section['fields'] as $field) {
                $key = (string) $field['key'];
                if ($requiresApproval && ! OwnRecordFields::allowsField($section['id'], $key)) {
                    $readOnlyFields[$section['id']][] = $key;
                }

                if (isset($field['derived'])) {
                    $display[$key] = EmployeeFields::derivedValue($employee, $field);
                } elseif (! empty($field['site'])) {
                    $siteId = $employee->getAttribute($key);
                    $display[$key] = $siteId === null || $siteId === ''
                        ? '—'
                        : ($sites->firstWhere('id', $siteId)->name ?? 'Unknown worksite');
                } else {
                    $display[$key] = EmployeeFields::readValue($employee, $field);
                }
            }
        }

        return view('livewire.employees.show', [
            'employee' => $employee,
            'sections' => EmployeeFields::sections(),
            'tabs' => $this->availableTabs($access),
            'editableSections' => $editableSections,
            'readOnlyFields' => $readOnlyFields,
            'profileLeft' => self::PROFILE_LEFT,
            'profileRight' => self::PROFILE_RIGHT,
            'display' => $display,
            'listTabs' => self::LIST_TABS,
            'canEdit' => $this->canEdit(app(Access::class)),
            'requiresApproval' => $requiresApproval,
            'canPrint' => ! $requiresApproval,
            'canManage' => $this->isHr(app(Access::class)) && ! $this->requiresApproval(),
            'employmentObligations' => $this->editingSection === 'employment' && $this->isHr(app(Access::class)) && ! $this->requiresApproval()
                ? app(EmploymentObligations::class)->forEmployee(app(Access::class), $employee->id) : [],
            // The stored one-line address, citizenship text and spouse while
            // nothing structured replaces them: the Addresses and Spouse
            // cards and the Citizenship field show them as "Entered before".
            'legacy' => [
                'address' => EmployeeFields::legacyAddress($employee),
                'citizenship' => EmployeeFields::legacyCitizenship($employee),
                'spouse' => EmployeeFields::legacySpouse($employee),
            ],
            // The card reads the typed residential parts alone; the stored
            // one-line address appears once, in the note under the card.
            'addressText' => [
                'residential' => EmployeeFields::structuredResidentialText($employee) ?: '—',
                'permanent' => EmployeeFields::permanentText($employee) ?: '—',
            ],
            // The printed sheet's "Area office" line: the worksite's name,
            // blank (never a dash) when the record has none.
            'siteName' => $employee->work_site_id === null ? '' : (string) ($sites->firstWhere('id', $employee->work_site_id)->name ?? ''),
            'accountsCount' => app(AccountLinks::class)->count(app(Access::class), $employee->id),
            'referenceOptions' => [
                'employment_statuses' => app(EmployeeRecords::class)->statuses(app(Access::class)),
            ],
            'sites' => $sites,
            // Read only while Employee Details is open for editing.
            'positionChoices' => $this->editingSection === 'employment' ? PositionPicker::options(app(Access::class), $this->form) : null,
            'photoUrl' => $employee->photo_path
                ? PortraitUrl::for($employee->photo_path)
                : null,
        ]);
    }
}
