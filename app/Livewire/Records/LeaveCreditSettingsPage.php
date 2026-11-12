<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\ModuleCode;
use App\Modules\Timekeeping\Application\Commands\CreateLeaveType;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveRule;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveSignatory;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Application\Queries\ConversionTableRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveRuleRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveSignatoryRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveSignatoryView;
use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView;
use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;
use App\Modules\Timekeeping\Domain\Absence\LeaveSignatorySlot;
use App\Modules\Timekeeping\Domain\Credits\LeaveCashOutLimits;
use App\Modules\Timekeeping\Domain\Credits\LeaveEligibility;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Records › Leave Settings: the company-wide rules of the leave-credits
 * flow, on three tabs. Leave types: THE LEAVE RULES, one per leave type for
 * every employee (its waiting period, what is held on acquiring it, its
 * monthly earning and fallback; changed in place and in force from the day
 * it is saved — no draft, no approval, no version to manage), and each
 * leave type's credit rules (unit, what may be charged to it and in what
 * order, below-zero limit, cash-out, year end). Conversion table: THE
 * minutes-to-days conversion table, shown and NOT EDITABLE — it is fixed
 * (ConversionTables). Signatories: the employees who sign the Application
 * for Leave. Nothing else is set: posting, days without pay and the charge
 * switch follow fixed terms (LeaveRuleBook::policyOn). Nothing is set per
 * employee.
 *
 * Every write re-resolves fresh Access and calls a Timekeeping command,
 * which checks LeaveSettingsAccess::mayManage for itself.
 */
#[Layout('layouts.app')]
#[Title('Leave Settings')]
class LeaveCreditSettingsPage extends Component
{
    /** The Leave Settings catalog: the leave type names this page adds, renames and deletes. */
    private const string CATALOG = 'leave-types';

    /**
     * The tabs, in strip order: the leave types table, the conversion table, then the signatories.
     *
     * @var list<string>
     */
    private const array TABS = ['types', 'table', 'signatories'];

    /** A signatory's draft once HR has asked for a blank line; never an employee id. */
    private const string NO_SIGNATORY = 'NONE';

    /**
     * Which tab is showing. Plain UI state; anything unknown collapses back
     * to the leave types. It rides in the address, so a reload or a shared
     * link opens the same tab.
     */
    #[Url(as: 'tab', except: 'types')]
    public string $tab = 'types';

    /**
     * The signatories being chosen, slot => the employee id, NO_SIGNATORY for
     * a blank line, or '' to leave the slot as it stands. Id-bearing → #[Locked]:
     * only the picker's event and Clear change it.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $signatoryForm = [];

    /** Whether the Signatories tab shows its dropdowns; only Edit, Cancel, a save and leaving the tab change it. */
    #[Locked]
    public bool $signatoriesEditing = false;

    /* ---- Adding, renaming and deleting a leave type ---- */

    public bool $addOpen = false;

    /** The name and description typed in the Add and Rename dialogs; the catalog validates both. */
    public string $newName = '';

    public string $newDescription = '';

    public ?string $addError = null;

    public bool $renameOpen = false;

    /** The leave type the Rename dialog edits. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $renameTypeId = null;

    public bool $deleteOpen = false;

    /** The leave type the delete confirmation is about. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deleteTypeId = null;

    /* ---- Leave type credit rules ---- */

    public bool $typeOpen = false;

    /** The leave type whose rules the dialog edits. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $typeId = null;

    /**
     * The dialog's draft: filing, allowance_days, day_count, earns_credits,
     * unit, charge_late, charge_absence, charge_order, below_zero_limit,
     * can_cash_out, the six cash_out_* limits (blank for none),
     * paid_on_separation, year_end, leave_details, and who may use the type:
     * status_mode (ALL or SOME), statuses (Employment list id => ticked) and
     * sex ('', FEMALE or MALE). SaveLeaveTypeCredits
     * validates all of it.
     *
     * @var array<string, mixed>
     */
    public array $typeForm = [];

    /* ---- A leave type's rule ---- */

    public bool $ruleOpen = false;

    /** The leave type whose rule the dialog changes. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $ruleTypeId = null;

    /**
     * The dialog's form (Support\LeaveRuleForm shape), filled in with the
     * rule in force today.
     *
     * @var array<string, mixed>
     */
    public array $ruleForm = [];

    public ?string $ruleError = null;

    /** A tab named in the address passes the same guard as one picked on the page. */
    public function mount(): void
    {
        $this->updatedTab($this->tab);
    }

    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, self::TABS, true) ? $value : 'types';
        $this->signatoriesEditing = false;
        if ($this->tab === 'signatories') {
            $this->loadSignatories();
        }
    }

    /** Opens the signatories for editing, starting from the slots as they stand. */
    public function editSignatories(): void
    {
        if (LeaveSettingsAccess::mayManage(app(Access::class)) && LeaveSignatoryRecords::employeesReady()) {
            $this->loadSignatories();
            $this->signatoriesEditing = true;
        }
    }

    /** Drops every unsaved choice and goes back to reading the signatories. */
    public function cancelSignatories(): void
    {
        $this->signatoriesEditing = false;
        $this->loadSignatories();
    }

    /**
     * A signatory picked from the list. The event can be sent by the client,
     * so the name, the actor and the employee are all checked again.
     */
    #[On('kit-employee-picked')]
    public function signatoryPicked(string $name, string $id): void
    {
        $access = app(Access::class);
        $slot = str_starts_with($name, 'signatory-') ? substr($name, strlen('signatory-')) : '';
        if (! $this->signatoriesEditing || ! LeaveSignatorySlot::isSlot($slot) || ! LeaveSettingsAccess::mayManage($access)) {
            return;
        }
        $employee = app(OperationalDirectory::class)->find($access, $id);
        if ($employee !== null && $employee->is_active) {
            $this->signatoryForm[$slot] = $employee->id;
        }
    }

    /** Clears a signatory's draft, so saving leaves a blank line on the form. */
    public function clearSignatory(string $slot): void
    {
        if ($this->signatoriesEditing && LeaveSignatorySlot::isSlot($slot) && LeaveSettingsAccess::mayManage(app(Access::class))) {
            $this->signatoryForm[$slot] = self::NO_SIGNATORY;
        }
    }

    /** Saves the signatories of the Application for Leave, each chosen slot through the audited command. */
    public function saveSignatories(): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        $changed = 0;
        try {
            foreach (LeaveSignatorySlot::all() as $slot) {
                $draft = $this->signatoryForm[$slot] ?? '';
                if ($draft !== '' && app(SaveLeaveSignatory::class)->handle($access, $slot, $draft === self::NO_SIGNATORY ? null : $draft)) {
                    $changed++;
                }
            }
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The signatories could not be saved. Please try again.');
            $this->loadSignatories();

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->loadSignatories();

            return;
        }
        $this->loadSignatories();
        $this->signatoriesEditing = false;
        Flux::toast(variant: 'success', text: $changed === 0 ? 'Nothing changed.' : ($changed === 1 ? 'One signatory saved.' : "{$changed} signatories saved."));
    }

    /** The signatories' drafts as the slots stand. */
    private function loadSignatories(): void
    {
        $this->signatoryForm = [];
        foreach (app(LeaveSignatoryRecords::class)->all(app(Access::class)) as $signatory) {
            $this->signatoryForm[$signatory->slot] = $signatory->employeeId;
        }
    }

    /**
     * What each signatory's row shows while it is being chosen: the employee
     * in the picker, the designation that goes with them, and what saving
     * does when the row is not simply showing its saved employee.
     *
     * @param  array<string, LeaveSignatoryView>  $signatories
     * @return array<string, array{picked: string, designation: string, note: string, clearable: bool}>
     */
    private function signatoryRows(Access $access, array $signatories): array
    {
        $rows = [];
        foreach ($signatories as $slot => $signatory) {
            $draft = $this->signatoryForm[$slot] ?? '';
            $saved = $draft !== '' && $draft === $signatory->employeeId;
            $rows[$slot] = match (true) {
                $draft === self::NO_SIGNATORY => ['picked' => '', 'designation' => '', 'note' => 'Saving leaves a blank line to sign by hand.', 'clearable' => false],
                $saved => ['picked' => $draft, 'designation' => $signatory->designation, 'note' => $signatory->employeeActive ? '' : "{$signatory->name} is no longer active. Pick who signs now.", 'clearable' => true],
                $draft !== '' => ['picked' => $draft, 'designation' => trim((string) (app(OperationalDirectory::class)->find($access, $draft)->position ?? '')), 'note' => '', 'clearable' => true],
                $signatory->name !== '' => ['picked' => '', 'designation' => $signatory->designation, 'note' => "The form shows {$signatory->name} until you pick an employee.", 'clearable' => true],
                default => ['picked' => '', 'designation' => '', 'note' => 'The form shows a blank line until you pick an employee.', 'clearable' => false],
            };
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Leave rules */
    /* ------------------------------------------------------------------ */

    /**
     * Opens one leave type's rule, filled in with what is in force today.
     * There is one rule to change, no draft and no version to pick.
     */
    public function openRule(string $typeId): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access) || ! LeaveRuleRecords::ready()) {
            return;
        }
        $type = app(LeaveTypeCreditRecords::class)->list($access)->first(static fn (LeaveTypeView $t): bool => $t->id === $typeId);
        if ($type === null) {
            return;
        }
        $this->ruleTypeId = $type->id;
        $this->ruleError = null;
        $this->ruleForm = Support\LeaveRuleForm::type(app(LeaveRuleRecords::class)->inForce($access)[(string) $type->token]->params ?? []);
        $this->ruleOpen = true;
    }

    /** Saves the dialog's rule: in force from today, for every employee. */
    public function saveRule(): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access) || $this->ruleTypeId === null) {
            return;
        }
        $type = app(LeaveTypeCreditRecords::class)->list($access)->first(fn (LeaveTypeView $t): bool => $t->id === $this->ruleTypeId);
        $credits = $type === null || LeaveTypeCreditRecords::of((string) $type->token)->filedAgainstCredits();
        try {
            $id = app(SaveLeaveRule::class)->handle($access, $this->ruleTypeId, Support\LeaveRuleForm::typeParams($this->ruleForm, $credits));
        } catch (QueryException $e) {
            report($e);
            $this->ruleError = 'The rule could not be saved. Try again.';

            return;
        } catch (RuntimeException $e) {
            $this->ruleError = $e->getMessage();

            return;
        }
        $this->ruleError = null;
        $this->ruleOpen = false;
        Flux::toast(text: $id === null ? 'Nothing changed.' : 'Saved. The rule applies from today.', variant: 'success');
    }

    /** Adds a lookup-table row to the rule dialog's earning. */
    public function addRuleRow(): void
    {
        if (! LeaveSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $this->ruleForm['table'][] = ['up_to' => '', 'credits' => ''];
    }

    public function removeRuleRow(int $index): void
    {
        if (! LeaveSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $rows = $this->ruleForm['table'] ?? [];
        unset($rows[$index]);
        $this->ruleForm['table'] = array_values($rows);
    }

    /* ------------------------------------------------------------------ */
    /* Adding, renaming and deleting a leave type */
    /* ------------------------------------------------------------------ */

    /** Opens the Add leave type dialog: the name, the rule and the settings in one form. */
    public function openAdd(): void
    {
        $access = app(Access::class);
        if (! app(ReferenceCatalogs::class)->for(self::CATALOG)->mayManage($access)) {
            return;
        }
        $this->newName = '';
        $this->newDescription = '';
        $this->addError = null;
        $this->ruleTypeId = null;
        $this->ruleForm = Support\LeaveRuleForm::type([]);
        $this->typeForm = $this->settingsForm(null);
        $this->addOpen = true;
    }

    /** Adds the leave type with its settings and its rule, which applies from today. */
    public function saveNewType(): void
    {
        $access = app(Access::class);
        if (! app(ReferenceCatalogs::class)->for(self::CATALOG)->mayManage($access)) {
            return;
        }
        $credits = ($this->typeForm['filing'] ?? LeaveTypeCredits::FILING_CREDITS) === LeaveTypeCredits::FILING_CREDITS;
        try {
            $created = app(CreateLeaveType::class)->handle($access, $this->newName, $this->newDescription, $this->settingsInput(),
                Support\LeaveRuleForm::touched($this->ruleForm, $credits) ? Support\LeaveRuleForm::typeParams($this->ruleForm, $credits) : null);
        } catch (QueryException $e) {
            report($e);
            $this->addError = 'The leave type could not be added. Check that the name is not already used.';

            return;
        } catch (RuntimeException $e) {
            $this->addError = $e->getMessage();

            return;
        }
        $this->addOpen = false;
        $this->addError = null;
        Flux::toast(text: $created['rule_id'] === null ? 'Leave type added.' : 'Leave type added. Its rule applies from today.', variant: 'success');
    }

    public function openRename(string $typeId): void
    {
        $access = app(Access::class);
        $type = app(LeaveTypeCreditRecords::class)->list($access)->first(static fn (LeaveTypeView $t): bool => $t->id === $typeId);
        if ($type === null || ! app(ReferenceCatalogs::class)->for(self::CATALOG)->mayManage($access)) {
            return;
        }
        $this->renameTypeId = $type->id;
        $this->newName = $type->name;
        $this->newDescription = (string) ($type->description ?? '');
        $this->addError = null;
        $this->renameOpen = true;
    }

    public function saveRename(): void
    {
        $access = app(Access::class);
        $catalog = app(ReferenceCatalogs::class)->for(self::CATALOG);
        if (! $catalog->mayManage($access) || $this->renameTypeId === null) {
            return;
        }
        try {
            $message = app(ReferenceCatalogs::class)->for(self::CATALOG)->save($access, $this->renameTypeId, $this->newName, $this->newDescription);
        } catch (QueryException $e) {
            report($e);
            $this->addError = 'The leave type could not be saved. Check that the name is not already used.';

            return;
        } catch (RuntimeException $e) {
            $this->addError = $e->getMessage();

            return;
        }
        $this->renameOpen = false;
        $this->renameTypeId = null;
        Flux::toast(text: $message, variant: 'success');
    }

    /** Stages a leave type for deletion and opens the confirm dialog. */
    public function askDelete(string $typeId): void
    {
        $access = app(Access::class);
        $type = app(LeaveTypeCreditRecords::class)->list($access)->first(static fn (LeaveTypeView $t): bool => $t->id === $typeId);
        if ($type === null || ! app(ReferenceCatalogs::class)->for(self::CATALOG)->mayManage($access)) {
            return;
        }
        $this->deleteTypeId = $type->id;
        $this->deleteOpen = true;
    }

    /** Deletes the STAGED leave type; the catalog refuses one that credits, requests or rules still name. */
    public function confirmDelete(): void
    {
        $access = app(Access::class);
        $catalog = app(ReferenceCatalogs::class)->for(self::CATALOG);
        $id = $this->deleteTypeId;
        $this->deleteTypeId = null;
        $this->deleteOpen = false;
        if (! $catalog->mayManage($access) || $id === null) {
            return;
        }
        try {
            $message = app(ReferenceCatalogs::class)->for(self::CATALOG)->delete($access, $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The leave type could not be deleted. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        Flux::toast(text: $message, variant: 'success');
    }

    /**
     * The settings dialog's draft for a leave type, or the blank one a new
     * type starts from: filed against earned credits, counted in working
     * days, asking no details of leave, charged for nothing, carried at the
     * year end. How a type is
     * filed is read through the settings reader, so a type set before the
     * column existed shows what it is filed as.
     *
     * @return array<string, mixed>
     */
    private function settingsForm(?LeaveTypeView $type): array
    {
        $settings = $type === null ? null : LeaveTypeCreditRecords::of((string) $type->token);
        $allowance = $settings?->allowanceDays;

        return [
            'filing' => $settings->filing ?? LeaveTypeCredits::FILING_CREDITS,
            'allowance_days' => $allowance === null ? '' : LeaveQuantity::format($allowance),
            'day_count' => $settings->dayCount ?? LeaveTypeCredits::DAYS_WORKING,
            'earns_credits' => (bool) ($type->earns_credits ?? false),
            'unit' => $type->unit ?? LeaveTypeCredits::UNIT_DAYS,
            'charge_late' => (bool) ($type->charge_late ?? false),
            'charge_absence' => (bool) ($type->charge_absence ?? false),
            'charge_order' => (string) ($type->charge_order ?? 0),
            'below_zero_limit' => LeaveQuantity::credits((float) ($type->below_zero_limit ?? 0.0)),
            'can_cash_out' => (bool) ($type->can_cash_out ?? false),
            'year_end' => (string) ($type->year_end ?? LeaveTypeCredits::YEAR_END_CARRY),
            'leave_details' => $settings->leaveDetails ?? LeaveApplication::NONE,
            ...($settings->cashOutLimits ?? new LeaveCashOutLimits)->toForm(),
            'paid_on_separation' => (bool) ($settings->paidOnSeparation ?? false),
            // Who may use it: the ticked Employment list ids, and a sex or ''.
            'status_mode' => ($settings?->eligibility->statuses ?? null) === null ? LeaveEligibility::STATUS_ALL : LeaveEligibility::STATUS_SOME,
            'statuses' => array_fill_keys($settings?->eligibility->statuses ?? [], true),
            'sex' => $settings?->eligibility->sex ?? '',
        ];
    }

    /** Whether the draft limits the type to chosen statuses and has ticked none. */
    private function choosesNoStatus(): bool
    {
        return ($this->typeForm['status_mode'] ?? LeaveEligibility::STATUS_ALL) === LeaveEligibility::STATUS_SOME
            && array_filter((array) ($this->typeForm['statuses'] ?? [])) === [];
    }

    /**
     * The Employment list ids ticked in the draft that are still on the
     * list: a status deleted since is dropped on save, as Incentive types
     * do. SaveLeaveTypeCredits re-resolves each one.
     *
     * @return list<string>
     */
    private function draftStatuses(Access $access): array
    {
        $known = array_column(app(ReferenceCatalogs::class)->for('employment')->options($access), 'id');
        $ticked = array_keys(array_filter((array) ($this->typeForm['statuses'] ?? [])));

        return array_values(array_filter(array_map(strval(...), $ticked), static fn (string $id): bool => in_array($id, $known, true)));
    }

    /**
     * The settings as SaveLeaveTypeCredits takes them, from the draft.
     *
     * @return array<string, mixed>
     */
    private function settingsInput(): array
    {
        return [
            'filing' => (string) ($this->typeForm['filing'] ?? LeaveTypeCredits::FILING_CREDITS),
            'allowance_days' => (string) ($this->typeForm['allowance_days'] ?? ''),
            'day_count' => (string) ($this->typeForm['day_count'] ?? LeaveTypeCredits::DAYS_WORKING),
            'earns_credits' => (bool) ($this->typeForm['earns_credits'] ?? false),
            'unit' => (string) ($this->typeForm['unit'] ?? ''),
            'charge_late' => (bool) ($this->typeForm['charge_late'] ?? false),
            'charge_absence' => (bool) ($this->typeForm['charge_absence'] ?? false),
            'charge_order' => (string) ($this->typeForm['charge_order'] ?? ''),
            'below_zero_limit' => (string) ($this->typeForm['below_zero_limit'] ?? ''),
            'can_cash_out' => (bool) ($this->typeForm['can_cash_out'] ?? false),
            'year_end' => (string) ($this->typeForm['year_end'] ?? LeaveTypeCredits::YEAR_END_CARRY),
            'leave_details' => (string) ($this->typeForm['leave_details'] ?? LeaveApplication::NONE),
            ...array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', array_intersect_key($this->typeForm, array_flip([...LeaveCashOutLimits::DAY_KEYS, 'cash_out_per_year', 'cash_out_reason_share']))),
            'paid_on_separation' => (bool) ($this->typeForm['paid_on_separation'] ?? false),
            'status_mode' => (string) ($this->typeForm['status_mode'] ?? LeaveEligibility::STATUS_ALL),
            'statuses' => $this->draftStatuses(app(Access::class)),
            'sex' => (string) ($this->typeForm['sex'] ?? ''),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Leave type credit rules */
    /* ------------------------------------------------------------------ */

    public function openType(string $typeId): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access) || ! LeaveTypeCreditRecords::ready()) {
            return;
        }
        $type = app(LeaveTypeCreditRecords::class)->list($access)->first(static fn (LeaveTypeView $t): bool => $t->id === $typeId);
        if ($type === null) {
            return;
        }
        $this->typeId = $type->id;
        $this->typeForm = $this->settingsForm($type);
        $this->typeOpen = true;
    }

    public function saveType(): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access) || $this->typeId === null) {
            return;
        }
        try {
            $message = app(SaveLeaveTypeCredits::class)->handle($access, $this->typeId, $this->settingsInput());
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The rules could not be saved. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        $this->typeOpen = false;
        $this->typeId = null;
        Flux::toast(text: $message, variant: 'success');
    }

    /**
     * The warning the open settings or Add dialog shows under Deduction
     * priority (the charge order) while its draft would share a number with
     * another charged type, or
     * null. Feedback only: SaveLeaveTypeCredits refuses such a save itself.
     *
     * @param  array<string, array{name: string, rules: LeaveTypeCredits}>  $settings  the saved settings, by token
     * @param  Collection<int, LeaveTypeView>  $types
     */
    private function draftOrderClash(array $settings, Collection $types): ?string
    {
        if (! $this->typeOpen && ! $this->addOpen) {
            return null;
        }
        $input = $this->settingsInput();
        if (LeaveTypeCredits::error($input) !== null) {
            return null;
        }
        $draft = LeaveTypeCredits::fromInput($input);
        if (! $draft->charged()) {
            return null;
        }
        // The type being edited is left out of the others; a type being added is not among them yet.
        $editing = $this->typeOpen ? (string) ($types->first(fn (LeaveTypeView $t): bool => $t->id === $this->typeId)->token ?? '') : '';
        unset($settings[$editing]);
        $settings[''] = ['name' => '', 'rules' => $draft];
        $clash = LeaveTypeCredits::orderClashes($settings)[''] ?? [];

        return $clash === [] ? null : LeaveTypeCredits::orderClashText($draft->chargeOrder, $clash);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $canManage = LeaveSettingsAccess::mayManage($access);
        $today = ManilaClock::todayKey();
        $types = app(LeaveTypeCreditRecords::class)->list($access);
        $ruleRecords = app(LeaveRuleRecords::class);
        $ruleType = $this->ruleTypeId === null ? null : $types->first(fn (LeaveTypeView $t): bool => $t->id === $this->ruleTypeId);
        // How each type is filed, and whether the type a rule is drawn for
        // holds credits: the Add dialog follows its own draft, the rule
        // dialog the saved type. Only such a type earns or falls back.
        $settings = LeaveTypeCreditRecords::rules();
        $statusOptions = app(ReferenceCatalogs::class)->for('employment')->options($access);
        $creditTokens = array_keys(array_filter($settings, static fn (array $entry): bool => $entry['rules']->filedAgainstCredits()));
        $ruleCredits = $this->addOpen
            ? ($this->typeForm['filing'] ?? LeaveTypeCredits::FILING_CREDITS) === LeaveTypeCredits::FILING_CREDITS
            : ($ruleType === null || LeaveTypeCreditRecords::of((string) $ruleType->token)->filedAgainstCredits());
        $signatories = $this->tab === 'signatories' ? app(LeaveSignatoryRecords::class)->all($access) : [];

        return view('livewire.records.leave-credit-settings-page', [
            'rulesReady' => LeaveRuleRecords::ready(),
            'rules' => $ruleRecords->inForce($access),
            'ruleName' => (string) ($ruleType->name ?? ''),
            'ruleToken' => (string) ($ruleType->token ?? ''),
            'ruleCredits' => $ruleCredits,
            'creditTokens' => $creditTokens,
            'settings' => array_map(static fn (array $entry): LeaveTypeCredits => $entry['rules'], $settings),
            // Types already sharing a charge order (the saved settings), and the open dialog's draft against the others.
            'orderClashes' => LeaveTypeCredits::orderClashes($settings),
            'orderClash' => $this->draftOrderClash($settings, $types),
            'canManage' => $canManage,
            'canEditTypes' => app(ReferenceCatalogs::class)->for(self::CATALOG)->mayManage($access),
            'deleteTypeName' => $this->deleteTypeId === null ? '' : (string) ($types->first(fn (LeaveTypeView $t): bool => $t->id === $this->deleteTypeId)->name ?? ''),
            'today' => $today,
            'typesReady' => LeaveTypeCreditRecords::ready(),
            // The details-of-leave setting, once its column exists (2026_11_08_0002).
            'detailsReady' => LeaveTypeCreditRecords::detailsReady(),
            'cashOutLimitsReady' => LeaveTypeCreditRecords::cashOutLimitsReady(),
            'types' => $types,
            // Who may use each type: the Employment list for the table and
            // the dialogs, and the open draft's reach today (counts only).
            'eligibilityReady' => LeaveTypeCreditRecords::eligibilityReady(),
            'statusOptions' => $statusOptions,
            'statusNames' => array_column($statusOptions, 'name', 'id'),
            // None while "Only the chosen statuses" has nothing ticked: the
            // save refuses that, and the dialog asks for a tick instead.
            'eligibilityReach' => ($this->typeOpen || $this->addOpen) && LeaveTypeCreditRecords::eligibilityReady() && ! $this->choosesNoStatus()
                ? $ruleRecords->reach($access, LeaveEligibility::fromInput($this->settingsInput()))
                : null,
            'typeName' => $this->typeId === null ? '' : (string) ($types->first(fn (LeaveTypeView $t): bool => $t->id === $this->typeId)->name ?? ''),
            // Only the tab in view reads the table; it is fixed, so there is nothing else to show.
            'table' => $this->tab === 'table' ? app(ConversionTableRecords::class)->table($access) : null,
            'signatories' => $signatories,
            'signatoryRows' => $canManage && $this->signatoriesEditing ? $this->signatoryRows($access, $signatories) : [],
            'signatoriesReady' => LeaveSignatoryRecords::employeesReady(),
            'mayOpenDepartments' => $access->hasModule(ModuleCode::RECORDS),
        ]);
    }
}
