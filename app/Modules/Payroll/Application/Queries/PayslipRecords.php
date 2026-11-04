<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Models\PayslipSettlement;
use App\Modules\Payroll\Application\AdvanceSettlements;
use App\Modules\Payroll\Application\DeductionColumns;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Contracts\Views\PayslipDeductionView;
use App\Modules\Payroll\Contracts\Views\PayslipIncentiveView;
use App\Modules\Payroll\Contracts\Views\PayslipSettlementView;
use App\Modules\Payroll\Contracts\Views\PayslipView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/** Posted amounts are read as stored; self-service remains pinned even for payroll administrators. */
final class PayslipRecords
{
    /** @return Collection<int, PayslipView> */
    public function forRun(Access $actor, string $runId): Collection
    {
        return Payslip::query()->with('run:id,code')->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->where('run_id', $runId)->orderBy('id')->get()
            ->map(self::view(...));
    }

    /** @return Collection<int, PayslipView> */
    public function own(Access $actor): Collection
    {
        if (! $actor->hasEmployeeLink()) {
            return collect();
        }

        return Payslip::query()->with('run:id,code')->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->where('employee_id', '=', $actor->ownEmployeeId())->orderByDesc('period_start')->get()
            ->map(self::view(...));
    }

    public function ownVisible(Access $actor, string $id): bool
    {
        return $actor->hasEmployeeLink() && Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->where('employee_id', '=', $actor->ownEmployeeId())->whereKey($id)->exists();
    }

    public function find(Access $actor, string $runId, string $employeeId): ?PayslipView
    {
        $row = Payslip::query()->with('run:id,code')->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->where('run_id', $runId)->where('employee_id', $employeeId)->first();

        return $row === null ? null : self::view($row);
    }

    /** The document's payroll reference comes only from its already-scoped parent run. */
    private static function view(Payslip $row): PayslipView
    {
        return Snapshot::of($row, PayslipView::class, ['payroll_code' => $row->run?->code]);
    }

    /**
     * The incentive and bonus lines each payslip posted, in the order they
     * were allocated against the PHP 90,000 ceiling. Only payslips the actor
     * may see: the ids are scoped first, exactly as the payslips themselves
     * are, and one query then loads every line.
     *
     * @param  list<string>  $payslipIds
     * @return array<string, list<PayslipIncentiveView>> payslip id => lines (absent when none)
     */
    public function incentives(Access $actor, array $payslipIds): array
    {
        if ($payslipIds === []) {
            return [];
        }

        $visible = Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->whereIn('id', $payslipIds)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        if ($visible === []) {
            return [];
        }

        $lines = [];
        foreach (PayslipIncentive::query()->whereIn('payslip_id', $visible)->orderBy('payslip_id')->orderBy('position')->get() as $row) {
            $lines[$row->payslip_id][] = Snapshot::of($row, PayslipIncentiveView::class);
        }

        return $lines;
    }

    /**
     * The immutable named deduction lines of visible posted payslips only.
     * An own-pinned Portal actor cannot use another payslip id to read pay.
     *
     * @param  list<string>  $payslipIds
     * @return array<string, list<PayslipDeductionView>>
     */
    public function customDeductions(Access $actor, array $payslipIds): array
    {
        if ($payslipIds === []) {
            return [];
        }

        $visible = Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->whereIn('id', $payslipIds)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        if ($visible === []) {
            return [];
        }

        $lines = [];
        foreach (PayslipDeduction::query()->whereIn('payslip_id', $visible)->orderBy('payslip_id')->orderBy('position')->get() as $row) {
            $lines[$row->payslip_id][] = Snapshot::of($row, PayslipDeductionView::class);
        }

        return $lines;
    }

    /**
     * The settlements each visible posted payslip made of earlier presumed
     * days (AdvanceSettlements), oldest settled payslip first. Scoped like
     * `customDeductions()`; nothing before the ledger exists.
     *
     * @param  list<string>  $payslipIds
     * @return array<string, list<PayslipSettlementView>>
     */
    public function settlements(Access $actor, array $payslipIds): array
    {
        if ($payslipIds === [] || ! AdvanceSettlements::ready()) {
            return [];
        }

        $visible = Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->whereIn('id', $payslipIds)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        if ($visible === []) {
            return [];
        }

        $lines = [];
        foreach (PayslipSettlement::query()->whereIn('payslip_id', $visible)->orderBy('payslip_id')->orderBy('from_date')->get() as $row) {
            $lines[$row->payslip_id][] = Snapshot::of($row, PayslipSettlementView::class);
        }

        return $lines;
    }

    /**
     * Posted payslips whose presumed days no later payslip has settled yet,
     * for the Payroll Settings Overview's "needs attention": each with the
     * employee, the payroll's code and the days. An employee's pay, so
     * all-data PAYROLL only; nothing before the ledger exists.
     *
     * @return list<array{employee_id: string, code: string, from: string, to: string, period_end: string}>
     */
    public function unsettledAdvances(Access $actor): array
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL) || ! AdvanceSettlements::ready()) {
            return [];
        }
        $rows = Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->whereNotNull('attendance_through')
            ->whereColumn('attendance_through', '<', 'period_end')
            ->whereHas('run', static fn ($run) => $run->where('kind', PayrollRunKind::REGULAR)->where('status', PayrollRunStatus::FINALIZED))
            ->whereDoesntHave('settledBy')
            ->with('run:id,code')
            ->orderBy('period_start')->orderBy('employee_id')
            ->get(['id', 'run_id', 'employee_id', 'period_end', 'attendance_through']);
        $pending = [];
        foreach ($rows as $row) {
            if ($row->attendance_through === null) {
                continue;
            }
            $pending[] = [
                'employee_id' => $row->employee_id,
                'code' => $row->run->code ?? '',
                'from' => CalendarDays::next($row->attendance_through->format('Y-m-d')),
                'to' => $row->period_end->format('Y-m-d'),
                'period_end' => $row->period_end->format('Y-m-d'),
            ];
        }

        return $pending;
    }

    /**
     * The Deductions columns of posted payslips: the Payroll Deductions
     * list AS IT STOOD when their run was finalized, read from the run's
     * evidence (`deduction_columns`), so a finalized payroll never changes
     * when the list does. A run whose evidence has no such key, or a
     * payslip with no run, shows the statutory columns under their default
     * labels and the typed lines it posted. Scoped like
     * `customDeductions()`: only the visible payslips' lines and run
     * contribute.
     *
     * @param  list<string>  $payslipIds
     * @return Collection<int, DeductionColumnView>
     */
    public function deductionColumns(Access $actor, array $payslipIds): Collection
    {
        if ($payslipIds === []) {
            return new Collection;
        }

        $visible = Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->whereIn('id', $payslipIds)->get(['id', 'run_id']);
        if ($visible->isEmpty()) {
            return new Collection;
        }

        $visibleIds = array_values(array_map(static fn (mixed $id): string => (string) $id, $visible->pluck('id')->all()));
        $posted = [];
        foreach ($this->customDeductions($actor, $visibleIds) as $lines) {
            foreach ($lines as $line) {
                $posted[] = $line;
            }
        }

        $runIds = $visible->pluck('run_id')->filter()->unique()->values();
        if ($runIds->count() === 1) {
            $recorded = $this->recordedColumns((string) $runIds->first());
            if ($recorded !== null) {
                return DeductionColumns::fromEvidence($recorded, $posted);
            }
        }

        return DeductionColumns::fromPosted($posted);
    }

    /**
     * The `deduction_columns` a run's finalization evidence recorded, or
     * null for a run whose evidence has no such key.
     *
     * @return list<mixed>|null
     */
    private function recordedColumns(string $runId): ?array
    {
        $payload = PayrollFinalizationSnapshot::query()->where('run_id', $runId)->orderByDesc('created_at')->value('payload');
        if (! is_string($payload) || $payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);
        $columns = is_array($decoded) ? ($decoded['deduction_columns'] ?? null) : null;

        return is_array($columns) ? array_values($columns) : null;
    }
}
