<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Payroll\PayslipDraft;
use App\Livewire\Payroll\Support\PayrollData;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PayrollReview;
use App\Modules\Payroll\Application\PayrollReviewRequired;
use App\Modules\Payroll\Application\Queries\PayrollRecords;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Application\ThirteenthMonthData;
use App\Modules\Payroll\Application\ThirteenthMonthInputs;
use App\Modules\Payroll\Contracts\Views\PayslipView;
use App\Modules\Workforce\Application\Queries\PayrollEmployees;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\Csv;
use App\Support\EmployeeIdentity;
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
 * One 13th month run, being created or opened again — "13th Month Payroll"
 * in the sidebar. The reference has no 13th month run.
 *
 * A run covers 1 January to 31 December of one year. Each member is paid
 * 1/12 of the basic pay they earned that year: what EMS's finalized regular
 * payslips paid, plus a figure typed here for the months paid outside EMS.
 * Up to the PHP 90,000 yearly exemption it is tax-free; the excess is taxed
 * at the member's marginal rate, a figure the officer may overtype. Every
 * amount comes from Payroll's ThirteenthMonthData — the same path the CSV and
 * finalize use; this component holds only the draft state. Finalize works as
 * on a regular run: saved state only, a server receipt for the displayed
 * inputs, then evidence, payslips, status and audit in one transaction.
 *
 * Anyone employed at some point of the year may be added, resigned staff
 * included (PayrollEmployees::employedInYear).
 */
#[Layout('layouts.app')]
class ThirteenthMonthWorkspace extends Component
{
    /** The open run's id, or null while creating. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $runId = null;

    /** Route id named a run that is gone. */
    #[Locked]
    public bool $missing = false;

    /** Payroll name. Free text the user edits. */
    public string $name = '';

    /** The calendar year the run covers. Form state; SavePayroll checks it. */
    public int $year = 0;

    /**
     * Member employee ids, in grid order. Id-bearing → #[Locked]; mutated only
     * by removeMember() and applyPicked(), which admit only employees of the
     * year.
     *
     * @var list<string>
     */
    #[Locked]
    public array $members = [];

    /**
     * Basic pay earned outside EMS per member. Keyed by id → #[Locked];
     * written only through updateOutside().
     *
     * @var array<string, float>
     */
    #[Locked]
    public array $outsideBasicPay = [];

    /**
     * Typed withholding per member — an override of the computed figure.
     * Keyed by id → #[Locked]; written only through updateWithholding().
     *
     * @var array<string, float>
     */
    #[Locked]
    public array $withholding = [];

    /** @var array<string, mixed> What the database holds, for the dirty flag. */
    #[Locked]
    public array $savedState = [];

    /** Server-signed receipt for the displayed inputs. */
    #[Locked]
    public ?string $previewReceipt = null;

    /** A stale preview needs its own review before another finalize. */
    #[Locked]
    public bool $reviewRequired = false;

    /** Whether the add-employees dialog is showing. */
    public bool $picking = false;

    /** Dialog search. */
    public string $pickSearch = '';

    /** Dialog position filter. */
    public string $pickPosition = 'ALL';

    /**
     * The dialog's working selection. Id-bearing → #[Locked]; mutated only by
     * the toggles, which admit only employees of the run's year.
     *
     * @var list<string>
     */
    #[Locked]
    public array $picked = [];

    public function mount(?string $id = null): mixed
    {
        if ($id === null) {
            $this->year = (int) substr(PayrollData::todayKey(), 0, 4);
            $this->name = "13th Month Pay {$this->year}";

            return null;
        }

        $run = app(PayrollRecords::class)->find(app(Access::class), $id, withMembers: true);
        if ($run === null) {
            $this->missing = true;

            return null;
        }
        if (($run->kind ?? PayrollRunKind::REGULAR) !== PayrollRunKind::THIRTEENTH_MONTH) {
            return $this->redirectRoute('payroll.show', ['id' => $run->id], navigate: true);
        }

        $this->runId = $run->id;
        $this->name = $run->name;
        $this->year = (int) $run->period_start->format('Y');
        $this->members = PayrollData::memberIds($run->members);

        $overrides = PayrollData::overridesFromMembers($run->members);
        $this->outsideBasicPay = $overrides['additional'];
        foreach ($overrides['deductions'] as $employeeId => $lines) {
            if (isset($lines['withholding_tax'])) {
                $this->withholding[$employeeId] = (float) $lines['withholding_tax'];
            }
        }

        $this->savedState = $this->currentState();

        return null;
    }

    /* ---- Grid edits: component state only, validated again by SavePayroll ---- */

    /** The basic pay earned outside EMS; a blank clears it. */
    public function updateOutside(string $employeeId, mixed $value): void
    {
        if ($this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            return;
        }
        $amount = self::money($value);
        if ($amount === null) {
            unset($this->outsideBasicPay[$employeeId]);

            return;
        }
        $this->outsideBasicPay[$employeeId] = $amount;
    }

    /** A typed withholding overrides the computed one; a blank returns to it. */
    public function updateWithholding(string $employeeId, mixed $value): void
    {
        if ($this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            return;
        }
        $amount = self::money($value);
        if ($amount === null) {
            unset($this->withholding[$employeeId]);

            return;
        }
        $this->withholding[$employeeId] = $amount;
    }

    public function removeMember(string $employeeId): void
    {
        if ($this->isFinalized()) {
            return;
        }
        $this->members = array_values(array_filter($this->members, static fn (string $id): bool => $id !== $employeeId));
        unset($this->outsideBasicPay[$employeeId], $this->withholding[$employeeId]);
    }

    /* ---- Add-employees dialog ---- */

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

    /** Ticking adds an employee of the run's year; unticking takes them off again. */
    public function togglePick(string $employeeId): void
    {
        $admissible = app(PayrollEmployees::class)->admissibleInYear(app(Access::class), $employeeId, $this->year);

        if (! $admissible && ! in_array($employeeId, $this->picked, true)) {
            return;
        }

        $this->picked = in_array($employeeId, $this->picked, true)
            ? array_values(array_filter($this->picked, static fn (string $id): bool => $id !== $employeeId))
            : [...$this->picked, $employeeId];
    }

    /** Select-all works on what the filters show — every one of them an employee of the year. */
    public function togglePickShown(): void
    {
        $shown = $this->pickerVisible($this->pickerEmployees())->pluck('id')->all();
        if ($shown === []) {
            return;
        }
        $this->picked = array_diff($shown, $this->picked) === []
            ? array_values(array_diff($this->picked, $shown))
            : array_values([...$this->picked, ...array_diff($shown, $this->picked)]);
    }

    public function applyPicked(): void
    {
        if ($this->isFinalized()) {
            return;
        }
        $this->members = $this->picked;
        $this->picking = false;
    }

    /* ---- Save, finalize, review ---- */

    public function save(): mixed
    {
        $actor = app(Access::class);
        try {
            $result = app(SavePayroll::class)->handle($actor, new PayrollDraft(
                $this->runId, $this->name, sprintf('%04d-01-01', $this->year), sprintf('%04d-12-31', $this->year),
                $this->members,
                array_map(static fn (float $tax): array => ['withholding_tax' => $tax], $this->activeWithholding()),
                [], [], [],
                PayrollRunKind::THIRTEENTH_MONTH,
                $this->activeOutside(),
            ));
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
            Flux::toast(text: '13th month payroll saved as a draft.', variant: 'success');

            return $this->redirectRoute('payroll.thirteenth.show', ['id' => $result['id']], navigate: true);
        }
        $this->savedState = $this->currentState();
        Flux::toast(text: 'Payroll saved.', variant: 'success');

        return null;
    }

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
        Flux::toast(text: 'Finalized — '.$count.' 13th month payslips posted.', variant: 'success');
    }

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

    /** The grid as a CSV: one row per member, the figures the screen shows. */
    public function exportCsv(): ?StreamedResponse
    {
        $actor = app(Access::class);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(text: 'Your role cannot manage organization-wide data.', variant: 'danger');

            return null;
        }

        ['lines' => $lines, 'employees' => $employees, 'statutory' => $statutory] = $this->linesForDisplay($actor);
        if ($lines === []) {
            Flux::toast(text: 'Nothing to export yet.', variant: 'danger');

            return null;
        }

        $headers = ['ID', 'Employee ID', 'Employee', 'Year', 'Basic pay in EMS', 'Basic pay outside EMS', 'Basic pay for the year',
            '13th month pay', 'Tax-exempt', 'Over PHP 90,000', 'Withholding tax', 'Net pay'];
        $rows = [];
        foreach ($lines as $p) {
            $e = $employees->get($p->employee_id);
            $basis = $statutory[$p->employee_id]['basis'] ?? null;
            $rows[] = [
                $e->employee_code ?? '', EmployeeIdentity::besideName($e), PayrollData::employeeName($e), $this->year,
                $basis['ems'] ?? '', $basis['outside'] ?? '', $basis['total'] ?? '',
                $p->thirteenth_month, $p->exempt_benefits, $p->taxable_benefits_excess, $p->withholding_tax, $p->net_pay,
            ];
        }

        $run = $this->runId !== null ? app(PayrollRecords::class)->find($actor, $this->runId) : null;
        $filename = 'cyberonions-13th-month-'.mb_strtolower((string) ($run->code ?? $this->year)).'.csv';
        $csv = Csv::build($headers, $rows);
        Flux::toast(text: 'Exported '.count($lines).' lines.', variant: 'success');

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        $actor = app(Access::class);
        if ($this->missing || ($this->runId !== null && app(PayrollRecords::class)->find($actor, $this->runId) === null)) {
            return view('livewire.payroll.run-missing', [
                'backRoute' => 'payroll.thirteenth.index',
                'backLabel' => 'Back to 13th Month Payroll',
            ])->title('13th Month Payroll');
        }

        $run = $this->runId !== null ? app(PayrollRecords::class)->find($actor, $this->runId) : null;
        $finalized = $run?->status === PayrollRunStatus::FINALIZED;
        $this->previewReceipt = null;

        ['lines' => $lines, 'employees' => $employees, 'statutory' => $statutory, 'issue' => $issue, 'inputs' => $inputs] = $this->linesForDisplay($actor);
        if (! $finalized && $this->runId !== null && ! $this->isDirty() && $inputs !== null) {
            $this->previewReceipt = PayrollReview::issue($actor, $this->runId, $this->name, $inputs);
        }

        $pickerRows = new Collection;
        $pickerPositions = [];
        $allShown = false;
        if ($this->picking && ! $finalized) {
            $all = $this->pickerEmployees();
            $pickerRows = $this->pickerVisible($all);
            $pickerPositions = $this->pickerPositions($all);
            $allShown = $pickerRows->isNotEmpty() && array_diff($pickerRows->pluck('id')->all(), $this->picked) === [];
        }

        $year = (int) substr(PayrollData::todayKey(), 0, 4);

        return view('livewire.payroll.thirteenth-month-workspace', [
            'run' => $run,
            'finalized' => $finalized,
            'lines' => $lines,
            'employees' => $employees,
            'statutory' => $statutory,
            'issue' => $issue,
            'totals' => PayrollData::totals($lines),
            'cutoffError' => $finalized ? null : app(StatutoryTables::class)->yearlyCutoffError($this->year),
            'years' => array_values(array_unique([$year - 1, $year, $this->year])),
            'dirty' => $this->isDirty(),
            'pickerRows' => $pickerRows,
            'pickerPositions' => $pickerPositions,
            'allShown' => $allShown,
        ])->title($run !== null ? '13th Month Payroll' : 'Create 13th month payroll');
    }

    /* ---- Internals ---- */

    /**
     * The grid's lines: what a finalized run posted, else the fresh
     * computation over the current (possibly unsaved) state.
     *
     * @return array{
     *     lines: list<PayslipView>|list<PayslipDraft>,
     *     employees: Collection<string, DirectoryEmployee>|Collection<string, EmployeePayData>,
     *     statutory: array<string, array<string, mixed>>,
     *     issue: ?array{employee: string, message: string},
     *     inputs: ?ThirteenthMonthInputs,
     * }
     */
    private function linesForDisplay(Access $actor): array
    {
        $run = $this->runId !== null ? app(PayrollRecords::class)->find($actor, $this->runId) : null;

        if ($run?->status === PayrollRunStatus::FINALIZED) {
            $lines = array_values(app(PayslipRecords::class)->forRun($actor, $run->id)->all());
            $employees = app(OperationalDirectory::class)->list($actor, array_map(static fn (PayslipView $p): string => $p->employee_id, $lines))
                ->keyBy(static fn (DirectoryEmployee $e): string => $e->id);

            return ['lines' => $lines, 'employees' => $employees, 'statutory' => [], 'issue' => null, 'inputs' => null];
        }

        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)
            || app(StatutoryTables::class)->yearlyCutoffError($this->year) !== null) {
            return ['lines' => [], 'employees' => new Collection, 'statutory' => [], 'issue' => null, 'inputs' => null];
        }

        $calculation = ThirteenthMonthData::calculateDrafts($this->members, $this->year, $this->activeOutside(), $this->activeWithholding(), actor: $actor);
        $employees = $calculation['employees']->keyBy(static fn (EmployeePayData $e): string => $e->id);
        $issue = null;
        if ($calculation['combined'] !== []) {
            $first = $calculation['combined'][0];
            $issue = ['employee' => PayrollData::employeeName($employees->get($first['employee_id'])), 'message' => $first['message']];
        }

        return ['lines' => $calculation['drafts'], 'employees' => $employees, 'statutory' => $calculation['statutory'], 'issue' => $issue, 'inputs' => $calculation['inputs']];
    }

    /** A typed peso figure, or null for a blank (or unreadable) cell. */
    private static function money(mixed $value): ?float
    {
        $text = is_string($value) ? str_replace(',', '', trim($value)) : $value;
        if ($text === null || $text === '' || ! is_numeric($text) || ! is_finite((float) $text)) {
            return null;
        }

        return max(0.0, (float) $text);
    }

    private function isFinalized(): bool
    {
        return $this->runId !== null
            && app(PayrollRecords::class)->find(app(Access::class), $this->runId)?->status === PayrollRunStatus::FINALIZED;
    }

    private function isDirty(): bool
    {
        return $this->savedState === [] || $this->currentState() != $this->savedState;
    }

    /** @return array<string, mixed> */
    private function currentState(): array
    {
        return [
            'name' => $this->name,
            'year' => $this->year,
            'members' => $this->members,
            'outside' => $this->activeOutside(),
            'withholding' => $this->activeWithholding(),
        ];
    }

    /** @return array<string, float> members only */
    private function activeOutside(): array
    {
        return array_intersect_key($this->outsideBasicPay, array_flip($this->members));
    }

    /** @return array<string, float> members only */
    private function activeWithholding(): array
    {
        return array_intersect_key($this->withholding, array_flip($this->members));
    }

    /** @return Collection<int, DirectoryEmployee> everyone employed at some point of the year */
    private function pickerEmployees(): Collection
    {
        return app(PayrollEmployees::class)->employedInYear(app(Access::class), $this->year);
    }

    /**
     * @param  Collection<int, DirectoryEmployee>  $employees
     * @return Collection<int, DirectoryEmployee>
     */
    private function pickerVisible(Collection $employees): Collection
    {
        $needle = mb_strtolower(trim($this->pickSearch));

        return $employees
            ->filter(fn (DirectoryEmployee $e): bool => $this->pickPosition === 'ALL' || $e->position === $this->pickPosition)
            ->filter(static fn (DirectoryEmployee $e): bool => $needle === '' || str_contains(
                mb_strtolower("{$e->employee_code} ".($e->employee_id_no ?? '').' '.PayrollData::employeeName($e).' '.($e->position ?? '')),
                $needle,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, DirectoryEmployee>  $employees
     * @return list<string>
     */
    private function pickerPositions(Collection $employees): array
    {
        $names = [];
        foreach (app(OrganizationDirectory::class)->positions(app(Access::class), ordered: false) as $name) {
            $names[(string) $name] = true;
        }
        foreach ($employees as $employee) {
            if ($employee->position !== null && $employee->position !== '') {
                $names[$employee->position] = true;
            }
        }
        $positions = array_keys($names);
        usort($positions, static fn (string $a, string $b): int => PayrollData::compareText($a, $b));

        return $positions;
    }
}
