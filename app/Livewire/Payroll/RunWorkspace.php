<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Payroll\PayslipDraft;
use App\Livewire\Payroll\Support\PayrollData;
use App\Models\Position;
use App\Models\User;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\LeaveCancellationWarnings;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PayrollReview;
use App\Modules\Payroll\Application\PayrollReviewRequired;
use App\Modules\Payroll\Application\Queries\IncentiveTypeRecords;
use App\Modules\Payroll\Application\Queries\PayrollRecords;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Contracts\Views\PayrollRunView;
use App\Modules\Payroll\Contracts\Views\PayslipView as Payslip;
use App\Modules\Payroll\Domain\CutoffAdvice;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Modules\Timekeeping\Contracts\ScheduleNotices;
use App\Modules\Workforce\Application\Queries\PayrollEmployees;
use App\Modules\Workforce\Contracts\DirectoryEmployee as EmployeeProfile;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\Csv;
use App\Support\EmployeeIdentity;
use App\Support\ManilaClock;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One payroll, whether it is being created or opened again — the port of
 * payroll/run-workspace.tsx plus its add-employees-dialog.tsx, one screen.
 *
 * A DRAFT recomputes its lines from attendance on every request, through
 * PayrollData::calculateDrafts — the single compute path both the grid preview
 * and finalize share. Nothing here re-derives pay, DTR minutes or a statutory
 * amount. A FINALIZED run shows the payslips it posted, not a fresh
 * computation.
 *
 * The reference kept a client-side computation for unsaved edits and a server
 * preview for the saved state, reconciled by a `dirty` flag; with Livewire the
 * component state IS on the server, so the two collapse into one computation
 * over the current (possibly unsaved) state. `dirty` survives — it still gates
 * finalize, which recomputes strictly from what was SAVED.
 *
 * Finalize = one transaction: membership lock, status guard, referential
 * checks, every payslip recomputed from attendance via the domain layer, the
 * old set cleared, the new set posted, the run flipped DRAFT→FINALIZED. The
 * amounts a client could type are overrides the calculator folds in — the
 * posted numbers are never taken from the request.
 */
#[Layout('layouts.app')]
class RunWorkspace extends Component
{
    /** The open run's id, or null while creating. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $runId = null;

    /** Route id named a run that is gone — renders the "no longer exists" card. */
    #[Locked]
    public bool $missing = false;

    /** Payroll name. Free text the user edits: unlocked. */
    public string $name = '';

    /** Cut-off start, 'Y-m-d'. Form state driven by the date field. */
    public string $start = '';

    /** Cut-off end, 'Y-m-d'. */
    public string $end = '';

    /**
     * Member employee ids, in the order the grid shows them. Id-bearing →
     * #[Locked]; mutated only by {@see self::applyPicked()} and
     * {@see self::removeMember()}, which validate what they admit.
     *
     * @var list<string>
     */
    #[Locked]
    public array $members = [];

    /**
     * Manager-typed allowance per employee id. Keyed by id → #[Locked];
     * written only through {@see self::updateAllowance()}.
     *
     * @var array<string, float>
     */
    #[Locked]
    public array $draftAllowances = [];

    /**
     * Typed deductions per employee id: overrides of the computed statutory
     * lines (SSS, PhilHealth, Pag-IBIG, withholding) and the always-typed
     * other deductions. An ABSENT key means "use the computed figure" and a
     * typed 0 is an override to zero; an emptied cell removes the key
     * ({@see self::updateDeduction()}).
     *
     * @var array<string, array<string, float>>
     */
    #[Locked]
    public array $draftDeductions = [];

    /**
     * Hand-entered earnings per employee id. Overtime is not acknowledged
     * here: it is approved per day on the Daily Time Record.
     *
     * @var array<string, array<string, mixed>>
     */
    #[Locked]
    public array $draftEarnings = [];

    /**
     * Incentive and bonus amounts per employee id: employee id => [incentive
     * type id => amount]. Keyed by ids → #[Locked]; written only through
     * {@see self::applyIncentives()}, which admits catalog types and positive
     * amounts, and SavePayroll checks both again.
     *
     * @var array<string, array<string, float>>
     */
    #[Locked]
    public array $draftIncentives = [];

    /**
     * The typed deduction columns: employee id => [Payroll Deductions row
     * id => amount], one cell per row the list prices by hand. Keyed by
     * ids → #[Locked]; written only through
     * {@see self::updateCustomDeduction()}, which admits only a current typed
     * row and a positive amount, and SavePayroll checks both again.
     *
     * @var array<string, array<string, float>>
     */
    #[Locked]
    public array $draftCustomDeductions = [];

    /**
     * What the database holds, for the `dirty` flag. Empty while creating —
     * a new workspace is dirty by definition (run-workspace.tsx:201-209).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $savedState = [];

    /** Server-signed receipt for the displayed inputs, never a client-supplied amount. */
    #[Locked]
    public ?string $previewReceipt = null;

    /** A failed stale preview requires a separate review action before another finalization. */
    #[Locked]
    public bool $reviewRequired = false;

    /* ---- Add-employees dialog (add-employees-dialog.tsx) ---- */

    /** Whether the dialog is showing. */
    public bool $picking = false;

    /** Dialog search. Plain filter state, unlocked. */
    public string $pickSearch = '';

    /** Dialog position filter. */
    public string $pickPosition = 'ALL';

    /**
     * The dialog's working selection. Id-bearing → #[Locked]; mutated only by
     * the toggle actions, which admit only active employees.
     *
     * @var list<string>
     */
    #[Locked]
    public array $picked = [];

    /* ---- Incentives dialog ---- */

    /** Whether the incentives dialog is showing. */
    public bool $incentivesOpen = false;

    /** The member whose incentives the dialog edits. Id-bearing → #[Locked]; set by {@see self::openIncentives()}. */
    #[Locked]
    public ?string $incentivesFor = null;

    /**
     * The dialog's draft amounts, incentive type id => typed text. Not
     * #[Locked], deliberately: a wire:model draft keyed by type id, so a
     * client can send any key — applyIncentives() admits only catalog types
     * and positive amounts, and SavePayroll re-validates every type and
     * amount against the database.
     *
     * @var array<string, string>
     */
    public array $incentiveForm = [];

    /** The dialog's inline error. */
    public ?string $incentiveError = null;

    public function mount(?string $id = null): mixed
    {
        if ($id === null) {
            // thisMonth() defaults (run-workspace.tsx:47-50, 141-142) — the
            // first cut-off of the current Manila month.
            $month = substr(PayrollData::todayKey(), 0, 7);
            $this->start = "{$month}-01";
            $this->end = "{$month}-15";

            return null;
        }

        $run = app(PayrollRecords::class)->find(app(Access::class), $id, withMembers: true);

        if ($run === null) {
            $this->missing = true;

            return null;
        }

        // A 13th month run has its own workspace.
        if (($run->kind ?? PayrollRunKind::REGULAR) === PayrollRunKind::THIRTEENTH_MONTH) {
            return $this->redirectRoute('payroll.thirteenth.show', ['id' => $run->id], navigate: true);
        }

        $this->runId = $run->id;
        $this->name = $run->name;
        $this->start = $run->period_start->format('Y-m-d');
        $this->end = $run->period_end->format('Y-m-d');
        $this->members = PayrollData::memberIds($run->members);

        $overrides = PayrollData::overridesFromMembers($run->members);
        $this->draftAllowances = $overrides['allowances'];
        $this->draftDeductions = $overrides['deductions'];
        $this->draftEarnings = $overrides['earnings'];
        $this->draftIncentives = $overrides['incentives'];
        $this->draftCustomDeductions = $overrides['customDeductions'];

        $this->savedState = $this->currentState();

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Grid edits — the useState setters, as validated Livewire actions */
    /* ------------------------------------------------------------------ */

    /** updateAllowance (run-workspace.tsx:383-385). */
    public function updateAllowance(string $employeeId, mixed $value): void
    {
        if ($this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            return;
        }

        $this->draftAllowances[$employeeId] = self::asMoney($value);
    }

    /** updateDeduction (run-workspace.tsx:373-381). */
    public function updateDeduction(string $employeeId, string $field, mixed $value): void
    {
        if ($this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            return;
        }

        if (! in_array($field, PayslipDraft::EDITABLE_DEDUCTION_KEYS, true)) {
            return; // tampered field name: fail closed
        }

        /*
         * AN EMPTIED OR UNREADABLE CELL CLEARS THE OVERRIDE. The engine
         * computes the statutory lines, so an absent key means "use the
         * computed figure" — while a stored 0 would EXEMPT the employee
         * from that line, with a payslip that still foots. So a blank, and
         * anything that is still not a number once the thousands separators
         * are gone, removes the key rather than storing asMoney()'s 0.0. A
         * typed 0 is still an override to zero, on purpose.
         */
        $amount = is_string($value) ? str_replace(',', '', trim($value)) : $value;
        if ($amount === null || $amount === '' || ! is_numeric($amount) || ! is_finite((float) $amount)) {
            unset($this->draftDeductions[$employeeId][$field]);

            if (($this->draftDeductions[$employeeId] ?? null) === []) {
                unset($this->draftDeductions[$employeeId]);
            }

            return;
        }

        $this->draftDeductions[$employeeId][$field] = self::asMoney($amount);
    }

    /** updateEarning (run-workspace.tsx:387-392). */
    public function updateEarning(string $employeeId, string $field, mixed $value): void
    {
        if ($this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            return;
        }

        if (! array_key_exists($field, PayrollData::EARNING_COLUMNS)) {
            return;
        }

        /*
         * AN EMPTIED CELL CLEARS THE OVERRIDE. It does not store a zero.
         *
         * `asMoney` turns anything non-numeric — an empty string among them —
         * into 0.0, and `memberColumns` then persists that 0.0 because
         * `is_numeric(0.0)` is true. For `night_differential` and
         * `leave_pay` (DIVERGENCE 6), a stored 0 is an override that REPLACES
         * the computed figure with nothing: an employee's whole paid leave
         * would silently pay zero, and the payslip would still foot correctly
         * so nothing would look wrong.
         *
         * `asMoney` itself deliberately turns a blank into 0.0 —
         * `updateAllowance` shares it, and for an allowance a cleared cell and
         * a zero genuinely mean the same thing. `updateDeduction` unsets an
         * emptied cell the same way this does, since the statutory lines have
         * a computed figure too.
         */
        if ($value === null || (is_string($value) && trim($value) === '')) {
            unset($this->draftEarnings[$employeeId][$field]);

            if (($this->draftEarnings[$employeeId] ?? null) === []) {
                unset($this->draftEarnings[$employeeId]);
            }

            return;
        }

        $this->draftEarnings[$employeeId][$field] = self::asMoney($value);
    }

    /**
     * Takes an employee off this payroll (run-workspace.tsx:402-412). Their
     * attendance and any payslip from another run are untouched — this only
     * shortens the member list, and the adjustments typed against them go with
     * it so a re-add starts clean.
     */
    public function removeMember(string $employeeId): void
    {
        if ($this->isFinalized()) {
            return;
        }

        $this->members = array_values(array_filter(
            $this->members,
            static fn (string $id): bool => $id !== $employeeId,
        ));

        unset(
            $this->draftAllowances[$employeeId],
            $this->draftDeductions[$employeeId],
            $this->draftEarnings[$employeeId],
            $this->draftIncentives[$employeeId],
            $this->draftCustomDeductions[$employeeId],
        );
    }

    /* ------------------------------------------------------------------ */
    /* Incentives dialog */
    /* ------------------------------------------------------------------ */

    /**
     * Opens one member's incentives: every catalog type, each with the
     * amount this draft holds for it (blank when none). Component state
     * only — nothing is written until Save.
     */
    public function openIncentives(string $employeeId): void
    {
        if ($this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            return;
        }

        $form = [];
        foreach (app(IncentiveTypeRecords::class)->list(app(Access::class)) as $type) {
            $amount = $this->draftIncentives[$employeeId][$type->id] ?? null;
            $form[$type->id] = $amount === null ? '' : (string) $amount;
        }

        $this->incentivesFor = $employeeId;
        $this->incentiveForm = $form;
        $this->incentiveError = null;
        $this->incentivesOpen = true;
    }

    /**
     * Hands the dialog's amounts back to the draft: a blank or zero is no
     * incentive; anything else must be a positive peso figure of a type
     * still in the catalog. Component state only.
     */
    public function applyIncentives(): void
    {
        $employeeId = $this->incentivesFor;
        if ($employeeId === null || $this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            $this->incentivesOpen = false;

            return;
        }

        $access = app(Access::class);
        $types = app(IncentiveTypeRecords::class)->list($access)->keyBy('id');
        $known = $types->keys()->all();
        // A scheduled benefit is paid as proposed unless an amount replaces
        // it; 0 is not an amount, and the catalog is where it is stopped.
        $scheduledIds = PayrollData::scheduledBenefitTypes($access, $employeeId, $this->start, $this->end);
        $amounts = [];
        foreach ($this->incentiveForm as $typeId => $text) {
            $value = str_replace(',', '', trim((string) $text));
            if ($value !== '' && is_numeric($value) && (float) $value === 0.0 && in_array((string) $typeId, $scheduledIds, true)) {
                $name = $types->get((string) $typeId)->name ?? 'This benefit';
                $this->incentiveError = "{$name} is scheduled by the catalog and is paid as proposed; it cannot be set to 0 here. To stop it for everyone, change its rule in Incentives & Bonuses.";

                return;
            }
            if ($value === '' || (is_numeric($value) && (float) $value === 0.0)) {
                continue;
            }
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                $this->incentiveError = 'Enter each amount as a peso figure, or leave it empty.';

                return;
            }
            if (! in_array((string) $typeId, $known, true)) {
                $this->incentiveError = 'An incentive type was removed meanwhile. Close this and open it again.';

                return;
            }
            $amounts[(string) $typeId] = (float) $value;
        }

        if ($amounts === []) {
            unset($this->draftIncentives[$employeeId]);
        } else {
            ksort($amounts, SORT_STRING);
            $this->draftIncentives[$employeeId] = $amounts;
        }

        $this->incentivesOpen = false;
        $this->incentivesFor = null;
        $this->incentiveError = null;
    }

    /**
     * A typed deduction column's cell: the amount of one Payroll Deductions
     * row for one member, typed in the grid like Other Deductions.
     * Only a row the list prices by hand is admitted — an automatic row is the
     * engine's line, and typing it here would charge it twice — and only a
     * positive amount; an emptied, zero or unreadable cell removes the line.
     * Reads the catalog, so it re-checks Access; SavePayroll validates the
     * row and amount again inside its transaction.
     */
    public function updateCustomDeduction(string $employeeId, string $typeId, mixed $value): void
    {
        $actor = app(Access::class);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL) || $this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            return;
        }

        if (! PayrollData::customDeductionTypes($actor)->contains(static fn (object $type): bool => $type->id === $typeId)) {
            return; // an automatic, deleted or made-up row: fail closed
        }

        $amount = is_string($value) ? str_replace(',', '', trim($value)) : $value;
        if ($amount === null || $amount === '' || ! is_numeric($amount) || ! is_finite((float) $amount) || Money::round($amount) <= 0) {
            unset($this->draftCustomDeductions[$employeeId][$typeId]);

            if (($this->draftCustomDeductions[$employeeId] ?? null) === []) {
                unset($this->draftCustomDeductions[$employeeId]);
            }

            return;
        }

        $this->draftCustomDeductions[$employeeId][$typeId] = Money::round($amount);
        ksort($this->draftCustomDeductions[$employeeId], SORT_STRING);
    }

    /* ------------------------------------------------------------------ */
    /* Add-employees dialog */
    /* ------------------------------------------------------------------ */

    /** Every opening starts from what the payroll holds right now (add-employees-dialog.tsx:51-57). */
    public function openPicker(): void
    {
        if ($this->isFinalized()) {
            return;
        }

        $this->pickSearch = '';
        $this->pickPosition = 'ALL';
        $this->picked = $this->members;
        $this->picking = true;
    }

    /** Ticking adds an employee and unticking takes them off again. */
    public function togglePick(string $employeeId): void
    {
        // Only rows the dialog lists — active employees — can be admitted.
        $exists = app(PayrollEmployees::class)->active(app(Access::class), $employeeId);

        if (! $exists && ! in_array($employeeId, $this->picked, true)) {
            return;
        }

        $this->picked = in_array($employeeId, $this->picked, true)
            ? array_values(array_filter($this->picked, static fn (string $id): bool => $id !== $employeeId))
            : [...$this->picked, $employeeId];
    }

    /** Select-all works on what the filters are showing, nothing more (add-employees-dialog.tsx:96-104). */
    public function togglePickShown(): void
    {
        $shown = $this->pickerVisible($this->pickerEmployees())->pluck('id')->all();

        if ($shown === []) {
            return;
        }

        $allShown = array_diff($shown, $this->picked) === [];

        $this->picked = $allShown
            ? array_values(array_diff($this->picked, $shown))
            : array_values([...$this->picked, ...array_diff($shown, $this->picked)]);
    }

    /** "Save selection" — hands the ticks back as the member list. */
    public function applyPicked(): void
    {
        if ($this->isFinalized()) {
            return;
        }

        $this->members = $this->picked;
        $this->picking = false;
    }

    /* ------------------------------------------------------------------ */
    /* Save */
    /* ------------------------------------------------------------------ */

    /**
     * Save the draft — the port of POST /api/payroll/runs (create) and
     * PATCH /api/payroll/runs/[id] (update), plus the workspace's own
     * pre-flight checks (run-workspace.tsx:243-317).
     */
    public function save(): mixed
    {
        $actor = app(Access::class);
        // A typed cell whose Payroll Deductions row was deleted or made
        // automatic meanwhile has no column any more (the grid drops it), so
        // it is dropped here too rather than refused as unknown; SavePayroll
        // still checks every row it is handed.
        $typed = PayrollData::customDeductionTypes($actor)->pluck('id')->all();
        foreach ($this->draftCustomDeductions as $employeeId => $lines) {
            $this->draftCustomDeductions[$employeeId] = array_intersect_key($lines, array_flip($typed));
            if ($this->draftCustomDeductions[$employeeId] === []) {
                unset($this->draftCustomDeductions[$employeeId]);
            }
        }
        try {
            $result = app(SavePayroll::class)->handle($actor, new PayrollDraft($this->runId, $this->name, $this->start, $this->end, $this->members, $this->activeDeductions(), $this->activeAllowances(), $this->activeEarnings(), $this->activeIncentives(), customDeductions: $this->activeCustomDeductions()));
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: str_contains($e->getMessage(), 'payroll_runs_name_unique') ? 'That payroll name is already in use.' : 'That payroll could not be saved. Try again.', variant: 'danger');

            return null;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return null;
        }
        $this->name = trim($this->name);
        if ($result['created']) {
            Flux::toast(text: 'Payroll saved as a draft.', variant: 'success');

            return $this->redirectRoute('payroll.show', ['id' => $result['id']], navigate: true);
        }
        $this->savedState = $this->currentState();
        Flux::toast(text: 'Payroll saved.', variant: 'success');

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Finalize */
    /* ------------------------------------------------------------------ */

    /**
     * Finalising posts a payslip per member and locks the run — the port of
     * POST /api/payroll/runs/[id]/finalize. Every line is recomputed from
     * captured inputs INSIDE the transaction; the browser's receipt must match
     * those inputs. Evidence, payslips, status and audit commit together.
     */
    public function finalize(): void
    {
        $actor = app(Access::class);

        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(text: 'Your role cannot manage organization-wide data.', variant: 'danger');

            return;
        }

        if ($this->runId === null) {
            return;
        }

        if ($this->reviewRequired) {
            Flux::toast(text: 'Review the updated payroll before finalizing.', variant: 'danger');

            return;
        }

        $cutoffError = PayrollData::cutoffError(app(Access::class), $this->members, $this->start, $this->end);

        if ($cutoffError !== null) {
            Flux::toast(text: $cutoffError, variant: 'danger');

            return;
        }

        if ($this->members === []) {
            Flux::toast(text: 'Add at least one employee first.', variant: 'danger');

            return;
        }

        if ($this->isDirty()) {
            Flux::toast(text: 'Save your changes before finalizing.', variant: 'danger');

            return;
        }

        try {
            $count = app(FinalizePayroll::class)->handle($actor, $this->runId, $this->previewReceipt);
        } catch (PayrollReviewRequired $e) {
            // Finalize already refused dirty local state, so reloading cannot discard unsaved work.
            $this->mount($this->runId);
            $this->reviewRequired = true;
            $this->previewReceipt = null;
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The payroll could not be finalized. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        Flux::toast(text: 'Finalized — '.$count.' payslips posted.', variant: 'success');
    }

    /** Acknowledges the newly displayed preview without posting or changing the saved draft. */
    public function reviewChanges(): void
    {
        $actor = app(Access::class);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(text: 'Your role cannot manage organization-wide data.', variant: 'danger');

            return;
        }
        if ($this->isDirty()) {
            Flux::toast(text: 'Save your changes before reviewing.', variant: 'danger');

            return;
        }
        $this->reviewRequired = false;
    }

    /* ------------------------------------------------------------------ */
    /* Export */
    /* ------------------------------------------------------------------ */

    /**
     * Export CSV (run-workspace.tsx:414-498) — the grid's lines, one row per
     * payslip, streamed as an RFC 4180 file with the reference's columns first,
     * followed by the employer and incentive details and one column per
     * typed Payroll Deductions row, formula-guarding and a UTF-8 BOM.
     */
    public function exportCsv(): ?StreamedResponse
    {
        $actor = app(Access::class);

        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(text: 'Your role cannot manage organization-wide data.', variant: 'danger');

            return null;
        }

        [$lines, $employees, $customDeductionLines, $deductionColumns] = $this->linesForDisplay($actor);

        if (count($lines) === 0) {
            Flux::toast(text: 'Nothing to export yet.', variant: 'danger');

            return null;
        }

        // The four statutory headers read as the Payroll Deductions list
        // names them; their positions are the reference's.
        $statutoryHeader = static function (string $calculation) use ($deductionColumns): string {
            $column = $deductionColumns->first(static fn (DeductionColumnView $c): bool => $c->calculation === $calculation);

            return $column->name ?? DeductionCalculation::defaultLabel($calculation);
        };
        $typedColumns = $deductionColumns->filter(static fn (DeductionColumnView $c): bool => ! $c->isAutomatic())->values();
        $typedAmounts = PayrollData::typedDeductionAmounts($customDeductionLines);

        $run = $this->runId !== null ? app(PayrollRecords::class)->find($actor, $this->runId) : null;
        $base = ($run->code ?? trim($this->name)) !== '' ? ($run->code ?? trim($this->name)) : 'draft';
        $filename = 'cyberonions-payroll-'.mb_strtolower($base).'.csv';

        // Both identifiers, unlike the screen: a spreadsheet is reconciled
        // against other systems, and the row id is the only key that is never
        // null. 'ID' matches what every list calls it.
        $headers = [
            'ID', 'Employee ID', 'Employee', 'Period start', 'Period end', 'Daily rate',
            'Work days', 'Holidays worked', 'Rest days worked', 'Absences', 'Unpaid leave days', 'Paid leave days',
            'Work days pay', 'Holiday pay', 'Rest day pay', 'Basic salary', 'Allowance', 'Total',
            'Overtime hours', 'Overtime pay', 'Overtime approved', 'NSD', 'Leave',
            'Other earnings', 'Gross income',
            $statutoryHeader(DeductionCalculation::SSS), $statutoryHeader(DeductionCalculation::PHILHEALTH),
            $statutoryHeader(DeductionCalculation::PAGIBIG), $statutoryHeader(DeductionCalculation::WITHHOLDING_TAX),
            'Other deductions', 'Undertime minutes', 'Undertime deduction', 'Late minutes',
            'Late deduction', 'Total deductions', 'Net pay',
            // After the reference's columns, so each of those keeps its
            // position: the employer shares, reported and never deducted.
            ...array_values(PayrollData::EMPLOYER_LABELS),
            // And the incentives and bonuses: the taxed part (taxable lines
            // plus any excess over the PHP 90,000 ceiling), the tax-exempt
            // part, and how much of the taxed part is excess.
            'Incentives taxed', 'Incentives tax-exempt', 'Excess over PHP 90,000',
            // And the typed deduction columns: one per Payroll Deductions
            // row an officer types, headed as the list names it, blank
            // where the employee has no such line.
            ...$typedColumns->map(static fn (DeductionColumnView $c): string => $c->name)->all(),
        ];

        $rows = [];

        foreach ($lines as $p) {
            $e = $employees->get($p->employee_id);
            $rows[] = [
                $e->employee_code ?? '',
                EmployeeIdentity::besideName($e),
                PayrollData::employeeName($e),
                $p->period_start instanceof \DateTimeInterface ? $p->period_start->format('Y-m-d') : $p->period_start,
                $p->period_end instanceof \DateTimeInterface ? $p->period_end->format('Y-m-d') : $p->period_end,
                $p->daily_rate,
                $p->work_days, $p->holiday_days, $p->rest_days, $p->absence_days, $p->unpaid_leave_days, $p->paid_leave_days,
                $p->work_days_pay, $p->holiday_pay, $p->rest_day_pay, $p->basic_pay, $p->allowances, $p->total_base_pay,
                $p->overtime_hours, $p->overtime_pay, $p->overtime_acknowledged ? 'Yes' : 'No',
                $p->night_differential, $p->leave_pay, $p->other_earnings, $p->gross_pay,
                $p->sss, $p->philhealth, $p->pagibig, $p->withholding_tax, $p->other_deductions,
                $p->undertime_minutes, $p->undertime_deduction, $p->late_minutes, $p->late_deduction,
                $p->total_deductions, $p->net_pay,
                $p->sss_er, $p->philhealth_er, $p->pagibig_er,
                $p->bonus, $p->exempt_benefits, $p->taxable_benefits_excess,
                ...$typedColumns->map(static fn (DeductionColumnView $c): string|float => $typedAmounts[$p->employee_id][$c->key()] ?? '')->all(),
            ];
        }

        $csv = Csv::build($headers, $rows);
        $count = count($lines);

        Flux::toast(text: "Exported {$count} payslips.", variant: 'success');

        return response()->streamDownload(static function () use ($csv): void {
            // The UTF-8 BOM Excel needs is already the first bytes of $csv —
            // Csv::build() emits it. Prepending another here would give the
            // file two, and Excel would show a stray ï»¿ in cell A1.
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------------------ */
    /* Render */
    /* ------------------------------------------------------------------ */

    public function render(): View
    {
        $actor = app(Access::class);

        if ($this->missing || ($this->runId !== null && app(PayrollRecords::class)->find($actor, $this->runId) === null)) {
            return view('livewire.payroll.run-missing')->title('Payroll');
        }

        $run = $this->runId !== null ? app(PayrollRecords::class)->find($actor, $this->runId) : null;
        $finalized = $run?->status === PayrollRunStatus::FINALIZED;

        $cutoffError = $finalized
            ? null
            : PayrollData::cutoffError($actor, $this->members, $this->start, $this->end);

        $readinessIssue = null;
        $invalidDraft = null;
        $statutory = [];
        $incentiveLines = [];
        $customDeductionLines = [];
        /** @var array<string, float> $proposedIncentives */
        $proposedIncentives = [];
        $exemptOrderWarning = false;
        $scheduleWarnings = ['unscheduled' => [], 'changed' => [], 'since' => ''];
        $leaveCancellationWarnings = [];
        $this->previewReceipt = null;

        // Non-blocking (Modules\Payroll\Domain\CutoffAdvice): shown beside the
        // grid, never part of what disables Finalize.
        $cutoffWarning = $finalized || $cutoffError !== null ? null : CutoffAdvice::warning($this->start, $this->end);

        if ($finalized) {
            /** A finalised run shows what it posted, not a fresh computation. */
            $lines = array_values(app(PayslipRecords::class)->forRun($actor, $run->id)->all());

            $employees = app(OperationalDirectory::class)->list($actor, array_map(static fn (Payslip $p): string => $p->employee_id, $lines))
                ->keyBy('id');
            $payslipIds = array_map(static fn (Payslip $p): string => $p->id, $lines);
            $posted = app(PayslipRecords::class)->customDeductions($actor, $payslipIds);
            foreach ($lines as $line) {
                $customDeductionLines[$line->employee_id] = $posted[$line->id] ?? [];
            }
            $deductionColumns = app(PayslipRecords::class)->deductionColumns($actor, $payslipIds);
        } elseif ($actor->mayReadAllData(ModuleCode::PAYROLL)) {
            // usePayrollCalculation(cutoffError ? [] : members, …) — a broken
            // cut-off computes nothing and the grid shows the error instead.
            $calculation = PayrollData::calculateDrafts(
                $cutoffError !== null ? [] : $this->members,
                $this->start,
                $this->end,
                $this->activeDeductions(),
                $this->activeAllowances(),
                $this->activeEarnings(),
                draftIncentives: $this->activeIncentives(),
                draftCustomDeductions: $this->activeCustomDeductions(),
            );

            $lines = $calculation['drafts'];
            $leaveCancellationWarnings = LeaveCancellationWarnings::forInputs($calculation['inputs']);
            $employees = $calculation['employees']->keyBy('id');
            $statutory = $calculation['statutory'];
            $incentiveLines = $calculation['incentiveLines'];
            $customDeductionLines = $calculation['customDeductionLines'];
            $deductionColumns = PayrollData::deductionColumns($actor);
            // The scheduled benefits payroll proposes for the member whose
            // incentives are open, type id => amount; a typed amount replaces one.
            foreach ($this->incentivesFor !== null ? ($calculation['incentiveLines'][$this->incentivesFor] ?? []) : [] as $line) {
                if ($line['automatic']) {
                    $proposedIncentives[$line['type_id']] = $line['amount'];
                }
            }

            // Non-blocking: an earlier open payroll of the same year also
            // paying these people a tax-exempt incentive.
            $exempting = array_keys(array_filter($incentiveLines, static fn (array $lines): bool => array_filter($lines, static fn (array $line): bool => ! $line['is_taxable']) !== []));
            $exemptOrderWarning = $cutoffError === null
                && app(PayrollRecords::class)->hasEarlierOpenExemptDraft($actor, $this->runId, array_map('strval', $exempting), $this->end);
            if ($this->runId !== null && ! $this->isDirty() && $calculation['inputs'] !== null) {
                $this->previewReceipt = PayrollReview::issue($actor, $this->runId, $this->name, $calculation['inputs']);
            }

            // Non-blocking: members judged by the system default on dates
            // nobody gave them a schedule for, and schedules changed after
            // this draft was last saved. The amounts on this page already
            // follow each date's schedule as it is now; finalizing still
            // needs the receipt of exactly what is shown.
            if ($cutoffError === null && $this->members !== []) {
                $scheduleWarnings = $this->scheduleWarnings($actor, $run, $employees);
            }

            if ($calculation['issues'] !== []) {
                $issue = $calculation['issues'][0];
                $readinessIssue = [
                    'employee' => PayrollData::employeeName($employees->get($issue['employee_id'])),
                    'message' => $issue['message'],
                ];
            }

            if ($calculation['draftErrors'] !== []) {
                $error = $calculation['draftErrors'][0];
                $invalidDraft = [
                    'employee' => PayrollData::employeeName($employees->get($error['employee_id'])),
                    'message' => $error['message'],
                ];
            }
        } else {
            $lines = [];
            $employees = new Collection;
            $deductionColumns = new Collection;
        }

        $totals = PayrollData::totals($lines);
        $typedAmounts = PayrollData::typedDeductionAmounts($customDeductionLines);
        $dirty = $this->isDirty();

        // The dialog's data, only while it is open.
        $pickerRows = new Collection;
        $pickerPositions = [];
        $allShown = false;

        if ($this->picking && ! $finalized) {
            $active = $this->pickerEmployees();
            $pickerRows = $this->pickerVisible($active);
            $pickerPositions = $this->pickerPositions($active);
            $allShown = $pickerRows->isNotEmpty()
                && array_diff($pickerRows->pluck('id')->all(), $this->picked) === [];
        }

        return view('livewire.payroll.run-workspace', [
            'run' => $run,
            'finalized' => $finalized,
            'lines' => $lines,
            'employees' => $employees,
            'totals' => $totals,
            'cutoffError' => $cutoffError,
            'invalidDraft' => $invalidDraft,
            'readinessIssue' => $readinessIssue,
            'cutoffWarning' => $cutoffWarning,
            'statutory' => $statutory,
            'incentiveLines' => $incentiveLines,
            'customDeductionLines' => $customDeductionLines,
            'exemptOrderWarning' => $exemptOrderWarning,
            'scheduleWarnings' => $scheduleWarnings,
            'leaveCancellationWarnings' => $leaveCancellationWarnings,
            // The catalog, only while the incentives dialog is open.
            'incentiveTypes' => $this->incentivesOpen && ! $finalized ? app(IncentiveTypeRecords::class)->list($actor) : new Collection,
            'incentivesEmployee' => $this->incentivesFor !== null ? $employees->get($this->incentivesFor) : null,
            'proposedIncentives' => $proposedIncentives,
            // The members on a draft whose cut-off is refused: the grid computes nothing, so the banner names them.
            'cutoffMembers' => $cutoffError !== null && ! $finalized ? app(OperationalDirectory::class)->list($actor, $this->members)->map(static fn (EmployeeProfile $e): string => PayrollData::employeeName($e))->values()->all() : [],
            // The Deductions group: one column per Payroll Deductions row —
            // the list's for a draft, what the payslips posted for a
            // finalized run — each typed cell's amount keyed by employee
            // and column, and each column's footing.
            'deductionColumns' => $deductionColumns,
            'typedAmounts' => $typedAmounts,
            'deductionTotals' => PayrollData::deductionTotals($deductionColumns, $totals, $typedAmounts),
            'dirty' => $dirty,
            'pickerRows' => $pickerRows,
            'pickerPositions' => $pickerPositions,
            'allShown' => $allShown,
        ])->title($run !== null ? 'Payroll' : 'Create payroll');
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /** DraftMoneyInput's coercion (run-workspace.tsx:120-124): finite → max(0, n), else 0. */
    /**
     * The amount cells send their comma-free `raw` (money-field.js), so the
     * strip below should never have anything to do. It is here because of what
     * happens if it ever does: is_numeric('1,000.00') is false, this would
     * return 0.0, and an employee's deduction would silently become nothing —
     * no error, no toast, just a wrong payslip. A separator is not a typo worth
     * discarding an amount over, and the cost of tolerating one is one call.
     *
     * Only the grouping separator is stripped. A value that is still not a
     * number after that is refused.
     */
    private static function asMoney(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }

    private function isFinalized(): bool
    {
        return $this->runId !== null
            && app(PayrollRecords::class)->find(app(Access::class), $this->runId)?->status === PayrollRunStatus::FINALIZED;
    }

    /**
     * The dirty flag (run-workspace.tsx:201-209): a workspace with no saved
     * run is dirty; otherwise any drift from the saved snapshot is.
     */
    private function isDirty(): bool
    {
        return $this->savedState === [] || $this->currentState() != $this->savedState;
    }

    /** @return array<string, mixed> */
    private function currentState(): array
    {
        return [
            'name' => $this->name,
            'start' => $this->start,
            'end' => $this->end,
            'members' => $this->members,
            'allowances' => $this->activeAllowances(),
            'deductions' => $this->activeDeductions(),
            'earnings' => $this->activeEarnings(),
            'incentives' => $this->activeIncentives(),
            'customDeductions' => $this->activeCustomDeductions(),
        ];
    }

    /**
     * The members' incentive amounts, members only.
     *
     * @return array<string, array<string, float>>
     */
    private function activeIncentives(): array
    {
        $active = [];

        foreach ($this->members as $employeeId) {
            $entry = $this->draftIncentives[$employeeId] ?? null;

            if (is_array($entry) && $entry !== []) {
                $active[$employeeId] = $entry;
            }
        }

        return $active;
    }

    /** @return array<string, array<string, float>> */
    private function activeCustomDeductions(): array
    {
        $active = [];
        foreach ($this->members as $employeeId) {
            $entry = $this->draftCustomDeductions[$employeeId] ?? null;
            if (is_array($entry) && $entry !== []) {
                $active[$employeeId] = $entry;
            }
        }

        return $active;
    }

    /**
     * activeDraftAllowances (run-workspace.tsx:181-190): only members, only
     * finite non-negative figures.
     *
     * @return array<string, float>
     */
    private function activeAllowances(): array
    {
        $active = [];

        foreach ($this->members as $employeeId) {
            $value = $this->draftAllowances[$employeeId] ?? null;

            if (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                $active[$employeeId] = (float) $value;
            }
        }

        return $active;
    }

    /**
     * activeDraftDeductions (run-workspace.tsx:172-179).
     *
     * @return array<string, array<string, float>>
     */
    private function activeDeductions(): array
    {
        $active = [];

        foreach ($this->members as $employeeId) {
            $override = $this->draftDeductions[$employeeId] ?? null;

            if (is_array($override) && $override !== []) {
                $active[$employeeId] = $override;
            }
        }

        return $active;
    }

    /**
     * activeDraftEarnings (run-workspace.tsx:192-199).
     *
     * @return array<string, array<string, mixed>>
     */
    private function activeEarnings(): array
    {
        $active = [];

        foreach ($this->members as $employeeId) {
            $entry = $this->draftEarnings[$employeeId] ?? null;

            if (is_array($entry) && $entry !== []) {
                $active[$employeeId] = $entry;
            }
        }

        return $active;
    }

    /**
     * The grid's lines and their employees, exactly as render() resolves them
     * — shared with the CSV export.
     *
     * @return array{0: list<Payslip|PayslipDraft>, 1: Collection<string, EmployeeProfile>|Collection<string, EmployeePayData>, 2: array<string, list<mixed>>, 3: Collection<int, DeductionColumnView>}
     */
    private function linesForDisplay(Access $actor): array
    {
        $run = $this->runId !== null ? app(PayrollRecords::class)->find($actor, $this->runId) : null;

        if ($run?->status === PayrollRunStatus::FINALIZED) {
            $lines = array_values(app(PayslipRecords::class)->forRun($actor, $run->id)->all());

            $employees = app(OperationalDirectory::class)->list($actor, array_map(static fn (Payslip $p): string => $p->employee_id, $lines))
                ->keyBy(static fn (EmployeeProfile $e): string => $e->id);

            $payslipIds = array_map(static fn (Payslip $p): string => $p->id, $lines);
            $posted = app(PayslipRecords::class)->customDeductions($actor, $payslipIds);
            $customDeductionLines = [];
            foreach ($lines as $line) {
                $customDeductionLines[$line->employee_id] = $posted[$line->id] ?? [];
            }

            return [$lines, $employees, $customDeductionLines, app(PayslipRecords::class)->deductionColumns($actor, $payslipIds)];
        }

        $cutoffError = PayrollData::cutoffError($actor, $this->members, $this->start, $this->end);

        $calculation = PayrollData::calculateDrafts(
            $cutoffError !== null ? [] : $this->members,
            $this->start,
            $this->end,
            $this->activeDeductions(),
            $this->activeAllowances(),
            $this->activeEarnings(),
            draftIncentives: $this->activeIncentives(),
            draftCustomDeductions: $this->activeCustomDeductions(),
        );

        return [$calculation['drafts'], $calculation['employees']->keyBy(static fn (EmployeePayData $e): string => $e->id), $calculation['customDeductionLines'], PayrollData::deductionColumns($actor)];
    }

    /**
     * The dialog's base list: active employees, as useEmployees fed it.
     *
     * @return Collection<int, EmployeeProfile>
     */
    private function pickerEmployees(): Collection
    {
        return app(PayrollEmployees::class)->list(app(Access::class));
    }

    /**
     * The dialog's filter pipeline (add-employees-dialog.tsx:70-81).
     *
     * @param  Collection<int, EmployeeProfile>  $employees
     * @return Collection<int, EmployeeProfile>
     */
    private function pickerVisible(Collection $employees): Collection
    {
        $needle = mb_strtolower(trim($this->pickSearch));

        return $employees
            ->filter(fn (EmployeeProfile $e): bool => $this->pickPosition === 'ALL' || $e->position === $this->pickPosition)
            ->filter(static function (EmployeeProfile $e) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                $name = PayrollData::employeeName($e);

                // BOTH identifiers, although the rows show only the
                // Employee ID under each name: an operator who still has a
                // COP- code on a handover sheet must be able to paste it, and
                // a number they CAN read and cannot search for reads as "no
                // such employee".
                return str_contains(
                    mb_strtolower("{$e->employee_code} ".($e->employee_id_no ?? '')." {$name} ".($e->position ?? '')),
                    $needle,
                );
            })
            ->values();
    }

    /**
     * Records Management's list, plus any job title an active employee still
     * carries that has since left it (add-employees-dialog.tsx:59-67).
     *
     * @param  Collection<int, EmployeeProfile>  $activeEmployees
     * @return list<string>
     */
    private function pickerPositions(Collection $activeEmployees): array
    {
        $names = [];

        foreach (app(OrganizationDirectory::class)->positions(app(Access::class), ordered: false) as $name) {
            $names[(string) $name] = true;
        }

        foreach ($activeEmployees as $employee) {
            if ($employee->position !== null && $employee->position !== '') {
                $names[$employee->position] = true;
            }
        }

        $positions = array_keys($names);
        usort($positions, static fn (string $a, string $b): int => PayrollData::compareText($a, $b));

        return $positions;
    }

    /**
     * The schedule notices beside the grid, in the person's words: who is
     * judged by the system default on how many dates, and whose schedule
     * changed after the draft was last saved.
     *
     * @param  Collection<int|string, EmployeePayData>  $employees
     * @return array{unscheduled: list<string>, changed: list<string>, since: string}
     */
    private function scheduleWarnings(Access $actor, ?PayrollRunView $run, Collection $employees): array
    {
        $notices = app(ScheduleNotices::class);
        $name = static fn (string $id): string => PayrollData::employeeName($employees->get($id));

        $unscheduled = [];
        foreach ($notices->unscheduledDays($actor, $this->members, $this->start, $this->end) as $employeeId => $days) {
            $unscheduled[] = $name($employeeId).' ('.$days.' '.($days === 1 ? 'day' : 'days').')';
        }

        $changed = [];
        $savedAt = $run === null ? null : ($run->updated_at ?? $run->created_at);
        if ($savedAt !== null) {
            foreach ($notices->changedSince($actor, $this->members, $this->start, $this->end, $savedAt) as $employeeId => $at) {
                $changed[] = $name($employeeId);
            }
        }

        return [
            'unscheduled' => $unscheduled,
            'changed' => $changed,
            'since' => $savedAt?->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') ?? '',
        ];
    }
}
