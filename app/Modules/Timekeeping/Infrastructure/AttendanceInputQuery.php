<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceLog;
use App\Models\LeaveTravelRequest;
use App\Models\OvertimeApproval;
use App\Modules\Timekeeping\Application\LeaveHalves;
use App\Modules\Timekeeping\Application\OvertimeCategoryColumn;
use App\Modules\Timekeeping\Application\OvertimeCreditColumn;
use App\Modules\Timekeeping\Contracts\AttendanceInputs;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Database\Eloquent\Collection;

/** Loads consistent calculation facts after authorizing the requested employees. */
final class AttendanceInputQuery implements AttendanceSource
{
    public function forDtr(Access $actor, string $employeeId, string $start, string $end): AttendanceInputs
    {
        abort_unless($actor->hasModule(ModuleCode::EDTR, ModuleCode::PAYROLL)
            && ($actor->mayReadAllData(ModuleCode::EDTR, ModuleCode::PAYROLL)
                || ($actor->hasEmployeeLink() && $employeeId === $actor->ownEmployeeId())), 403);

        return $this->load([$employeeId], $start, $end, 50000)[$employeeId];
    }

    public function forPayroll(Access $actor, array $employeeIds, string $start, string $end, ?string $punchesThrough = null): array
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL), 403);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            abort_unless($actor->hasEmployeeLink()
                && array_diff($employeeIds, [$actor->ownEmployeeId()]) === [], 403);
        }
        // A counted-through date on or after the period's end counts everything.
        $through = $punchesThrough !== null && $punchesThrough < $end ? $punchesThrough : null;

        return $this->load($employeeIds, $start, $end, wholeMonths: true, punchesThrough: $through);
    }

    /**
     * The facts the leave earning job reads for a month (PostLeaveEarnings):
     * a system read with no actor, inside Timekeeping only, for the days it
     * counts present, absent and without pay. The schedule covers the whole
     * months like payroll's read, so the monthly working days are right.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, AttendanceInputs>
     */
    public function forPosting(array $employeeIds, string $start, string $end): array
    {
        return $this->load($employeeIds, $start, $end, wholeMonths: true);
    }

    public function forOwnPortal(Access $actor, string $start, string $end): ?AttendanceInputs
    {
        abort_unless($actor->hasModule(ModuleCode::PORTAL), 403);
        if (! $actor->hasEmployeeLink()) {
            return null;
        }

        $employeeId = $actor->ownEmployeeId();

        return $this->load([$employeeId], $start, $end, 50000, boundedLeaves: true)[$employeeId];
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, AttendanceInputs>
     */
    private function load(array $employeeIds, string $start, string $end, ?int $limit = null, bool $wholeMonths = false, bool $boundedLeaves = false, ?string $punchesThrough = null): array
    {
        // Two prior dates distinguish a flexible day's own open Time In
        // from a Second Time In borrowed from the preceding night.
        $range = PayrollPeriod::attendanceDateRange(PayrollPeriod::addCalendarDays($start, -1), $punchesThrough ?? $end);
        $instants = PayrollPeriod::attendanceInstants($range['from'], $range['to']);
        $logs = AttendanceLog::query()->whereIn('employee_id', $employeeIds)
            ->whereBetween('logged_at', [$instants['gte'], $instants['lte']])
            ->when($limit !== null, static fn ($q) => $q->orderByDesc('logged_at')->limit($limit))
            ->get(['id', 'employee_id', 'log_type', 'logged_at']);
        if ($punchesThrough !== null) {
            $logs = self::throughFirstTimeIn($logs, PayrollPeriod::addCalendarDays($punchesThrough, 1));
        }
        // Payroll's divisor includes every scheduled date in each touched
        // month, even dates outside the cutoff. DTR needs only its padded span.
        $scheduleRange = $wholeMonths
            ? PayrollPeriod::attendanceDateRange(PayrollPeriod::addCalendarDays(PayrollPeriod::calendarMonthRange($start)['from'], -1), PayrollPeriod::calendarMonthRange($end)['to'])
            : $range;
        $shiftMap = app(ShiftRoster::class)->scheduleForEmployees($employeeIds, $scheduleRange['from'], $scheduleRange['to']);
        // Access to the derived result is not access to the leave-management screen.
        // These facts omit reasons, reviewer identities, and other private metadata.
        $leaves = LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date')->whereIn('employee_id', $employeeIds)
            ->when($boundedLeaves, static fn ($query) => $query
                ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start))
            ->get(LeaveHalves::columns(['id', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date', 'status', 'is_paid']));
        $logMap = [];
        foreach ($logs as $log) {
            $logMap[$log->employee_id][] = DomainLog::fromArray([
                'id' => $log->id, 'employee_id' => $log->employee_id,
                'log_type' => $log->log_type, 'logged_at' => $log->logged_at,
            ]);
        }
        $leaveMap = [];
        foreach ($leaves as $leave) {
            $leaveMap[$leave->employee_id][] = DomainLeave::fromArray([
                'id' => $leave->id, 'employee_id' => $leave->employee_id,
                'request_type' => $leave->request_type, 'day_type' => $leave->day_type,
                'start_date' => $leave->start_date, 'end_date' => $leave->end_date,
                'status' => $leave->status, 'is_paid' => $leave->is_paid,
                'cancelled_dates' => $leave->cancelledDates(),
                'half' => LeaveHalves::of($leave->half ?? null),
            ]);
        }
        // The DTR's overtime decisions for the EXACT period — not the padded
        // punch span: the engine prices overtime on the shift-start date,
        // and only days inside the period are paid.
        $approvalMap = [];
        $categoryMap = [];
        // The category column arrives with 2026_11_02_0006; until the cron
        // has run it every day is ordinary overtime.
        $withCategory = OvertimeCategoryColumn::present();
        // Payroll receives the PAID minutes: the approval less what the
        // decision credited as Compensatory Leave (2026_11_02_0016).
        $withCredit = OvertimeCreditColumn::present();
        $columns = ['employee_id', 'date', 'approved_minutes', ...($withCategory ? ['category'] : []), ...($withCredit ? ['credited_minutes'] : [])];
        $approvals = OvertimeApproval::query()->whereIn('employee_id', $employeeIds)
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $punchesThrough ?? $end)
            ->get($columns);
        foreach ($approvals as $approval) {
            $date = $approval->date->format('Y-m-d');
            $approvalMap[$approval->employee_id][$date] = OvertimeApprovalPolicy::paidMinutes((int) $approval->approved_minutes, $withCredit ? (int) $approval->credited_minutes : 0);
            if ($withCategory && $approval->category !== null) {
                $categoryMap[$approval->employee_id][$date] = (string) $approval->category;
            }
        }
        $result = [];
        foreach ($employeeIds as $id) {
            $decisions = $approvalMap[$id] ?? [];
            ksort($decisions, SORT_STRING);
            $categories = $categoryMap[$id] ?? [];
            ksort($categories, SORT_STRING);
            $result[$id] = new AttendanceInputs($logMap[$id] ?? [], $shiftMap[$id] ?? null, $leaveMap[$id] ?? [], $decisions, $categories);
        }

        return $result;
    }

    /**
     * The punches read for an advance payroll: everything through the counted
     * day, and of the day after it only what precedes its first Time In —
     * the carry-over of the counted day's night, as PayrollReadiness slices
     * it. A Time In that day opens the employee's own next shift, which the
     * payroll presumes rather than reads, so it and everything after it stay
     * out, and the facts do not change with each punch of the processing day.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return Collection<int, AttendanceLog>
     */
    private static function throughFirstTimeIn(Collection $logs, string $nextDate): Collection
    {
        $firstTimeIn = [];
        foreach ($logs as $log) {
            if ($log->log_type === 'TIME_IN' && ManilaClock::dayKey($log->logged_at) === $nextDate) {
                $at = ManilaClock::ms($log->logged_at);
                $firstTimeIn[$log->employee_id] = min($firstTimeIn[$log->employee_id] ?? $at, $at);
            }
        }

        return $logs->filter(static function (AttendanceLog $log) use ($nextDate, $firstTimeIn): bool {
            if (ManilaClock::dayKey($log->logged_at) !== $nextDate) {
                return true;
            }
            $cutoff = $firstTimeIn[$log->employee_id] ?? null;

            return $cutoff === null || ManilaClock::ms($log->logged_at) < $cutoff;
        })->values();
    }
}
