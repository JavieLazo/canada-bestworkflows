<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Minutes;
use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Payroll\Domain\PayrollAdvance;
use App\Modules\Workforce\Contracts\EmployeePayData;

/**
 * What a regular payroll pays as it stands although attendance has not
 * finished deciding it — the record FinalizePayroll keeps in the audit
 * sentence and the evidence. Nothing shows it on the workspace and nothing
 * holds the posting for it: attendance is reviewed before payroll.
 *
 *  - the period is not over yet: the days still to come pay as absences
 *    (not on an advance payroll, PayrollAdvance, which pays the days after
 *    its counted date as scheduled and settles them on the next payroll;
 *    there, only the counted days are looked at);
 *  - overtime awaiting a decision on the Daily Time Record: an undecided day
 *    pays nothing (DIVERGENCE 7), and once a payslip covers it, the day can
 *    never be decided (PostedPayrollDates);
 *  - leave or official business awaiting review: a pending request pays
 *    nothing, and approving it after posting never changes the payslip.
 *
 * Read from the captured inputs only, like LeaveCancellationWarnings: the
 * DTR here is the engine's own, over the punches, shift, leaves and holidays
 * the preview priced and inside each member's employment window, exactly as
 * PayslipCalculator runs it. So the panel lists what the preview priced, and
 * finalize — which verified those same inputs — decides from the same list.
 *
 * Not in the evidence fingerprint and not part of the computation: it moves
 * no peso. FinalizePayroll records it beside the results.
 */
final readonly class OpenAttendanceItems
{
    /**
     * @param  array<string, list<string>>  $overtime  employee id => the dates whose overtime awaits a decision
     * @param  array<string, list<array{type: string, start: string, end: string}>>  $requests  employee id => their pending leave and official business
     */
    private function __construct(
        public bool $periodOpen,
        public array $overtime,
        public array $requests,
    ) {}

    /** Nothing open: a preview with nothing to compute. */
    public static function none(): self
    {
        return new self(false, [], []);
    }

    /** `$today` is the local calendar day the officer finalizes on (LocalClock::todayKey()). */
    public static function forInputs(?PayrollInputs $inputs, string $today): self
    {
        if ($inputs === null) {
            return self::none();
        }

        $employees = [];
        foreach ($inputs->employees as $employee) {
            $employees[$employee->id] = $employee;
        }

        $overtime = [];
        $requests = [];
        foreach ($inputs->employeeIds as $employeeId) {
            $employee = $employees[$employeeId] ?? null;
            $facts = $inputs->attendance[$employeeId] ?? null;
            if ($employee === null || $facts === null) {
                continue;
            }
            // An advance payroll reads attendance through its counted date;
            // what comes after is presumed and settled later, so nothing
            // there is open here.
            [$from, $to] = self::window($employee, $inputs->periodStart, $inputs->attendanceEnd());
            if ($from > $to) {
                continue;
            }

            // The member's own counted date: an unfinished day among the
            // counted ones pulls it back, and the days from there on are
            // presumed too (PayrollAdvance::countedThrough).
            $days = DtrCalculator::compute($facts->logs, $facts->shift, $facts->leaves, $from, $to, $inputs->holidays);
            $counted = PayrollAdvance::countedThrough($inputs->attendanceThrough, $days);
            if ($counted !== null && $counted < $to) {
                $to = $counted;
            }
            if ($from > $to) {
                continue;
            }

            foreach ($days as $day) {
                if ($day->date <= $to && self::decidable($day->overtimeMinutes) && ! array_key_exists($day->date, $facts->overtimeApprovals)) {
                    $overtime[$employeeId][] = $day->date;
                }
            }

            foreach ($facts->leaves as $leave) {
                if ($leave->status === 'PENDING' && $leave->startDate <= $to && $leave->endDate >= $from) {
                    $requests[$employeeId][] = ['type' => $leave->requestType, 'start' => $leave->startDate, 'end' => $leave->endDate];
                }
            }
        }

        // The period is open only when its days to come would pay as
        // absences: never on an advance payroll, which pays them as
        // scheduled (PayrollAdvance) and settles them on the next one.
        return new self($inputs->attendanceThrough === null && $today <= $inputs->periodEnd, $overtime, $requests);
    }

    /**
     * The Daily Time Record's own PENDING rule: at least one whole minute of
     * overtime. Restated, because Payroll does not reach into Timekeeping's
     * domain; PayrollOpenItemsTest pins it to
     * OvertimeApprovalPolicy::decidable().
     */
    public static function decidable(float $minutes): bool
    {
        return max(0, (int) Minutes::round(is_finite($minutes) ? $minutes : 0.0)) >= 1;
    }

    public function isEmpty(): bool
    {
        return ! $this->periodOpen && $this->overtime === [] && $this->requests === [];
    }

    public function overtimeDays(): int
    {
        return array_sum(array_map('count', $this->overtime));
    }

    public function pendingRequests(): int
    {
        return array_sum(array_map('count', $this->requests));
    }

    /** "the period is not over, 3 overtime days undecided, 1 request pending" — '' when nothing is open. */
    public function summary(): string
    {
        $parts = [];
        if ($this->periodOpen) {
            $parts[] = 'the period is not over';
        }
        $days = $this->overtimeDays();
        if ($days > 0) {
            $parts[] = $days.($days === 1 ? ' overtime day' : ' overtime days').' undecided';
        }
        $pending = $this->pendingRequests();
        if ($pending > 0) {
            $parts[] = $pending.($pending === 1 ? ' request' : ' requests').' pending';
        }

        return implode(', ', $parts);
    }

    /**
     * The record FinalizePayroll keeps beside the results.
     *
     * @return array{period_open: bool, overtime: array<string, list<string>>, requests: array<string, list<array{type: string, start: string, end: string}>>}
     */
    public function toArray(): array
    {
        return ['period_open' => $this->periodOpen, 'overtime' => $this->overtime, 'requests' => $this->requests];
    }

    /**
     * The dates the payslip prices: the period, clipped to the employment
     * window, as PayslipCalculator clips it.
     *
     * @return array{0: string, 1: string}
     */
    private static function window(EmployeePayData $employee, string $start, string $end): array
    {
        $hired = PayrollPeriod::dateKeyFromDate($employee->date_hired);
        $resigned = $employee->date_resigned !== null ? PayrollPeriod::dateKeyFromDate($employee->date_resigned) : null;

        return [
            strcmp($hired, $start) > 0 ? $hired : $start,
            $resigned !== null && strcmp($resigned, $end) < 0 ? $resigned : $end,
        ];
    }
}
