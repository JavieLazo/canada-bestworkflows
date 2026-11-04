<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Shift;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\PayslipCalculator;
use App\Domain\Payroll\PayslipDraft;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\Payslip;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The settlement of an ADVANCE PAYROLL's presumed days (PayrollAdvance,
 * DELIBERATE DIVERGENCE 14). A payroll processed on the 12th paid the 12th
 * to the 15th as full scheduled days; the employee's next regular payroll
 * prices those days again from their finished attendance — the same engine,
 * over those days alone, with the employee, Formulas and Pay Basis & Rates
 * the advance payroll's evidence captured, so each day is worth what it was
 * worth then — and adds the difference as one line.
 *
 * Which payslips a draft settles: the member's posted regular payslips with
 * presumed days (`attendance_through` before `period_end`) that nothing has
 * settled yet, whose period ended before this payroll's starts and before
 * today, so every presumed day is over. The draft captures them with its
 * inputs (`PayrollInputs::settlements`) and prices them from the capture;
 * a payslip still too early to settle waits for the payroll after.
 *
 * What is compared, both over the presumed days: basic pay (work, holiday
 * and rest day), leave pay, approved overtime pay, night pay, less the late
 * and undertime charges. Contributions and tax are not compared: they are
 * computed on the whole payslip the days belonged to, and the settlement
 * itself enters the settling payslip's taxable gross.
 *
 * THE PRESUMED SIDE IS FROZEN WHEN THE ADVANCE POSTS: `presumedLines()` is
 * computed by the finalizing run and written into its evidence beside the
 * results (`presumed`), and the settlement reads it from there, so the
 * figure settled is the one the engine that paid it produced, whatever the
 * engine does by the time of settlement. THE ACTUAL SIDE READS TODAY: the
 * employee's leaving date as it stands (a resignation inside the presumed
 * days charges the days after it back in full) and the holidays as the
 * settling payroll captured them (a holiday proclaimed after the advance
 * is priced as the holiday it is). Only the schedule, the Formulas and the
 * rates stay the advance payroll's.
 */
final class AdvanceSettlements
{
    /** The lines a settlement compares, in the order the evidence carries them. */
    public const array LINE_KEYS = ['work_days', 'paid_leave_days', 'absence_days', 'basic_pay', 'leave_pay', 'overtime_pay', 'night_differential', 'late_deduction', 'undertime_deduction'];

    private function __construct() {}

    /** Whether the advance payroll's columns and the settlement ledger exist (the window before the cron migrates). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payslips', 'settlement') && Schema::hasTable('payslip_settlements'));
    }

    /**
     * The settlements these members are owed on a payroll over
     * `$periodStart`.., captured for its evidence: the advance payroll's
     * copy of the employee, Formulas, holidays and rates, the schedule and
     * leaves it presumed from, the presumed lines it froze, today's leaving
     * date, and the presumed days' attendance as it stands now.
     *
     * @param  list<string>  $employeeIds
     * @param  iterable<EmployeePayData>  $employees  the members as they stand today
     * @return array<string, list<SettlementInputs>>
     */
    public static function capture(Access $actor, array $employeeIds, string $periodStart, string $today, iterable $employees): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $current = [];
        foreach ($employees as $employee) {
            $current[$employee->id] = $employee;
        }
        $rows = self::pending($employeeIds)
            ->where('period_end', '<', $periodStart)
            ->where('period_end', '<', $today)
            ->with('run:id,code')
            ->orderBy('period_start')->orderBy('created_at')
            ->get(['id', 'run_id', 'employee_id', 'period_start', 'period_end', 'attendance_through']);

        /** @var array<string, array{inputs: PayrollInputs, presumed: array<string, array<string, float>>}> $restored  the advance payrolls' evidence, once per run */
        $restored = [];
        $settlements = [];
        foreach ($rows as $row) {
            $employeeId = (string) $row->employee_id;
            $runId = (string) $row->run_id;
            $restored[$runId] ??= self::evidence($runId);
            $evidence = $restored[$runId]['inputs'];
            $captured = null;
            foreach ($evidence->employees as $employee) {
                if ($employee->id === $employeeId) {
                    $captured = $employee;
                }
            }
            $facts = $evidence->attendance[$employeeId] ?? null;
            $through = $row->attendance_through;
            if ($captured === null || $facts === null || $through === null) {
                throw new RuntimeException('The evidence of payroll '.($row->run->code ?? '').' does not hold '.($current[$employeeId]->first_name ?? 'an employee').', so their presumed days cannot be settled.');
            }
            $from = CalendarDays::next($through->format('Y-m-d'));
            $to = $row->period_end->format('Y-m-d');
            $resigned = $current[$employeeId]->date_resigned ?? null;
            $settlements[$employeeId][] = new SettlementInputs(
                $row->id,
                $row->run->code ?? '',
                $from,
                $to,
                // The advance payroll's employee as captured: who it paid.
                $captured,
                $evidence->formulas,
                $evidence->holidays,
                // The advance payroll's first-day version: what priced the
                // presumed days (a version taking over inside its period
                // priced none of them; DIVERGENCE 17's segments are left out).
                isset($evidence->payRules[$employeeId]) ? [
                    'scope' => $evidence->payRules[$employeeId]['scope'],
                    'version_id' => $evidence->payRules[$employeeId]['version_id'],
                    'effective_from' => $evidence->payRules[$employeeId]['effective_from'],
                    'figures' => $evidence->payRules[$employeeId]['figures'],
                ] : null,
                $facts->shift,
                $facts->leaves,
                app(AttendanceSource::class)->forPayroll($actor, [$employeeId], $from, $to)[$employeeId],
                // Today's leaving date, for the actual side alone.
                $resigned?->format('Y-m-d'),
                // The presumed lines the advance payroll froze when it posted.
                $restored[$runId]['presumed'][$employeeId] ?? null,
            );
        }

        return $settlements;
    }

    /**
     * The presumed days priced as the advance payroll pays them: every
     * scheduled working day in `$from`..`$to` a full day, nothing read from
     * attendance (PayslipCalculator, DIVERGENCE 14). The finalizing run
     * records this beside its results, and a settlement reads it from there.
     *
     * @param  list<LeaveRequest>  $leaves
     * @param  list<array<string, mixed>>  $formulas
     * @param  list<HolidaySetting>  $holidays
     * @return array<string, float>
     */
    public static function presumedLines(EmployeePayData $employee, ?Shift $shift, array $leaves, string $from, string $to, array $formulas, array $holidays, PayRules $rules): array
    {
        return self::lines(PayslipCalculator::compute(
            employee: $employee, logs: [], shift: $shift, leaves: $leaves,
            periodStart: $from, periodEnd: $to, bonus: 0.0, settings: ['formulas' => $formulas, 'holidays' => $holidays],
            overtimeApprovals: [], rules: $rules,
            // Everything after the day before the range: every day presumed.
            attendanceThrough: CalendarDays::previous($from),
        ));
    }

    /**
     * The employee the actual side is priced for: the advance payroll's
     * copy with the leaving date as it stands today, so the days after a
     * resignation price as nothing and are charged back.
     */
    public static function actualEmployee(SettlementInputs $s): EmployeePayData
    {
        return PayrollInputs::employeeFromArray([...PayrollInputs::employeeToArray($s->employee), 'date_resigned' => $s->resigned]);
    }

    /**
     * Posted regular payslips with presumed days nothing has settled yet.
     *
     * @param  list<string>  $employeeIds
     * @return Builder<Payslip>
     */
    public static function pending(array $employeeIds): Builder
    {
        return Payslip::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereNotNull('attendance_through')
            ->whereColumn('attendance_through', '<', 'period_end')
            ->whereHas('run', static fn (Builder $run) => $run->where('kind', PayrollRunKind::REGULAR)->where('status', PayrollRunStatus::FINALIZED))
            ->whereDoesntHave('settledBy');
    }

    /**
     * One settlement priced: the presumed days as the advance payroll
     * priced them, and as their attendance now prices them, line by line,
     * and the difference.
     *
     * @param  array{late: array<string, float>, absence: array<string, float>, late_days: float}|null  $leaveCharges  what leave credits cover of these days' lateness and absences (DIVERGENCE 15), as the engine takes it
     * @param  list<HolidaySetting>|null  $holidays  the holidays the actual side is priced with — the settling payroll's capture; null prices it with the advance payroll's
     * @return array{payslip_id: string, run_code: string, from: string, to: string, presumed: array<string, float>, actual: array<string, float>, presumed_amount: float, actual_amount: float, amount: float}
     */
    public static function price(SettlementInputs $s, ?array $leaveCharges = null, ?array $holidays = null): array
    {
        $rules = $s->payRules === null ? PayRules::fromFormulas($s->formulas) : PayRulesStore::rulesFor($s->payRules, $s->formulas);
        // What the advance payroll froze; a payslip posted before that
        // figure was recorded is priced again from what it presumed from.
        $presumedLines = $s->presumedLines ?? self::presumedLines($s->employee, $s->presumedShift, $s->presumedLeaves, $s->from, $s->to, $s->formulas, $s->holidays, $rules);
        $actual = PayslipCalculator::compute(
            employee: self::actualEmployee($s), logs: $s->actual->logs, shift: $s->actual->shift, leaves: $s->actual->leaves,
            periodStart: $s->from, periodEnd: $s->to, bonus: 0.0, settings: ['formulas' => $s->formulas, 'holidays' => $holidays ?? $s->holidays],
            overtimeApprovals: $s->actual->overtimeApprovals, overtimeCategories: $s->actual->overtimeCategories, rules: $rules,
            leaveCharges: $leaveCharges,
        );
        $actualLines = self::lines($actual);
        $presumedAmount = self::total($presumedLines);
        $actualAmount = self::total($actualLines);

        return [
            'payslip_id' => $s->payslipId, 'run_code' => $s->runCode, 'from' => $s->from, 'to' => $s->to,
            'presumed' => $presumedLines, 'actual' => $actualLines,
            'presumed_amount' => $presumedAmount, 'actual_amount' => $actualAmount,
            'amount' => Money::round($actualAmount - $presumedAmount),
        ];
    }

    /**
     * The lines a settlement compares, as the engine priced them.
     *
     * @return array<string, float>
     */
    private static function lines(PayslipDraft $draft): array
    {
        return [
            'work_days' => $draft->work_days,
            'paid_leave_days' => $draft->paid_leave_days,
            'absence_days' => $draft->absence_days,
            'basic_pay' => $draft->basic_pay,
            'leave_pay' => $draft->leave_pay,
            'overtime_pay' => $draft->overtime_acknowledged ? $draft->overtime_pay : 0.0,
            'night_differential' => $draft->night_differential,
            'late_deduction' => $draft->late_deduction,
            'undertime_deduction' => $draft->undertime_deduction,
        ];
    }

    /** @param  array<string, float>  $lines */
    private static function total(array $lines): float
    {
        return Money::round($lines['basic_pay'] + $lines['leave_pay'] + $lines['overtime_pay'] + $lines['night_differential']
            - $lines['late_deduction'] - $lines['undertime_deduction']);
    }

    /**
     * An advance payroll's evidence, restored strictly from its own
     * snapshot: the inputs, and the presumed lines it froze per employee
     * (`presumed`, absent on a payslip posted before they were recorded).
     *
     * @return array{inputs: PayrollInputs, presumed: array<string, array<string, float>>}
     */
    private static function evidence(string $runId): array
    {
        $payload = PayrollFinalizationSnapshot::query()->where('run_id', $runId)->orderByDesc('created_at')->value('payload');
        $decoded = is_string($payload) ? json_decode($payload, true) : null;
        $inputs = is_array($decoded) ? ($decoded['inputs'] ?? null) : null;
        if (! is_array($inputs)) {
            throw new RuntimeException('The evidence of an advance payroll could not be read, so its presumed days cannot be settled.');
        }
        $presumed = [];
        foreach (is_array($decoded['presumed'] ?? null) ? $decoded['presumed'] : [] as $employeeId => $lines) {
            $read = self::linesFrom($lines);
            if (is_string($employeeId) && $read !== null) {
                $presumed[$employeeId] = $read;
            }
        }

        return ['inputs' => PayrollInputs::restore(PayrollInputs::json($inputs)), 'presumed' => $presumed];
    }

    /**
     * Frozen presumed lines read strictly: exactly the compared lines, each
     * a finite number; anything else is no record, and the days are priced
     * again from what they were presumed from.
     *
     * @return array<string, float>|null
     */
    public static function linesFrom(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $expected = self::LINE_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            return null;
        }
        $lines = [];
        foreach (self::LINE_KEYS as $key) {
            if (! is_int($value[$key]) && ! is_float($value[$key])) {
                return null;
            }
            $number = (float) $value[$key];
            if (! is_finite($number)) {
                return null;
            }
            $lines[$key] = $number;
        }

        return $lines;
    }
}
