<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Payroll\Support\OpensEmployeePaySettings;
use App\Livewire\Records\Support\BenefitText;
use App\Livewire\Records\Support\EmployeePayProfile;
use App\Livewire\Records\Support\EmployeePaySettingsRows;
use App\Livewire\Records\Support\EmployeeRates;
use App\Livewire\Records\Support\Hris;
use App\Modules\Payroll\Application\BenefitAssignments;
use App\Modules\Payroll\Application\Commands\ApproveBenefitAssignment;
use App\Modules\Payroll\Application\Commands\ApproveRecurringLineEntry;
use App\Modules\Payroll\Application\Commands\ApproveTaxBalance;
use App\Modules\Payroll\Application\Commands\DeleteRecurringLine;
use App\Modules\Payroll\Application\Commands\DiscardBenefitAssignment;
use App\Modules\Payroll\Application\Commands\DiscardRecurringLineEntry;
use App\Modules\Payroll\Application\Commands\DiscardTaxBalance;
use App\Modules\Payroll\Application\Commands\EndRecurringLine;
use App\Modules\Payroll\Application\Commands\ReplaceGovDeductionPolicy;
use App\Modules\Payroll\Application\Commands\SaveBenefitAssignment;
use App\Modules\Payroll\Application\Commands\SaveRecurringLine;
use App\Modules\Payroll\Application\Commands\SaveRecurringLineEntry;
use App\Modules\Payroll\Application\Commands\SaveTaxBalance;
use App\Modules\Payroll\Application\GovDeductionInput;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\Queries\BenefitAssignmentRecords;
use App\Modules\Payroll\Application\Queries\GovDeductionPolicyRecords;
use App\Modules\Payroll\Application\Queries\IncentiveTypeRecords;
use App\Modules\Payroll\Application\Queries\PayRuleRecords;
use App\Modules\Payroll\Application\Queries\RecurringLineEntryRecords;
use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
use App\Modules\Payroll\Application\Queries\TaxYearRecords;
use App\Modules\Payroll\Application\RecurringLineEntries;
use App\Modules\Payroll\Application\RecurringLinePayoff;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Modules\Payroll\Contracts\Views\BenefitAssignmentView;
use App\Modules\Payroll\Contracts\Views\GovDeductionPolicyView;
use App\Modules\Payroll\Contracts\Views\IncentiveTypeView;
use App\Modules\Payroll\Contracts\Views\PayRuleView;
use App\Modules\Payroll\Contracts\Views\RecurringLineEntryView;
use App\Modules\Payroll\Contracts\Views\RecurringLineView;
use App\Modules\Payroll\Contracts\Views\TaxBalanceView;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Modules\Workforce\Application\Commands\ReplaceSalary;
use App\Modules\Workforce\Application\Commands\UpdateEmployeePaySettings;
use App\Modules\Workforce\Application\EmployeePaySettingsInput;
use App\Modules\Workforce\Application\Queries\EmployeePaySettingsRecords;
use App\Modules\Workforce\Application\SalaryRates;
use App\Modules\Workforce\Contracts\Views\EmployeePaySettingsView;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * One employee's pay on one page, under Records → Payroll Settings →
 * Employees, each figure marked with where it comes from (EmployeePayProfile).
 * Pay at a glance shows their salary, changed here (ReplaceSalary), the day
 * and the hour those make, and what prices their days and hours today —
 * rates of their own, their status's override or the company rule —
 * opening Pay Basis & Rates for them; its hourly rate override is edited
 * here in place, with a reason (UpdateEmployeePaySettings). Contributions
 * & tax reads the settings in force as payroll applies them: per
 * contribution whether it is deducted, a fixed employee or employer share
 * in place of the table, and how it falls across the cut-offs; how
 * withholding is worked out; and each percentage Payroll Deduction, at the
 * employee's own rate when they have one. A save replaces them
 * (ReplaceGovDeductionPolicy; "Reset to standard" fills the form with
 * the standard computation and saves nothing). Allowances & incentives
 * lists, in one table, the company benefits they qualify for, the
 * incentives assigned to them and their own recurring allowances, and
 * Loans & other deductions their own recurring deductions, each running
 * loan with when it is paid off; both add, change, end and delete the
 * employee's own lines (SaveRecurringLine, EndRecurringLine,
 * DeleteRecurringLine), the dialog's kind fixed by the card it opened from.
 * The employee search in the head opens another employee's page.
 *
 * The form keys are GovDeductionInput::toForm()'s and
 * EmployeePaySettingsInput::toForm()'s; the commands read and check them
 * again, so a tampered form is refused there.
 */
#[Layout('layouts.app')]
class EmployeePaySettingsShow extends Component
{
    use OpensEmployeePaySettings;

    /** The employee on screen, from the route. Id-bearing → #[Locked]. */
    #[Locked]
    public string $employeeId = '';

    /** The Contributions & tax card is preparing a version. Plain UI state. */
    public bool $editing = false;

    /** Pay at a glance is editing the hourly rate. Plain UI state. */
    public bool $hourlyEditing = false;

    /**
     * The version form, GovDeductionInput::toForm() shape. Client-editable:
     * the command re-checks every value.
     *
     * @var array<string, mixed>
     */
    public array $form = [];

    /** The save's inline error, cleared by the next edit. */
    public ?string $formError = null;

    /** The hourly rate override, as typed. */
    public string $hourlyRate = '';

    public string $hourlyReason = '';

    public ?string $hourlyError = null;

    /** The Assign incentive dialog. Plain UI state. */
    public bool $assignOpen = false;

    /**
     * The assignment as typed: the type, the dates, the amount, the reason
     * and the reference. The type id is a wire:model draft that
     * SaveBenefitAssignment re-resolves, so it is not #[Locked].
     *
     * @var array<string, string>
     */
    public array $assignForm = ['type_id' => '', 'starts_on' => '', 'ends_on' => '', 'amount' => '', 'reason' => '', 'reference' => ''];

    public ?string $assignError = null;

    /** The Record loan entry dialog. Plain UI state. */
    public bool $entryOpen = false;

    /** The line an entry is being recorded on. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $entryLineId = null;

    /**
     * The entry as typed: the kind, the amount, the date, the reason and
     * the reference. No id in it, so not #[Locked]; SaveRecurringLineEntry
     * re-validates every field.
     *
     * @var array<string, string>
     */
    public array $entryForm = ['kind' => 'OUTSIDE_PAYMENT', 'amount' => '', 'entry_date' => '', 'reason' => '', 'reference' => ''];

    public ?string $entryError = null;

    /** The Record tax balance dialog. Plain UI state. */
    public bool $balanceOpen = false;

    /**
     * The balance as typed: the year, the kind, the two amounts, the
     * reason and the reference. No id in it, so not #[Locked];
     * SaveTaxBalance re-validates every field.
     *
     * @var array<string, string>
     */
    public array $balanceForm = ['tax_year' => '', 'kind' => 'OPENING', 'taxable_income' => '', 'tax_withheld' => '', 'reason' => '', 'reference' => ''];

    public ?string $balanceError = null;

    /** The Change salary dialog. Plain UI state. */
    public bool $salaryOpen = false;

    /**
     * The salary as typed: the two rates. Free text — no id in it, so not
     * #[Locked]; ReplaceSalary re-validates both.
     *
     * @var array<string, string>
     */
    public array $salaryForm = ['monthly' => '', 'daily' => ''];

    public ?string $salaryError = null;

    /** The recurring line dialog's visibility. Plain UI state. */
    public bool $lineOpen = false;

    /** The recurring line being edited, or null when adding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $lineId = null;

    public string $lineKind = RecurringLine::KIND_DEDUCTION;

    /**
     * The catalog row the line charges. Not #[Locked]: the select binds it,
     * and SaveRecurringLine re-checks it against the catalog (a sanctioned
     * wire:model draft).
     */
    public string $lineTypeId = '';

    public string $lineAmount = '';

    public string $lineTotal = '';

    public string $lineFrequency = RecurringLine::FREQUENCY_EVERY;

    public string $lineStartsOn = '';

    public string $lineEndsOn = '';

    public string $lineNote = '';

    /** The line dialog's inline error. */
    public ?string $lineError = null;

    /** The end dialog's visibility. Plain UI state. */
    public bool $endOpen = false;

    /** The line being ended. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $endingId = null;

    public string $endLastDay = '';

    public string $endReason = '';

    public ?string $endError = null;

    /** The delete confirmation's visibility. Plain UI state. */
    public bool $deleteOpen = false;

    /** The line queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    public function mount(string $employee): void
    {
        $this->employeeId = $employee;
    }

    /** Any edit clears the last refusal. */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form')) {
            $this->formError = null;
        }
        if (str_starts_with($name, 'hourly')) {
            $this->hourlyError = null;
        }
        if (str_starts_with($name, 'line')) {
            $this->lineError = null;
        }
        if (str_starts_with($name, 'end')) {
            $this->endError = null;
        }
    }

    /** A deduction and an incentive charge different catalogs, and only a deduction has a total. */
    public function updatedLineKind(): void
    {
        $this->lineTypeId = '';
        if ($this->lineKind !== RecurringLine::KIND_DEDUCTION) {
            $this->lineTotal = '';
        }
    }

    /**
     * Opens the line dialog for a new line of the card it was opened from:
     * a loan or deduction from Loans & other deductions, an allowance from
     * Allowances & benefits. Anything else is a deduction.
     */
    public function addLine(string $kind = RecurringLine::KIND_DEDUCTION): void
    {
        $this->lineId = null;
        $this->lineKind = in_array($kind, RecurringLine::KINDS, true) ? $kind : RecurringLine::KIND_DEDUCTION;
        $this->lineTypeId = '';
        $this->lineAmount = '';
        $this->lineTotal = '';
        $this->lineFrequency = RecurringLine::FREQUENCY_EVERY;
        $this->lineStartsOn = self::nextCutoffStart(ManilaClock::todayKey());
        $this->lineEndsOn = '';
        $this->lineNote = '';
        $this->lineError = null;
        $this->lineOpen = true;
    }

    public function editLine(string $id): void
    {
        $line = $this->ownLine($id);
        if ($line === null) {
            return;
        }
        $this->lineId = $line->id;
        $this->lineKind = $line->kind;
        $this->lineTypeId = $line->type_id;
        $this->lineAmount = Hris::moneyText($line->amount);
        $this->lineTotal = $line->total === null ? '' : Hris::moneyText($line->total);
        $this->lineFrequency = $line->frequency;
        $this->lineStartsOn = $line->starts_on->format('Y-m-d');
        $this->lineEndsOn = $line->ends_on?->format('Y-m-d') ?? '';
        $this->lineNote = (string) $line->note;
        $this->lineError = null;
        $this->lineOpen = true;
    }

    public function saveLine(): void
    {
        $access = app(Access::class);
        $figures = [
            'kind' => $this->lineKind,
            'amount' => self::number($this->lineAmount),
            'total' => trim($this->lineTotal) === '' ? null : self::number($this->lineTotal),
            'frequency' => $this->lineFrequency,
            'starts_on' => trim($this->lineStartsOn),
            'ends_on' => trim($this->lineEndsOn) === '' ? null : trim($this->lineEndsOn),
        ];
        if (trim($this->lineTotal) !== '' && $figures['total'] === null) {
            $this->lineError = 'Enter the total loan payable as a peso figure, or leave it blank.';

            return;
        }
        try {
            $message = app(SaveRecurringLine::class)->handle($access, $this->lineId, $this->employeeId, $this->lineTypeId, $figures, $this->lineNote);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'It could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->lineError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->lineOpen = false;
        $this->lineId = null;
    }

    public function startEnd(string $id): void
    {
        $line = $this->ownLine($id);
        if ($line === null) {
            return;
        }
        if ($line->status === RecurringLineRecords::ENDED) {
            Flux::toast(variant: 'danger', text: 'That loan or allowance has already ended. Edit it to change its dates.');

            return;
        }
        $this->endingId = $line->id;
        $this->endLastDay = ManilaClock::todayKey();
        $this->endReason = '';
        $this->endError = null;
        $this->endOpen = true;
    }

    public function endLine(): void
    {
        $access = app(Access::class);
        if ($this->endingId === null) {
            return;
        }
        try {
            $message = app(EndRecurringLine::class)->handle($access, $this->endingId, trim($this->endLastDay), $this->endReason);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'It could not be ended. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->endError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->endOpen = false;
        $this->endingId = null;
    }

    public function confirmDeleteLine(string $id): void
    {
        $line = $this->ownLine($id);
        if ($line === null) {
            return;
        }
        $this->deletingId = $line->id;
        $this->deleteOpen = true;
    }

    /**
     * One of this page's employee's recurring lines, read with fresh access;
     * any other id toasts and gives null, so a line of another employee is
     * never staged for an edit, an end or a delete from this page.
     */
    private function ownLine(string $id): ?RecurringLineView
    {
        $access = app(Access::class);
        $line = $access->mayReadAllData(ModuleCode::PAYROLL) ? app(RecurringLineRecords::class)->find($access, $id, ManilaClock::todayKey()) : null;
        if ($line === null || $line->employee_id !== $this->employeeId) {
            Flux::toast(variant: 'danger', text: 'That loan or allowance no longer exists.');

            return null;
        }

        return $line;
    }

    public function deleteLine(): void
    {
        $id = $this->deletingId;
        $this->deleteOpen = false;
        $this->deletingId = null;
        if ($id === null) {
            return;
        }
        $access = app(Access::class);
        try {
            $message = app(DeleteRecurringLine::class)->handle($access, $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'It could not be deleted. It may have been paid meanwhile.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    /* ---- Contributions & tax ---- */

    /** Opens the form on the settings in use: the employee's own, else the baseline. */
    public function startEdit(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $settings = app(EmployeePaySettingsRecords::class)->find($access, $this->employeeId);
        if ($settings === null) {
            Flux::toast(variant: 'danger', text: 'That employee no longer exists.');

            return;
        }
        $today = ManilaClock::todayKey();
        $versions = app(GovDeductionPolicyRecords::class)->versions($access, $this->employeeId);
        $inForce = self::govInForce($versions, $today);
        $this->form = GovDeductionInput::toForm(EmployeePaySettingsRows::effective($settings, $inForce), array_keys($this->percentRows($access, $settings)));
        $this->formError = null;
        $this->editing = true;
        $this->hourlyEditing = false;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->hourlyEditing = false;
        $this->formError = null;
        $this->hourlyError = null;
    }

    /** The standard computation, into the form: nothing is saved until Save. */
    public function useStandard(): void
    {
        $access = app(Access::class);
        $settings = $access->mayReadAllData(ModuleCode::PAYROLL) ? app(EmployeePaySettingsRecords::class)->find($access, $this->employeeId) : null;
        $this->form = GovDeductionInput::toForm(GovDeductionSettings::standard(), $settings === null ? [] : array_keys($this->percentRows($access, $settings)));
        $this->formError = null;
    }

    /** Replaces the employee's settings through the audited command; they apply at once. */
    public function save(): void
    {
        $access = app(Access::class);
        $settings = $access->mayReadAllData(ModuleCode::PAYROLL) ? app(EmployeePaySettingsRecords::class)->find($access, $this->employeeId) : null;
        if ($settings === null) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage employee pay settings.');

            return;
        }
        $figures = GovDeductionInput::figures($this->form, array_keys($this->percentRows($access, $settings)));
        if (is_string($figures)) {
            $this->formError = $figures;

            return;
        }
        try {
            $message = app(ReplaceGovDeductionPolicy::class)->handle($access, $this->employeeId, $figures);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The settings could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->editing = false;
    }

    /* ---- Assigned benefits ---- */

    /** Opens the Assign dialog with today as the start: the earliest an assignment reaches a payroll. */
    public function openAssign(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $this->assignForm = ['type_id' => '', 'starts_on' => ManilaClock::todayKey(), 'ends_on' => '', 'amount' => '', 'reason' => '', 'reference' => ''];
        $this->assignError = null;
        $this->assignOpen = true;
    }

    public function updatedAssignOpen(bool $value): void
    {
        if (! $value) {
            $this->assignError = null;
        }
    }

    /** Saves the assignment as a draft through the audited command. */
    public function saveAssignment(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage employee pay settings.');

            return;
        }
        try {
            $message = app(SaveBenefitAssignment::class)->handle(
                $access, $this->employeeId, (string) ($this->assignForm['type_id'] ?? ''),
                (string) ($this->assignForm['starts_on'] ?? ''), (string) ($this->assignForm['ends_on'] ?? ''),
                self::money((string) ($this->assignForm['amount'] ?? '')),
                (string) ($this->assignForm['reason'] ?? ''), (string) ($this->assignForm['reference'] ?? ''),
            );
        } catch (QueryException $e) {
            report($e);
            $this->assignError = 'The assignment could not be saved. Nothing was changed. Please try again.';

            return;
        } catch (RuntimeException $e) {
            $this->assignError = $e->getMessage();

            return;
        }
        $this->assignOpen = false;
        $this->assignError = null;
        $applied = $this->applyDrafts($access, app(BenefitAssignmentRecords::class)->forEmployee($access, $this->employeeId), ApproveBenefitAssignment::class);
        Flux::toast(variant: 'success', text: $applied ?? $message);
    }

    /**
     * A SAVE TAKES EFFECT AT ONCE: there is no approval step on this page.
     * The owning commands still write a draft and approve it as two
     * operations, each under its own lock, so the save is followed here by
     * the approval of every row of this employee still in draft — the one
     * just saved, and any left from before. The message of the last
     * approval is returned, or null when nothing was a draft; a refusal is
     * shown and leaves the row a draft, with its Approve item in the menu.
     *
     * @param  iterable<BenefitAssignmentView|RecurringLineEntryView|TaxBalanceView>  $rows  this employee's rows
     * @param  class-string  $command  the approve command, `handle(Access, string $id): string`
     */
    private function applyDrafts(Access $access, iterable $rows, string $command): ?string
    {
        $message = null;
        foreach ($rows as $row) {
            if ($row->status !== 'DRAFT') {
                continue;
            }
            try {
                $message = app($command)->handle($access, (string) $row->id);
            } catch (QueryException $e) {
                report($e);
                Flux::toast(variant: 'danger', text: 'It was saved but could not take effect. Open its menu and choose Approve.');
            } catch (RuntimeException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());
            }
        }

        return $message;
    }

    /** Approves a drafted assignment: the command refuses the preparer and the employee's own account. */
    public function approveAssignment(string $id): void
    {
        $this->decideAssignment($id, ApproveBenefitAssignment::class);
    }

    /** Deletes a draft that should not be paid. */
    public function discardAssignment(string $id): void
    {
        $this->decideAssignment($id, DiscardBenefitAssignment::class);
    }

    /** @param  class-string<ApproveBenefitAssignment|DiscardBenefitAssignment>  $command */
    private function decideAssignment(string $id, string $command): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage employee pay settings.');

            return;
        }
        $row = app(BenefitAssignmentRecords::class)->forEmployee($access, $this->employeeId)
            ->first(static fn (BenefitAssignmentView $v): bool => $v->id === $id);
        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That assignment no longer exists.');

            return;
        }
        try {
            $message = app($command)->handle($access, $row->id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The assignment could not be updated. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    /* ---- Loan entries ---- */

    /** Opens the entry dialog on one of the employee's loans, dated today. */
    public function openEntry(string $lineId): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $line = app(RecurringLineRecords::class)->find($access, $lineId, ManilaClock::todayKey());
        if ($line === null || $line->employee_id !== $this->employeeId || $line->total === null) {
            Flux::toast(variant: 'danger', text: 'Entries are recorded only on a deduction with a total loan payable.');

            return;
        }
        $this->entryLineId = $line->id;
        $this->entryForm = ['kind' => 'OUTSIDE_PAYMENT', 'amount' => '', 'entry_date' => ManilaClock::todayKey(), 'reason' => '', 'reference' => ''];
        $this->entryError = null;
        $this->entryOpen = true;
    }

    public function updatedEntryOpen(bool $value): void
    {
        if (! $value) {
            $this->entryError = null;
            $this->entryLineId = null;
        }
    }

    /** Saves the entry as a draft through the audited command. */
    public function saveEntry(): void
    {
        $access = app(Access::class);
        $lineId = $this->entryLineId;
        if (! $access->mayReadAllData(ModuleCode::PAYROLL) || $lineId === null) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage employee pay settings.');

            return;
        }
        try {
            $message = app(SaveRecurringLineEntry::class)->handle(
                $access, $lineId, (string) ($this->entryForm['kind'] ?? ''), self::money((string) ($this->entryForm['amount'] ?? '')),
                (string) ($this->entryForm['entry_date'] ?? ''), (string) ($this->entryForm['reason'] ?? ''), (string) ($this->entryForm['reference'] ?? ''),
            );
        } catch (QueryException $e) {
            report($e);
            $this->entryError = 'The entry could not be saved. Nothing was changed. Please try again.';

            return;
        } catch (RuntimeException $e) {
            $this->entryError = $e->getMessage();

            return;
        }
        $this->entryOpen = false;
        $this->entryLineId = null;
        $this->entryError = null;
        $applied = $this->applyDrafts($access, app(RecurringLineEntryRecords::class)->forEmployee($access, $this->employeeId), ApproveRecurringLineEntry::class);
        Flux::toast(variant: 'success', text: $applied ?? $message);
    }

    /** Approves a drafted entry: the command refuses the preparer and the employee's own account. */
    public function approveEntry(string $id): void
    {
        $this->decideEntry($id, ApproveRecurringLineEntry::class);
    }

    /** Deletes a draft that should not count. */
    public function discardEntry(string $id): void
    {
        $this->decideEntry($id, DiscardRecurringLineEntry::class);
    }

    /** @param  class-string<ApproveRecurringLineEntry|DiscardRecurringLineEntry>  $command */
    private function decideEntry(string $id, string $command): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage employee pay settings.');

            return;
        }
        $row = app(RecurringLineEntryRecords::class)->forEmployee($access, $this->employeeId)
            ->first(static fn (RecurringLineEntryView $v): bool => $v->id === $id);
        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That entry no longer exists.');

            return;
        }
        try {
            $message = app($command)->handle($access, $row->id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The entry could not be updated. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    /* ---- The tax year ---- */

    /** Opens the balance dialog on the current tax year. */
    public function openBalance(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $this->balanceForm = ['tax_year' => substr(ManilaClock::todayKey(), 0, 4), 'kind' => 'OPENING', 'taxable_income' => '', 'tax_withheld' => '', 'reason' => '', 'reference' => ''];
        $this->balanceError = null;
        $this->balanceOpen = true;
    }

    public function updatedBalanceOpen(bool $value): void
    {
        if (! $value) {
            $this->balanceError = null;
        }
    }

    /** Saves the balance as a draft through the audited command. */
    public function saveBalance(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage employee pay settings.');

            return;
        }
        try {
            $message = app(SaveTaxBalance::class)->handle(
                $access, $this->employeeId, (string) ($this->balanceForm['tax_year'] ?? ''), (string) ($this->balanceForm['kind'] ?? ''),
                self::money((string) ($this->balanceForm['taxable_income'] ?? '')), self::money((string) ($this->balanceForm['tax_withheld'] ?? '')),
                (string) ($this->balanceForm['reason'] ?? ''), (string) ($this->balanceForm['reference'] ?? ''),
            );
        } catch (QueryException $e) {
            report($e);
            $this->balanceError = 'The balance could not be saved. Nothing was changed. Please try again.';

            return;
        } catch (RuntimeException $e) {
            $this->balanceError = $e->getMessage();

            return;
        }
        $this->balanceOpen = false;
        $this->balanceError = null;
        $applied = $this->applyDrafts($access, app(TaxYearRecords::class)->balances($access, $this->employeeId), ApproveTaxBalance::class);
        Flux::toast(variant: 'success', text: $applied ?? $message);
    }

    /** Approves a drafted balance: the command refuses the preparer and the employee's own account. */
    public function approveBalance(string $id): void
    {
        $this->decideBalance($id, ApproveTaxBalance::class);
    }

    /** Deletes a draft that should not count. */
    public function discardBalance(string $id): void
    {
        $this->decideBalance($id, DiscardTaxBalance::class);
    }

    /** @param  class-string<ApproveTaxBalance|DiscardTaxBalance>  $command */
    private function decideBalance(string $id, string $command): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage employee pay settings.');

            return;
        }
        $row = app(TaxYearRecords::class)->balances($access, $this->employeeId)
            ->first(static fn (TaxBalanceView $v): bool => $v->id === $id);
        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That balance no longer exists.');

            return;
        }
        try {
            $message = app($command)->handle($access, $row->id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The balance could not be updated. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    /* ---- The salary ---- */

    /** Opens the dialog on the salary in use. */
    public function openSalaryChange(): void
    {
        $access = app(Access::class);
        $settings = $access->mayReadAllData(ModuleCode::PAYROLL) ? app(EmployeePaySettingsRecords::class)->find($access, $this->employeeId) : null;
        if ($settings === null) {
            return;
        }
        $this->salaryForm = [
            'monthly' => (float) ($settings->monthly_rate ?? 0) > 0.0 ? Hris::moneyText($settings->monthly_rate) : '',
            'daily' => $settings->daily_rate === null ? '' : Hris::moneyText($settings->daily_rate),
        ];
        $this->salaryError = null;
        $this->salaryOpen = true;
    }

    public function updatedSalaryOpen(bool $value): void
    {
        if (! $value) {
            $this->salaryError = null;
        }
    }

    /** Records the change through the command: approved at once, or pending when dated in the past. */
    public function saveSalaryChange(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage employee pay settings.');

            return;
        }
        try {
            $message = app(ReplaceSalary::class)->handle($access, $this->employeeId,
                self::money((string) ($this->salaryForm['monthly'] ?? '')), self::money((string) ($this->salaryForm['daily'] ?? '')));
        } catch (QueryException $e) {
            report($e);
            $this->salaryError = 'The salary could not be saved. Nothing was changed. Please try again.';

            return;
        } catch (RuntimeException $e) {
            $this->salaryError = $e->getMessage();

            return;
        }
        $this->salaryOpen = false;
        $this->salaryError = null;
        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * The benefits an employee can be assigned: every type with a schedule,
     * as id => name, in catalog order.
     *
     * @return array<string, string>
     */
    private function assignableTypes(Access $access): array
    {
        $types = [];
        foreach (app(IncentiveTypeRecords::class)->list($access) as $type) {
            try {
                $rule = BenefitRule::fromArray([
                    'amount_type' => $type->amount_type, 'amount' => $type->amount, 'frequency' => $type->frequency, 'month' => $type->month,
                    'statuses' => $type->statuses, 'min_service_months' => $type->min_service_months, 'proration' => $type->proration,
                    'tax' => $type->tax, 'cap' => $type->cap, 'approval_reference' => $type->approval_reference, 'counts_toward_13th' => (bool) $type->counts_toward_13th,
                ]);
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($rule->isScheduled()) {
                $types[$type->id] = $type->name;
            }
        }

        return $types;
    }

    /** A typed amount for the command: blank stays blank, commas stripped, anything else as typed for the refusal. */
    private static function money(string $text): ?string
    {
        $text = trim(str_replace(',', '', $text));

        return $text === '' ? null : $text;
    }

    /* ---- The hourly rate ---- */

    /** The hourly rate is edited from Pay at a glance: the one in-place setting, with its reason. */
    public function startEditHourly(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        $settings = app(EmployeePaySettingsRecords::class)->find($access, $this->employeeId);
        if ($settings === null) {
            Flux::toast(variant: 'danger', text: 'That employee no longer exists.');

            return;
        }
        $this->hourlyRate = EmployeePaySettingsInput::toForm($settings)['hourly_rate'];
        $this->hourlyReason = '';
        $this->hourlyError = null;
        $this->hourlyEditing = true;
        $this->editing = false;
    }

    public function saveHourly(): void
    {
        $access = app(Access::class);
        try {
            $message = app(UpdateEmployeePaySettings::class)->handle($access, $this->employeeId, ['hourly_rate' => $this->hourlyRate], $this->hourlyReason);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The pay settings could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->hourlyError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->hourlyEditing = false;
        $this->hourlyReason = '';
    }

    public function render(): View
    {
        $access = app(Access::class);
        $canRead = $access->mayReadAllData(ModuleCode::PAYROLL);
        $settings = $canRead ? app(EmployeePaySettingsRecords::class)->find($access, $this->employeeId) : null;
        if ($canRead && $settings === null) {
            abort(404);
        }

        $today = ManilaClock::todayKey();
        $lines = $settings === null ? new Collection : app(RecurringLineRecords::class)->forEmployee($access, $settings->id, $today);
        $rates = $settings === null ? null : $this->rates($access, $settings);
        $govVersions = $settings === null ? new Collection : app(GovDeductionPolicyRecords::class)->versions($access, $settings->id);
        $govInForce = self::govInForce($govVersions, $today);
        $gov = $settings === null ? null : EmployeePaySettingsRows::effective($settings, $govInForce);
        $percentRows = $settings === null ? [] : $this->percentRows($access, $settings);
        $assignments = $settings === null ? new Collection : app(BenefitAssignmentRecords::class)->forEmployee($access, $settings->id);

        return view('livewire.records.employee-pay-settings-show', [
            'canRead' => $canRead,
            'settings' => $settings,
            'gov' => $gov,
            'govInForce' => $govInForce,
            'govReady' => GovDeductionPolicyStore::ready(),
            'salaryReady' => SalaryRates::ready(),
            'assignReady' => BenefitAssignments::ready(),
            'balancesReady' => TaxYearToDate::balancesReady(),
            'taxYear' => $settings === null ? ['year' => (int) substr($today, 0, 4), 'taxable' => 0.0, 'withheld' => 0.0] : app(TaxYearRecords::class)->summary($access, $settings->id, (int) substr($today, 0, 4)),
            'taxBalances' => $settings === null ? new Collection : app(TaxYearRecords::class)->balances($access, $settings->id),
            'assignments' => $assignments,
            'assignableTypes' => $settings === null ? [] : $this->assignableTypes($access),
            'customized' => $settings === null ? [] : $gov->customized(),
            'hourlyCustomized' => $settings !== null && EmployeePaySettingsInput::customized($settings) !== [],
            'rates' => $rates,
            'glance' => $settings === null || $rates === null ? null : EmployeePayProfile::glance($settings, $rates),
            'benefits' => $settings === null ? [] : EmployeePayProfile::benefits($access, $settings, $today),
            'percentDeductions' => $settings === null || $gov === null ? [] : EmployeePayProfile::percentDeductions($access, $settings, $today, $gov),
            'percentRows' => $percentRows,
            'payoff' => $settings === null ? null : $this->payoff($access),
            'ratesReady' => PayRulesStore::employeeScopesReady(),
            'recurringReady' => RecurringLines::ready(),
            'entriesReady' => RecurringLineEntries::ready(),
            'entries' => $settings === null ? new Collection : app(RecurringLineEntryRecords::class)->forEmployee($access, $settings->id),
            'lines' => $lines,
            'payoffs' => self::payoffs($lines, $today),
            'incentiveTaxes' => $settings === null ? [] : app(IncentiveTypeRecords::class)->list($access)->mapWithKeys(static fn (IncentiveTypeView $type): array => [$type->id => BenefitText::TAXES[BenefitText::tax($type)]])->all(),
            'lineOptions' => $this->lineOpen && $settings !== null ? $this->lineOptions($access) : [],
            'editingLine' => $this->lineId !== null && $settings !== null ? app(RecurringLineRecords::class)->find($access, $this->lineId, ManilaClock::todayKey()) : null,
            'today' => $today,
        ])->title($settings === null ? 'Employee Pay Settings' : EmployeeName::display($settings).' · Pay Settings');
    }

    /**
     * The approved version in force on a day, from the versions newest first.
     *
     * @param  Collection<int, GovDeductionPolicyView>  $versions
     */
    private static function govInForce(Collection $versions, string $today): ?GovDeductionPolicyView
    {
        return $versions->first(static fn (GovDeductionPolicyView $row): bool => $row->status === 'APPROVED' && $row->effective_from->format('Y-m-d') <= $today);
    }

    /**
     * The percentage Payroll Deductions the form offers a rate on, row id
     * => name and listed detail.
     *
     * @return array<string, array{name: string, detail: string}>
     */
    private function percentRows(Access $access, EmployeePaySettingsView $settings): array
    {
        $rows = [];
        foreach (EmployeePayProfile::percentDeductions($access, $settings, ManilaClock::todayKey()) as $row) {
            $rows[$row['id']] = ['name' => $row['name'], 'detail' => $row['detail']];
        }

        return $rows;
    }

    /**
     * The catalog rows the line dialog offers for its kind.
     *
     * @return list<array{id: string, name: string}>
     */
    private function lineOptions(Access $access): array
    {
        $records = app(RecurringLineRecords::class);

        return $this->lineKind === RecurringLine::KIND_INCENTIVE ? $records->incentiveOptions($access) : $records->deductionOptions($access);
    }

    /**
     * The open line dialog's payoff preview: for a deduction with a total,
     * how many payrolls it takes and the last one, counted from its first
     * cut-off — or, for a line already paying, from the next cut-off with
     * what it has paid. Null while the figures do not read as a line.
     *
     * @return array{payrolls: int, last: string, amount: float}|null
     */
    private function payoff(Access $access): ?array
    {
        if (! $this->lineOpen || $this->lineKind !== RecurringLine::KIND_DEDUCTION) {
            return null;
        }
        $amount = self::number($this->lineAmount);
        $total = trim($this->lineTotal) === '' ? null : self::number($this->lineTotal);
        if ($amount === null || $total === null) {
            return null;
        }
        try {
            $line = RecurringLine::fromArray([
                'kind' => RecurringLine::KIND_DEDUCTION, 'amount' => $amount, 'total' => $total, 'frequency' => $this->lineFrequency,
                'starts_on' => trim($this->lineStartsOn), 'ends_on' => trim($this->lineEndsOn) === '' ? null : trim($this->lineEndsOn),
            ]);
        } catch (InvalidArgumentException) {
            return null;
        }
        $paid = 0.0;
        $from = null;
        if ($this->lineId !== null && $access->mayReadAllData(ModuleCode::PAYROLL)) {
            $paid = app(RecurringLineRecords::class)->find($access, $this->lineId, ManilaClock::todayKey())->paid ?? 0.0;
            $from = self::nextCutoffStart(ManilaClock::todayKey());
        }
        $payoff = RecurringLinePayoff::of($line, $paid, $from);

        return $payoff === null ? null : [...$payoff, 'amount' => $line->amount];
    }

    /**
     * When each running loan is paid off, from the next cut-off with what
     * it has paid, if no payroll skips it: line id => payrolls and the last.
     *
     * @param  Collection<int, RecurringLineView>  $lines
     * @return array<string, array{payrolls: int, last: string}>
     */
    private static function payoffs(Collection $lines, string $today): array
    {
        $payoffs = [];
        foreach ($lines as $line) {
            if ($line->kind !== RecurringLine::KIND_DEDUCTION || $line->total === null || ! in_array($line->status, [RecurringLineRecords::UPCOMING, RecurringLineRecords::ACTIVE], true)) {
                continue;
            }
            try {
                $rule = RecurringLine::fromArray([
                    'kind' => $line->kind, 'amount' => $line->amount, 'total' => $line->total, 'frequency' => $line->frequency,
                    'starts_on' => $line->starts_on->format('Y-m-d'), 'ends_on' => $line->ends_on?->format('Y-m-d'),
                ]);
            } catch (InvalidArgumentException) {
                continue;
            }
            $payoff = RecurringLinePayoff::of($rule, $line->paid, self::nextCutoffStart($today));
            if ($payoff !== null) {
                $payoffs[$line->id] = $payoff;
            }
        }

        return $payoffs;
    }

    /** The 1st or the 16th on or after a day. */
    private static function nextCutoffStart(string $day): string
    {
        $date = CarbonImmutable::parse($day, 'UTC');
        if ($date->day === 1 || $date->day === 16) {
            return $date->format('Y-m-d');
        }

        return ($date->day < 16 ? $date->day(16) : $date->addMonthNoOverflow()->day(1))->format('Y-m-d');
    }

    /** A typed peso figure, or null when it does not read as one. */
    private static function number(string $text): ?float
    {
        $text = str_replace(',', '', trim($text));

        return is_numeric($text) && is_finite((float) $text) ? (float) $text : null;
    }

    /**
     * What prices the employee today: their own, their status's or the
     * company's versions.
     *
     * @return array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}
     */
    private function rates(Access $access, EmployeePaySettingsView $settings): array
    {
        $records = app(PayRuleRecords::class);
        $statusId = $settings->employment_status_id;

        return EmployeeRates::today(
            $records->versions($access, $settings->id)->values()->all(),
            $statusId === null ? [] : $records->versions($access, $statusId)->values()->all(),
            $records->versions($access, PayRulesStore::COMPANY)->values()->all(),
            ManilaClock::todayKey(),
        );
    }
}
