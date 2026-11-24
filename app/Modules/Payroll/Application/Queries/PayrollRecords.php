<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberDeduction;
use App\Models\PayrollRunMemberIncentive;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Contracts\Views\PayrollRunMemberView;
use App\Modules\Payroll\Contracts\Views\PayrollRunView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PayrollRecords
{
    /**
     * Every run, whatever its kind: the Payroll list.
     *
     * @return Collection<int, PayrollRunView>
     */
    public function list(Access $actor): Collection
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            return collect();
        }

        return PayrollRun::query()->withCount('members')
            ->orderByDesc('period_start')->get()
            ->map(static fn (PayrollRun $row): PayrollRunView => Snapshot::of($row, PayrollRunView::class));
    }

    public function find(Access $actor, string $id, bool $withMembers = false): ?PayrollRunView
    {
        $query = PayrollRun::query()->whereKey($id);
        $all = $actor->mayReadAllData(ModuleCode::PAYROLL);
        if (! $all) {
            if (! $actor->hasEmployeeLink()) {
                return null;
            }
            $query->whereHas('members', static fn (Builder $members) => $members->where('employee_id', $actor->ownEmployeeId()));
        }
        if ($withMembers) {
            $query->with(['members' => static function ($members) use ($all, $actor): void {
                if (! $all) {
                    $members->where('employee_id', $actor->ownEmployeeId());
                }
            }, 'members.incentives', 'members.deductions']);
        }
        $row = $query->first();

        return $row === null ? null : Snapshot::of($row, PayrollRunView::class, [
            'members' => $withMembers ? $row->members->map(static fn (PayrollRunMember $member): PayrollRunMemberView => Snapshot::of($member, PayrollRunMemberView::class, [
                // The member's incentive lines: type id => amount.
                'incentives' => $member->incentives
                    ->mapWithKeys(static fn (PayrollRunMemberIncentive $line): array => [$line->type_id => $line->amount])
                    ->all(),
                'deductions' => $member->deductions
                    ->mapWithKeys(static fn (PayrollRunMemberDeduction $line): array => [$line->deduction_id => $line->amount])
                    ->all(),
            ])) : collect(),
        ]);
    }

    /**
     * Whether another OPEN payroll that ends earlier in the same year also
     * pays a tax-exempt incentive to any of these employees. The
     * PHP 90,000 exemption is spent in the order payrolls are FINALIZED,
     * so finalizing this one first would spend it out of calendar order —
     * the workspace warns, and never blocks.
     *
     * @param  list<string>  $employeeIds  the members of this run with a tax-exempt line
     */
    public function hasEarlierOpenExemptDraft(Access $actor, ?string $runId, array $employeeIds, string $periodEnd): bool
    {
        if ($employeeIds === [] || ! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            return false;
        }

        $yearStart = substr($periodEnd, 0, 4).'-01-01';

        return PayrollRunMemberIncentive::query()
            ->whereHas('type', static fn (Builder $type) => $type->where('is_taxable', false))
            ->whereHas('member', static fn (Builder $member) => $member
                ->whereIn('employee_id', $employeeIds)
                ->whereHas('run', static fn (Builder $run) => $run
                    ->where('status', PayrollRunStatus::DRAFT)
                    ->when($runId !== null, static fn (Builder $query) => $query->whereKeyNot($runId))
                    ->where('period_end', '>=', $yearStart)
                    ->where('period_end', '<', $periodEnd)))
            ->exists();
    }

    /**
     * How many payrolls type each Payroll Deductions row, DRAFT and
     * FINALIZED apart: a draft's lines leave with a deleted row, a
     * finalized run's keep it on the list. Posted payslips copy names, so a
     * later catalog edit cannot rewrite one.
     *
     * @param  list<string>  $deductionIds
     * @return array<string, array{drafts: int, finalized: int}>
     */
    public function deductionUsage(Access $actor, array $deductionIds): array
    {
        if (! PayrollSettingsAccess::mayManage($actor) || $deductionIds === []) {
            return [];
        }

        $usage = [];
        $rows = PayrollRunMemberDeduction::query()
            ->join('payroll_run_members', 'payroll_run_members.id', '=', 'payroll_run_member_deductions.member_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_members.run_id')
            ->whereIn('payroll_run_member_deductions.deduction_id', $deductionIds)
            ->selectRaw('payroll_run_member_deductions.deduction_id AS deduction_id, payroll_runs.status AS status, COUNT(DISTINCT payroll_runs.id) AS runs')
            ->groupBy('payroll_run_member_deductions.deduction_id', 'payroll_runs.status')
            ->get();
        foreach ($rows as $row) {
            $id = (string) $row->getAttribute('deduction_id');
            $usage[$id] ??= ['drafts' => 0, 'finalized' => 0];
            $bucket = (string) $row->getAttribute('status') === PayrollRunStatus::FINALIZED ? 'finalized' : 'drafts';
            $usage[$id][$bucket] += (int) $row->getAttribute('runs');
        }

        return $usage;
    }
}
