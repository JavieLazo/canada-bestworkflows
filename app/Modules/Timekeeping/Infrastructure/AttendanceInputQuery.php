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
use App\Modules\Timekeeping\Contracts\AttendanceInputs;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Support\Access;

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

    public function forPayroll(Access $actor, array $employeeIds, string $start, string $end): array
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL), 403);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            abort_unless($actor->hasEmployeeLink()
                && array_diff($employeeIds, [$actor->ownEmployeeId()]) === [], 403);
        }

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
    private function load(array $employeeIds, string $start, string $end, ?int $limit = null, bool $wholeMonths = false, bool $boundedLeaves = false): array
    {
        // Two prior dates distinguish a flexible day's own open Time In
        // from a Second Time In borrowed from the preceding night.
        $range = PayrollPeriod::attendanceDateRange(PayrollPeriod::addCalendarDays($start, -1), $end);
        $instants = PayrollPeriod::attendanceInstants($range['from'], $range['to']);
        $logs = AttendanceLog::query()->whereIn('employee_id', $employeeIds)
            ->whereBetween('logged_at', [$instants['gte'], $instants['lte']])
            ->when($limit !== null, static fn ($q) => $q->orderByDesc('logged_at')->limit($limit))
            ->get(['id', 'employee_id', 'log_type', 'logged_at']);
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
            ->get(['id', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date', 'status', 'is_paid']);
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
            ]);
        }
        // The DTR's overtime decisions for the EXACT period — not the padded
        // punch span: the engine prices overtime on the shift-start date,
        // and only days inside the period are paid.
        $approvalMap = [];
        $approvals = OvertimeApproval::query()->whereIn('employee_id', $employeeIds)
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->get(['employee_id', 'date', 'approved_minutes']);
        foreach ($approvals as $approval) {
            $approvalMap[$approval->employee_id][$approval->date->format('Y-m-d')] = (int) $approval->approved_minutes;
        }
        $result = [];
        foreach ($employeeIds as $id) {
            $decisions = $approvalMap[$id] ?? [];
            ksort($decisions, SORT_STRING);
            $result[$id] = new AttendanceInputs($logMap[$id] ?? [], $shiftMap[$id] ?? null, $leaveMap[$id] ?? [], $decisions);
        }

        return $result;
    }
}
