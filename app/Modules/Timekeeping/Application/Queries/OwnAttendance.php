<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\DtrSummary;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\OvertimeApproval;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Contracts\Views\OvertimeDayView;
use App\Modules\Timekeeping\Contracts\Views\OwnAttendanceView;
use App\Modules\Timekeeping\Domain\Attendance\DayClose;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Modules\Timekeeping\Infrastructure\ScheduleDayReader;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** My Portal reads the current linked employee's DTR without granting attendance management access. */
final class OwnAttendance
{
    public const int MAX_DAYS = 31;

    public function between(Access $actor, string $start, string $end): ?OwnAttendanceView
    {
        abort_unless($actor->hasModule(ModuleCode::PORTAL), 403);
        if (! $actor->hasEmployeeLink()) {
            return null;
        }

        if (! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            throw ValidationException::withMessages(['period' => 'Choose a valid start and end date in chronological order.']);
        }
        if ($end > PayrollPeriod::addCalendarDays($start, self::MAX_DAYS - 1)) {
            throw ValidationException::withMessages(['period' => 'Choose no more than 31 days of attendance.']);
        }

        $lastComputed = min($end, ManilaClock::todayKey());
        if ($start > $lastComputed) {
            return new OwnAttendanceView([], DtrSummary::of([]), 0, [], ['days' => 0, 'late' => 0.0, 'undertime' => 0.0, 'absences' => 0]);
        }

        $inputs = app(AttendanceSource::class)->forOwnPortal($actor, $start, $lastComputed);
        if ($inputs === null) {
            return null;
        }
        $days = DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, $start, $lastComputed, app(HolidayCalendar::class)->all());
        $employeeId = $actor->ownEmployeeId();
        $scheduleDays = app(ScheduleDayReader::class)->between([$employeeId], $start, $lastComputed)[$employeeId] ?? [];
        $fallbackDays = array_values(array_filter(
            $days,
            static fn (DayRecord $day): bool => ($scheduleDays[$day->date] ?? null)?->basis === ScheduleBasis::NONE,
        ));
        $cap = app(OpenPunchLimit::class)->hours();
        $now = CarbonImmutable::now();

        return new OwnAttendanceView(
            days: $days,
            totals: DtrSummary::of($days),
            absences: DayClose::absences($days, $inputs->shift, $cap, $now),
            overtimeByDate: $this->overtime($actor, $employeeId, $days, $start, $lastComputed, $cap),
            unscheduled: [
                'days' => count($fallbackDays),
                'late' => (float) array_sum(array_map(static fn (DayRecord $day): float => $day->lateMinutes, $fallbackDays)),
                'undertime' => (float) array_sum(array_map(static fn (DayRecord $day): float => $day->undertimeMinutes, $fallbackDays)),
                'absences' => DayClose::absences($fallbackDays, $inputs->shift, $cap, $now),
            ],
        );
    }

    /**
     * @param  list<DayRecord>  $days
     * @return array<string, OvertimeDayView>
     */
    private function overtime(Access $actor, string $employeeId, array $days, string $start, string $end, float $cap): array
    {
        $rows = OvertimeApproval::query()->toEmployee($actor, 'employee_id', ModuleCode::EDTR)
            ->where('employee_id', '=', $employeeId)
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->get(['date', 'decision', 'computed_minutes', 'approved_minutes'])
            ->keyBy(static fn (OvertimeApproval $row): string => $row->date->format('Y-m-d'));

        $result = [];
        foreach ($days as $day) {
            $row = $rows->get($day->date);
            $ceiling = OvertimeApprovalPolicy::ceiling($day->overtimeMinutes, $cap);
            $status = OvertimeApprovalPolicy::status($row?->decision, $row?->approved_minutes, $row?->computed_minutes, $day->overtimeMinutes, $ceiling);
            if ($status === null) {
                continue;
            }

            $result[$day->date] = new OvertimeDayView(
                date: $day->date,
                status: $status,
                decision: $row?->decision,
                computed_minutes: OvertimeApprovalPolicy::wholeMinutes($day->overtimeMinutes),
                approved_minutes: $row?->approved_minutes,
                ceiling: $ceiling,
                payable_minutes: OvertimeApprovalPolicy::payable($row?->decision, $row?->approved_minutes, $ceiling),
                note: null,
                reviewer_name: null,
                decided_at: null,
            );
        }

        return $result;
    }
}
