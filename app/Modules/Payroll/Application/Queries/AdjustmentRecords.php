<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollAdjustment;
use App\Models\PayrollAdjustmentCategory;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Contracts\Views\AdjustmentCategoryView;
use App\Modules\Payroll\Contracts\Views\AdjustmentView;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Collection;

/**
 * The Adjustments page's reads: the ledger behind the payslips' row scope
 * (an all-data PAYROLL holder sees everyone's, a linked holder their own,
 * nobody else anything), newest first, narrowed by status and a search on
 * the employee's name, the category or the reason; one line; the
 * categories (readable by any PAYROLL holder) and the open regular
 * payrolls a line may be targeted at. Writes go through the commands.
 */
final class AdjustmentRecords
{
    /** @return Collection<int, AdjustmentView> */
    public function list(Access $actor, string $status = 'ALL', string $search = ''): Collection
    {
        if (! Adjustments::ready() || ! $actor->hasModule(ModuleCode::PAYROLL)) {
            return new Collection;
        }
        $rows = PayrollAdjustment::query()->with('category')
            ->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->when(in_array($status, AdjustmentRule::STATUSES, true), static fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();
        $views = $this->views($actor, $rows);
        $needle = mb_strtolower(trim($search));

        return $needle === '' ? $views : $views->filter(static fn (AdjustmentView $v): bool => str_contains(mb_strtolower($v->employee_name.' '.$v->employee_code.' '.$v->category_name.' '.$v->reason.' '.($v->reference ?? '')), $needle))->values();
    }

    public function find(Access $actor, string $id): ?AdjustmentView
    {
        if (! Adjustments::ready() || ! $actor->hasModule(ModuleCode::PAYROLL)) {
            return null;
        }
        $row = PayrollAdjustment::query()->with('category')->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)->whereKey($id)->first();

        return $row === null ? null : $this->views($actor, new Collection([$row]))->first();
    }

    /** @return Collection<int, AdjustmentCategoryView> by name */
    public function categories(Access $actor): Collection
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL), 403);
        if (! Adjustments::ready()) {
            return new Collection;
        }

        return PayrollAdjustmentCategory::query()->withCount('adjustments')->orderBy('name')->get()
            ->map(static fn (PayrollAdjustmentCategory $row): AdjustmentCategoryView => Snapshot::of($row, AdjustmentCategoryView::class));
    }

    /**
     * The open regular payrolls a line may be targeted at, newest period first.
     *
     * @return list<array{id: string, code: string, name: string, period: string}>
     */
    public function openRuns(Access $actor): array
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            return [];
        }

        return array_values(PayrollRun::query()->where('status', 'DRAFT')->where('kind', 'REGULAR')->orderByDesc('period_start')->get()
            ->map(static fn (PayrollRun $run): array => ['id' => (string) $run->id, 'code' => $run->code, 'name' => $run->name, 'period' => $run->period_start->format('d M Y').' – '.$run->period_end->format('d M Y')])
            ->all());
    }

    /**
     * @param  Collection<int, PayrollAdjustment>  $rows
     * @return Collection<int, AdjustmentView>
     */
    private function views(Access $actor, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return new Collection;
        }
        $employees = app(OperationalDirectory::class)->list($actor, array_values(array_unique(array_map('strval', $rows->pluck('employee_id')->all()))))->keyBy('id');
        $runIds = $rows->pluck('target_run_id')->filter()->unique()->values()->all();
        $payslipIds = $rows->pluck('payslip_id')->filter()->unique()->values()->all();
        $runCodes = $runIds === [] ? [] : PayrollRun::query()->whereIn('id', $runIds)->pluck('code', 'id')->all();
        $payslipRuns = $payslipIds === [] ? [] : Payslip::query()->with('run')->whereIn('id', $payslipIds)->get()->mapWithKeys(static fn (Payslip $p): array => [$p->id => $p->run?->code])->all();

        return $rows->map(static function (PayrollAdjustment $row) use ($employees, $runCodes, $payslipRuns): AdjustmentView {
            /** @var DirectoryEmployee|null $employee */
            $employee = $employees->get($row->employee_id);

            return Snapshot::of($row, AdjustmentView::class, [
                'employee_name' => $employee === null ? 'A removed employee' : EmployeeName::display($employee),
                'employee_code' => $employee === null ? '' : $employee->employee_code,
                'category_name' => $row->category->name,
                'kind' => $row->category->kind,
                'tax' => $row->category->tax,
                'target_run_code' => $row->target_run_id === null ? null : ($runCodes[$row->target_run_id] ?? null),
                'payslip_run_code' => $row->payslip_id === null ? null : ($payslipRuns[$row->payslip_id] ?? null),
            ]);
        })->values();
    }
}
