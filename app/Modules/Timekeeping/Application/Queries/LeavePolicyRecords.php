<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeLeavePolicy;
use App\Models\LeaveAttendanceCharge;
use App\Models\LeaveEarningPosting;
use App\Modules\Timekeeping\Application\LeaveCharges;
use App\Modules\Timekeeping\Application\LeavePolicyStore;
use App\Modules\Timekeeping\Contracts\Views\LeaveChargeView;
use App\Modules\Timekeeping\Contracts\Views\LeaveEarningPostingView;
use App\Modules\Timekeeping\Contracts\Views\LeavePolicyView;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Collection;

/**
 * Employees' leave policy versions and the earning postings made under
 * them, behind the leave list's row scope (LEAVES — the leave_credits
 * list): an all-data holder reads everyone's, a linked employee their own,
 * an unlinked account nothing.
 */
final class LeavePolicyRecords
{
    public static function ready(): bool
    {
        return LeavePolicyStore::ready();
    }

    /**
     * Every version of one employee, newest effective date first.
     *
     * @return Collection<int, LeavePolicyView>
     */
    public function versions(Access $access, string $employeeId): Collection
    {
        if (! self::ready()) {
            return new Collection;
        }
        $names = LeavePresetRecords::typeNames();
        $inForce = LeavePolicyStore::inForce($employeeId, ManilaClock::todayKey());

        return EmployeeLeavePolicy::query()
            ->toEmployee($access, 'employee_id', ModuleCode::LEAVES)
            ->where('employee_id', $employeeId)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get()
            ->map(static fn (EmployeeLeavePolicy $row): LeavePolicyView => self::view($row, $names, $inForce?->id === $row->id));
    }

    public function find(Access $access, string $id): ?LeavePolicyView
    {
        if (! self::ready()) {
            return null;
        }
        $row = EmployeeLeavePolicy::query()->toEmployee($access, 'employee_id', ModuleCode::LEAVES)->whereKey($id)->first();
        if ($row === null) {
            return null;
        }
        $inForce = LeavePolicyStore::inForce($row->employee_id, ManilaClock::todayKey());

        return self::view($row, LeavePresetRecords::typeNames(), $inForce?->id === $row->id);
    }

    /**
     * The approved version in force today for each of these employees:
     * employee id => a one-line summary, absent for an employee with none.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, LeavePolicyView>
     */
    public function inForce(Access $access, array $employeeIds): array
    {
        if (! self::ready() || $employeeIds === []) {
            return [];
        }
        $visible = EmployeeLeavePolicy::query()->toEmployee($access, 'employee_id', ModuleCode::LEAVES)->whereIn('employee_id', $employeeIds)->distinct()->pluck('employee_id')->all();
        $names = LeavePresetRecords::typeNames();
        $out = [];
        foreach (LeavePolicyStore::forEmployees(array_values(array_map('strval', $visible)), ManilaClock::todayKey()) as $employeeId => $row) {
            $out[$employeeId] = self::view($row, $names, true);
        }

        return $out;
    }

    /**
     * Whether any employee the actor sees has a draft awaiting approval.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, int> employee id => drafts
     */
    public function drafts(Access $access, array $employeeIds): array
    {
        if (! self::ready() || $employeeIds === []) {
            return [];
        }
        $out = [];
        $counts = EmployeeLeavePolicy::query()->toEmployee($access, 'employee_id', ModuleCode::LEAVES)->whereIn('employee_id', $employeeIds)
            ->where('status', EmployeeLeavePolicy::DRAFT)->selectRaw('employee_id, COUNT(*) AS n')->groupBy('employee_id')->pluck('n', 'employee_id');
        foreach ($counts as $employeeId => $n) {
            $out[(string) $employeeId] = (int) $n;
        }

        return $out;
    }

    /**
     * One employee's earning postings, newest period first, at most `$limit`.
     *
     * @return Collection<int, LeaveEarningPostingView>
     */
    public function postings(Access $access, string $employeeId, int $limit = 60): Collection
    {
        if (! self::ready()) {
            return new Collection;
        }
        $names = LeavePresetRecords::typeNames();

        return LeaveEarningPosting::query()
            ->toEmployee($access, 'employee_id', ModuleCode::LEAVES)
            ->where('employee_id', $employeeId)
            ->orderByDesc('period')->orderBy('request_type')
            ->limit($limit)
            ->get()
            ->map(static fn (LeaveEarningPosting $row): LeaveEarningPostingView => Snapshot::of($row, LeaveEarningPostingView::class, [
                'type_name' => $names[$row->request_type] ?? $row->request_type,
                'basis' => (string) ($row->inputs['basis'] ?? (isset($row->inputs['carry']) ? 'year end: carried '.$row->inputs['carry'].', lapsed '.$row->inputs['lapse'] : '')),
            ]));
    }

    /**
     * One employee's attendance charged to leave credits by posted payrolls,
     * newest date first, at most `$limit`.
     *
     * @return Collection<int, LeaveChargeView>
     */
    public function charges(Access $access, string $employeeId, int $limit = 60): Collection
    {
        if (! LeaveCharges::ready()) {
            return new Collection;
        }
        $names = LeavePresetRecords::typeNames();

        return LeaveAttendanceCharge::query()
            ->toEmployee($access, 'employee_id', ModuleCode::LEAVES)
            ->where('employee_id', $employeeId)
            ->orderByDesc('date')->orderBy('kind')
            ->limit($limit)
            ->get()
            ->map(static function (LeaveAttendanceCharge $row) use ($names): LeaveChargeView {
                $covered = [];
                foreach ($row->covered as $part) {
                    $covered[] = ($names[$part['type']] ?? $part['type']).' '.LeaveQuantity::format((float) $part['days']);
                }

                return Snapshot::of($row, LeaveChargeView::class, ['covered_text' => $covered === [] ? 'Nothing covered' : implode(', ', $covered)]);
            });
    }

    /** @param  array<string, string>  $names */
    private static function view(EmployeeLeavePolicy $row, array $names, bool $inForce): LeavePolicyView
    {
        return Snapshot::of($row, LeavePolicyView::class, [
            'summary' => LeavePolicy::fromParams($row->params)->describe($names),
            'in_force' => $inForce,
        ]);
    }
}
