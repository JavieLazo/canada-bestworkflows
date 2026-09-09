<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayrollValidation;
use App\Domain\Payroll\PayslipDraft;
use App\Livewire\Payroll\Support\PayrollData;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Position;
use App\Models\User;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
     * Manager-typed statutory overrides per employee id.
     *
     * @var array<string, array<string, float>>
     */
    #[Locked]
    public array $draftDeductions = [];

    /**
     * Hand-entered earnings + the overtime-acknowledged switch per employee id.
     *
     * @var array<string, array<string, mixed>>
     */
    #[Locked]
    public array $draftEarnings = [];

    /**
     * What the database holds, for the `dirty` flag. Empty while creating —
     * a new workspace is dirty by definition (run-workspace.tsx:201-209).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $savedState = [];

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

    public function mount(?string $id = null): void
    {
        if ($id === null) {
            // thisMonth() defaults (run-workspace.tsx:47-50, 141-142) — the
            // first cut-off of the current Manila month.
            $month = substr(PayrollData::todayKey(), 0, 7);
            $this->start = "{$month}-01";
            $this->end = "{$month}-15";

            return;
        }

        $run = PayrollRun::query()->with('members')->whereKey($id)->first();

        if ($run === null) {
            $this->missing = true;

            return;
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

        $this->savedState = $this->currentState();
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

        $this->draftDeductions[$employeeId][$field] = self::asMoney($value);
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

        $this->draftEarnings[$employeeId][$field] = self::asMoney($value);
    }

    /** Computed overtime stays out of gross pay until it is acknowledged here (run-workspace.tsx:394-400). */
    public function acknowledgeOvertime(string $employeeId, bool $acknowledged): void
    {
        if ($this->isFinalized() || ! in_array($employeeId, $this->members, true)) {
            return;
        }

        $this->draftEarnings[$employeeId]['overtime_acknowledged'] = $acknowledged;
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
        );
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
        $exists = EmployeeProfile::query()
            ->whereKey($employeeId)
            ->where('is_active', true)
            ->exists();

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

        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(text: 'Your role cannot manage organization-wide data.', variant: 'danger');

            return null;
        }

        $name = trim($this->name);

        if ($name === '') {
            Flux::toast(text: 'Give the payroll a name.', variant: 'danger');

            return null;
        }

        $cutoffError = PayrollPeriod::cutoffError($this->start, $this->end);

        if ($cutoffError !== null) {
            Flux::toast(text: $cutoffError, variant: 'danger');

            return null;
        }

        $deductions = $this->activeDeductions();
        $allowances = $this->activeAllowances();
        $earnings = $this->activeEarnings();

        // requirePayrollOverrideMembers — structurally always clean here (the
        // active maps are filtered by membership), kept for parity with the route.
        $overrideError = PayrollValidation::overrideMembersError($this->members, $deductions, $allowances, $earnings);

        if ($overrideError !== null) {
            Flux::toast(text: $overrideError, variant: 'danger');

            return null;
        }

        $members = $this->members;
        $runId = $this->runId;
        $start = $this->start;
        $end = $this->end;

        try {
            $result = DB::transaction(function () use ($runId, $name, $start, $end, $members, $deductions, $allowances, $earnings): array {
                PayrollData::lockMembership();

                $this->guardMembership($members, $start, $end, $runId);

                if ($runId !== null) {
                    $existing = PayrollRun::query()->whereKey($runId)->lockForUpdate()->first();

                    if ($existing === null) {
                        throw new RuntimeException('That payroll no longer exists.');
                    }

                    if ($existing->status === PayrollRunStatus::FINALIZED) {
                        throw new RuntimeException('That payroll is finalized and can no longer be edited.');
                    }

                    $updated = PayrollRun::query()
                        ->whereKey($runId)
                        ->where('status', PayrollRunStatus::DRAFT)
                        ->update([
                            'name' => $name,
                            'period_start' => $start,
                            'period_end' => $end,
                        ]);

                    if ($updated === 0) {
                        throw new RuntimeException('That payroll changed before the save completed.');
                    }

                    $this->writeMembers($runId, $members, $start, $end, $deductions, $allowances, $earnings);

                    return ['id' => $runId, 'created' => false];
                }

                // The server assigns the authoritative code (runs/route.ts:47-51).
                $code = PayrollData::nextCode('PR', PayrollRun::query()->pluck('code'));

                $run = new PayrollRun;
                $run->fill([
                    'code' => $code,
                    'name' => $name,
                    'period_start' => $start,
                    'period_end' => $end,
                    'status' => PayrollRunStatus::DRAFT,
                ])->save();

                $this->writeMembers($run->id, $members, $start, $end, $deductions, $allowances, $earnings);

                return ['id' => $run->id, 'created' => true];
            });
        } catch (QueryException $e) {
            // Caught BEFORE RuntimeException — QueryException descends from it
            // via PDOException. payroll_runs.name (and code) are UNIQUE in this
            // schema — the reference had no such constraint; surface a
            // message, not a 500.
            $text = str_contains($e->getMessage(), 'payroll_runs_name_unique')
                ? 'That payroll name is already in use.'
                : 'That payroll could not be saved. Try again.';
            Flux::toast(text: $text, variant: 'danger');
            Log::warning('[payroll] save rejected by the database', ['error' => $e->getMessage()]);

            return null;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return null;
        }

        $this->name = $name;

        if ($result['created']) {
            AuditService::record(
                $actor,
                AuditEvent::PAYROLL_RUN_CREATED,
                'payroll_runs',
                $result['id'],
                "{$name} created as a draft with ".count($members).' employees',
            );
            Flux::toast(text: 'Payroll saved as a draft.', variant: 'success');

            // router.replace(`/payroll/${created.id}`) (run-workspace.tsx:316).
            return $this->redirectRoute('payroll.show', ['id' => $result['id']], navigate: true);
        }

        AuditService::record(
            $actor,
            AuditEvent::PAYROLL_RUN_UPDATED,
            'payroll_runs',
            $result['id'],
            "{$name} saved",
        );

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
     * attendance INSIDE the transaction; nothing the browser holds is posted.
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

        $cutoffError = PayrollPeriod::cutoffError($this->start, $this->end);

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

        $runId = $this->runId;

        try {
            $result = DB::transaction(function () use ($runId, $actor): array {
                PayrollData::lockMembership();

                $run = PayrollRun::query()->with('members')->whereKey($runId)->lockForUpdate()->first();

                if ($run === null) {
                    throw new RuntimeException('That payroll no longer exists.');
                }

                if ($run->status === PayrollRunStatus::FINALIZED) {
                    throw new RuntimeException('That payroll is already finalized.');
                }

                $memberIds = PayrollData::memberIds($run->members);

                if ($memberIds === []) {
                    throw new RuntimeException('Add at least one employee first.');
                }

                // The route's zod re-parse of the stored JSON has no analogue:
                // the overrides live in typed DECIMAL columns, so "invalid
                // draft adjustments" is unrepresentable by construction.
                $overrides = PayrollData::overridesFromMembers($run->members);

                $periodStart = $run->period_start->format('Y-m-d');
                $periodEnd = $run->period_end->format('Y-m-d');

                $cutoffError = PayrollPeriod::cutoffError($periodStart, $periodEnd);

                if ($cutoffError !== null) {
                    throw new RuntimeException($cutoffError);
                }

                $this->guardMembership($memberIds, $periodStart, $periodEnd, $run->id);

                // THE computation — the same domain path the draft grid renders.
                $calculation = PayrollData::calculateDrafts(
                    $memberIds,
                    $periodStart,
                    $periodEnd,
                    $overrides['deductions'],
                    $overrides['allowances'],
                    $overrides['earnings'],
                    strict: true,
                );

                if ($calculation['combined'] !== []) {
                    $issue = $calculation['combined'][0];
                    $employee = $calculation['employees']->firstWhere('id', $issue['employee_id']);
                    $who = $employee !== null ? PayrollData::employeeName($employee) : 'An employee';

                    throw new RuntimeException("{$who}: {$issue['message']}");
                }

                // Clear, post, lock — the three writes, one transaction.
                Payslip::query()->where('run_id', $run->id)->delete();

                foreach ($calculation['drafts'] as $draft) {
                    $payslip = new Payslip;
                    $payslip->fill(array_merge($draft->toArray(), [
                        'run_id' => $run->id,
                        // The pre-additive engine's columns stay pinned to 0 —
                        // their reductions live inside basic_pay now.
                        'late_undertime_deduction' => 0,
                        'absence_deduction' => 0,
                        'unpaid_leave_deduction' => 0,
                    ]))->save();
                }

                $flipped = PayrollRun::query()
                    ->whereKey($run->id)
                    ->where('status', PayrollRunStatus::DRAFT)
                    ->update([
                        'status' => PayrollRunStatus::FINALIZED,
                        // UTC at the DATETIME(3) precision the column declares.
                        'finalized_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
                        // Actor column: from the session-derived Access, never the request.
                        'finalized_by' => (int) $actor->userId,
                    ]);

                if ($flipped === 0) {
                    throw new RuntimeException('That payroll changed before finalization completed.');
                }

                return [
                    'run' => $run,
                    'drafts' => $calculation['drafts'],
                    'periodStart' => $periodStart,
                    'periodEnd' => $periodEnd,
                ];
            });
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        /** @var PayrollRun $run */
        $run = $result['run'];
        $drafts = $result['drafts'];

        AuditService::record(
            $actor,
            AuditEvent::PAYROLL_FINALIZED,
            'payroll_runs',
            $run->id,
            "{$run->name} finalized — ".count($drafts).' payslips',
        );

        foreach ($drafts as $draft) {
            $this->notifyEmployee($draft->employee_id, [
                'kind' => 'PAYSLIP_POSTED',
                'title' => "{$run->code} payslip posted",
                'body' => "{$run->name} · {$result['periodStart']} to {$result['periodEnd']}",
                'href' => '/portal?tab=payslips',
            ]);
        }

        Flux::toast(text: 'Finalized — '.count($drafts).' payslips posted.', variant: 'success');
    }

    /* ------------------------------------------------------------------ */
    /* Export */
    /* ------------------------------------------------------------------ */

    /**
     * Export CSV (run-workspace.tsx:414-498) — the grid's lines, one row per
     * payslip, streamed as an RFC 4180 file with the reference's exact header
     * row, formula-guarding and UTF-8 BOM.
     */
    public function exportCsv(): ?StreamedResponse
    {
        $actor = app(Access::class);

        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            Flux::toast(text: 'Your role cannot manage organization-wide data.', variant: 'danger');

            return null;
        }

        [$lines, $employees] = $this->linesForDisplay($actor);

        if (count($lines) === 0) {
            Flux::toast(text: 'Nothing to export yet.', variant: 'danger');

            return null;
        }

        $run = $this->runId !== null ? PayrollRun::query()->whereKey($this->runId)->first() : null;
        $base = ($run->code ?? trim($this->name)) !== '' ? ($run->code ?? trim($this->name)) : 'draft';
        $filename = 'bestworkflows-payroll-'.mb_strtolower($base).'.csv';

        $headers = [
            'Employee code', 'Employee', 'Period start', 'Period end', 'Daily rate',
            'Work days', 'Holidays worked', 'Rest days worked', 'Absences', 'Unpaid leave days',
            'Work days pay', 'Holiday pay', 'Rest day pay', 'Basic salary', 'Allowance', 'Total',
            'Overtime hours', 'Overtime pay', 'Overtime acknowledged', 'NSD', 'Leave',
            'Other earnings', 'Gross income', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding tax',
            'Other deductions', 'Undertime minutes', 'Undertime deduction', 'Late minutes',
            'Late deduction', 'Total deductions', 'Net pay',
        ];

        $rows = [];

        foreach ($lines as $p) {
            $e = $employees->get($p->employee_id);
            $rows[] = [
                $e->employee_code ?? '',
                PayrollData::employeeName($e),
                $p->period_start instanceof \DateTimeInterface ? $p->period_start->format('Y-m-d') : $p->period_start,
                $p->period_end instanceof \DateTimeInterface ? $p->period_end->format('Y-m-d') : $p->period_end,
                $p->daily_rate,
                $p->work_days, $p->holiday_days, $p->rest_days, $p->absence_days, $p->unpaid_leave_days,
                $p->work_days_pay, $p->holiday_pay, $p->rest_day_pay, $p->basic_pay, $p->allowances, $p->total_base_pay,
                $p->overtime_hours, $p->overtime_pay, $p->overtime_acknowledged ? 'Yes' : 'No',
                $p->night_differential, $p->leave_pay, $p->other_earnings, $p->gross_pay,
                $p->sss, $p->philhealth, $p->pagibig, $p->withholding_tax, $p->other_deductions,
                $p->undertime_minutes, $p->undertime_deduction, $p->late_minutes, $p->late_deduction,
                $p->total_deductions, $p->net_pay,
            ];
        }

        $csv = self::toCsv($headers, $rows);
        $count = count($lines);

        Flux::toast(text: "Exported {$count} payslips.", variant: 'success');

        return response()->streamDownload(static function () use ($csv): void {
            // BOM so Excel opens UTF-8 (₱, é) correctly instead of mojibake.
            echo "\u{FEFF}".$csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------------------ */
    /* Render */
    /* ------------------------------------------------------------------ */

    public function render(): View
    {
        $actor = app(Access::class);

        if ($this->missing || ($this->runId !== null && ! PayrollRun::query()->whereKey($this->runId)->exists())) {
            return view('livewire.payroll.run-missing')->title('Payroll');
        }

        $run = $this->runId !== null ? PayrollRun::query()->whereKey($this->runId)->first() : null;
        $finalized = $run?->status === PayrollRunStatus::FINALIZED;

        $cutoffError = $finalized
            ? null
            : PayrollPeriod::cutoffError($this->start, $this->end);

        $readinessIssue = null;
        $invalidDraft = null;

        if ($finalized) {
            /** A finalised run shows what it posted, not a fresh computation. */
            $lines = array_values(Payslip::query()
                ->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
                ->where('run_id', $run->id)
                ->orderBy('id')
                ->get()
                ->all());

            $employees = EmployeeProfile::query()
                ->whereIn('id', array_map(static fn (object $p): string => $p->employee_id, $lines))
                ->get()
                ->keyBy('id');
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
            );

            $lines = $calculation['drafts'];
            $employees = $calculation['employees']->keyBy('id');

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
        }

        $totals = PayrollData::totals($lines);
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
     * number after that is refused as before.
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
            && PayrollRun::query()->whereKey($this->runId)->value('status') === PayrollRunStatus::FINALIZED;
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
        ];
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
     * requirePayrollEmployees + requireUniquePayrollMembership, inside the
     * caller's transaction. Throws the message the route would have 400/409ed.
     *
     * @param  list<string>  $memberIds
     */
    private function guardMembership(array $memberIds, string $periodStart, string $periodEnd, ?string $excludeRunId): void
    {
        $employees = EmployeeProfile::query()->whereIn('id', $memberIds)->get();

        $error = PayrollValidation::employeesError($memberIds, $employees, $periodStart, $periodEnd);

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        if ($memberIds === []) {
            return;
        }

        // One employee, one payroll per cut-off. Candidates carry their member
        // ids in position order, shaped like the reference's employee_ids.
        $candidates = PayrollRun::query()
            ->with('members')
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->when($excludeRunId !== null, static fn ($q) => $q->whereKeyNot($excludeRunId))
            ->whereHas('members', static fn ($q) => $q->whereIn('employee_id', $memberIds))
            ->orderBy('created_at')
            ->get()
            ->map(static fn (PayrollRun $r): array => [
                'code' => $r->code,
                'employee_ids' => PayrollData::memberIds($r->members),
            ]);

        $conflict = PayrollValidation::findMembershipConflict($memberIds, $candidates);

        if ($conflict !== null) {
            $employee = $employees->firstWhere('id', $conflict['employeeId'])
                ?? EmployeeProfile::query()->whereKey($conflict['employeeId'])->first();

            throw new RuntimeException(PayrollValidation::membershipConflictMessage(
                $employee !== null ? EmployeeName::display($employee) : null,
                $conflict['runCode'],
            ));
        }
    }

    /**
     * Rewrite the run's membership as full snapshot rows — never a bare
     * attach(): position order, the override columns and the period snapshot
     * all travel with each member.
     *
     * @param  list<string>  $memberIds
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  array<string, float>  $allowances
     * @param  array<string, array<string, mixed>>  $earnings
     */
    private function writeMembers(
        string $runId,
        array $memberIds,
        string $periodStart,
        string $periodEnd,
        array $deductions,
        array $allowances,
        array $earnings,
    ): void {
        PayrollRunMember::query()->where('run_id', $runId)->delete();

        foreach ($memberIds as $position => $employeeId) {
            PayrollRunMember::create(PayrollData::memberColumns(
                $runId,
                $employeeId,
                $position,
                $periodStart,
                $periodEnd,
                $deductions,
                $allowances,
                $earnings,
            ));
        }
    }

    /**
     * The grid's lines and their employees, exactly as render() resolves them
     * — shared with the CSV export.
     *
     * @return array{0: list<Payslip|PayslipDraft>, 1: Collection<string, EmployeeProfile>}
     */
    private function linesForDisplay(Access $actor): array
    {
        $run = $this->runId !== null ? PayrollRun::query()->whereKey($this->runId)->first() : null;

        if ($run?->status === PayrollRunStatus::FINALIZED) {
            $lines = array_values(Payslip::query()
                ->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
                ->where('run_id', $run->id)
                ->orderBy('id')
                ->get()
                ->all());

            $employees = EmployeeProfile::query()
                ->whereIn('id', array_map(static fn (Payslip $p): string => $p->employee_id, $lines))
                ->get()
                ->keyBy(static fn (EmployeeProfile $e): string => $e->id);

            return [$lines, $employees];
        }

        $cutoffError = PayrollPeriod::cutoffError($this->start, $this->end);

        $calculation = PayrollData::calculateDrafts(
            $cutoffError !== null ? [] : $this->members,
            $this->start,
            $this->end,
            $this->activeDeductions(),
            $this->activeAllowances(),
            $this->activeEarnings(),
        );

        return [$calculation['drafts'], $calculation['employees']->keyBy(static fn (EmployeeProfile $e): string => $e->id)];
    }

    /**
     * The dialog's base list: active employees, as useEmployees fed it.
     *
     * @return Collection<int, EmployeeProfile>
     */
    private function pickerEmployees(): Collection
    {
        return EmployeeProfile::query()
            ->where('is_active', true)
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'suffix', 'position', 'photo_path']);
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

                return str_contains(mb_strtolower("{$e->employee_code} {$name} ".($e->position ?? '')), $needle);
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

        foreach (Position::query()->pluck('name') as $name) {
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
     * Port of `notifyEmployee` (src/lib/api/notify.ts:44-57): tells whoever
     * holds the login attached to an employee. Fire-and-forget — the payroll
     * really was finalized, and failing it because the bell did not ring would
     * be the worse lie.
     *
     * @param  array{kind: string, title: string, body: ?string, href: string}  $entry
     */
    private function notifyEmployee(string $employeeId, array $entry): void
    {
        try {
            $accountIds = User::query()
                ->where('employee_id', $employeeId)
                ->where('is_active', true)
                ->pluck('id');

            foreach ($accountIds as $accountId) {
                $notification = new Notification;
                $notification->fill([
                    'kind' => $entry['kind'],
                    'title' => $entry['title'],
                    'body' => $entry['body'],
                    'href' => $entry['href'],
                ]);
                // The addressee is server-assigned, never mass-assigned.
                $notification->user_id = (int) $accountId;
                $notification->save();
            }
        } catch (Throwable $e) {
            Log::error('[notify] write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * RFC 4180 with the export.ts formula guard: quote every field, CRLF rows.
     *
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    private static function toCsv(array $headers, array $rows): string
    {
        $cell = static function (mixed $v): string {
            $text = (string) ($v ?? '');

            // Spreadsheets execute a cell that opens with = + - @ or a tab even
            // when quoted; a leading apostrophe makes Excel read it as text.
            if (is_string($v) && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
                $text = "'".$text;
            }

            return '"'.str_replace('"', '""', $text).'"';
        };

        $out = [];

        foreach ([$headers, ...$rows] as $row) {
            $out[] = implode(',', array_map($cell, $row));
        }

        return implode("\r\n", $out);
    }
}
