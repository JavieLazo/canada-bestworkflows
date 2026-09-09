<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayrollReadiness;
use App\Domain\Payroll\PayrollValidation;
use App\Domain\Payroll\PayslipCalculator;
use App\Domain\Payroll\PayslipDraft;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveTravelRequest;
use App\Models\PayrollFormula;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Shift;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Payroll module's screen-side helpers, shared by the run list, the run
 * workspace and the individual payslip.
 *
 * Three groups live here:
 *
 *  1. The hris.ts formatting helpers the payroll screens use (peso, fmtDate,
 *     employeeName, nextCode, dayCount…), self-contained so this module owns
 *     every file it depends on.
 *  2. The port of `calculatePayrollDrafts` (src/lib/payroll-server-calculation.ts)
 *     — the ONE compute path. The reference had a browser copy of the
 *     arithmetic for the live draft preview and a server copy for finalize;
 *     here both are this method, which only assembles inputs and hands them to
 *     PayslipCalculator / PayrollReadiness. No screen re-derives a peso.
 *  3. The mapping between the three JSON override maps the reference stored on
 *     the run (draft_deductions / draft_allowances / draft_earnings) and the
 *     typed columns they became on `payroll_run_members`.
 */
final class PayrollData
{
    /** DEDUCTION_LABELS (run-workspace.tsx:52-58), keyed in grid order. */
    public const array DEDUCTION_LABELS = [
        'sss' => 'SSS deduction',
        'philhealth' => 'PhilHealth deduction',
        'pagibig' => 'Pag-IBIG deduction',
        'withholding_tax' => 'Withholding tax',
        'other_deductions' => 'Other deductions',
    ];

    /** EARNING_FIELDS (run-workspace.tsx:61-65) — the hand-entered earnings, in grid order. */
    public const array EARNING_FIELDS = [
        'night_differential' => 'NSD',
        'leave_pay' => 'Leave',
        'other_earnings' => 'Others',
    ];

    /** payroll-run-schema.ts `money`: z.number().finite().min(0).max(999_999_999). */
    public const float MONEY_MAX = 999_999_999.0;

    /** Editable deduction key => payroll_run_members column. */
    public const array DEDUCTION_COLUMNS = [
        'sss' => 'deduction_sss',
        'philhealth' => 'deduction_philhealth',
        'pagibig' => 'deduction_pagibig',
        'withholding_tax' => 'deduction_withholding_tax',
        'other_deductions' => 'deduction_other',
        // Legacy: accepted for drafts saved before absence moved into Basic
        // pay; the calculation layer ignores it (payroll-run-schema.ts:9-11).
        'absence_deduction' => 'deduction_absence',
    ];

    /** Earning key => payroll_run_members column. */
    public const array EARNING_COLUMNS = [
        'night_differential' => 'earning_night_differential',
        'leave_pay' => 'earning_leave_pay',
        'other_earnings' => 'earning_other',
    ];

    /** use-payroll-lines.ts TOTALLED_COLUMNS — the keys the total row foots. */
    public const array TOTALLED_COLUMNS = [
        'work_days',
        'holiday_days',
        'rest_days',
        'work_days_pay',
        'holiday_pay',
        'rest_day_pay',
        'basic_pay',
        'allowances',
        'total_base_pay',
        'overtime_pay',
        'night_differential',
        'leave_pay',
        'other_earnings',
        'gross_pay',
        'sss',
        'philhealth',
        'pagibig',
        'withholding_tax',
        'other_deductions',
        'late_deduction',
        'undertime_deduction',
        'total_deductions',
        'net_pay',
    ];

    /* ------------------------------------------------------------------ */
    /* hris.ts helpers */
    /* ------------------------------------------------------------------ */

    /** hris.ts todayKey() — today's Manila calendar day, 'YYYY-MM-DD'. */
    public static function todayKey(): string
    {
        return ManilaClock::dayKey(CarbonImmutable::now());
    }

    /** peso() (hris.ts:184-189): ₱ + en-PH grouping, always two decimals. */
    public static function peso(mixed $n): string
    {
        $v = is_numeric($n) ? (float) $n : 0.0;

        if (! is_finite($v)) {
            $v = 0.0;
        }

        return "\u{20B1}".number_format($v, 2);
    }

    /** fmtDate (hris.ts) — 'Aug 5, 2026', unpadded day, from a 'Y-m-d' key or Carbon. */
    public static function fmtDate(mixed $d): string
    {
        if ($d instanceof \DateTimeInterface) {
            $d = $d->format('Y-m-d');
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', (string) $d, 'UTC')->format('M j, Y');
        } catch (\Throwable) {
            return (string) $d;
        }
    }

    /** employeeName (hris.ts:425-427). */
    public static function employeeName(?object $e): string
    {
        return EmployeeName::display($e);
    }

    /** initials() (run-workspace.tsx:44-45). */
    public static function initials(?object $e): string
    {
        if ($e === null) {
            return '—';
        }

        return EmployeeName::initials($e);
    }

    /** compareText (useSort.ts:33-40): blanks last in an ascending pass. */
    public static function compareText(?string $a, ?string $b): int
    {
        $left = trim($a ?? '');
        $right = trim($b ?? '');

        if ($left === '' && $right === '') {
            return 0;
        }
        if ($left === '') {
            return 1;
        }
        if ($right === '') {
            return -1;
        }

        return strcasecmp($left, $right);
    }

    /**
     * hris.ts nextCode() — the next `PR-001`-style code past the highest in
     * use. Deleting a run can never hand a code back out.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    public static function nextCode(string $prefix, iterable $codes, int $width = 3): string
    {
        $highest = 0;
        $pattern = '/^'.preg_quote($prefix, '/').'-(\d+)$/i';

        foreach ($codes as $code) {
            if ($code !== null && preg_match($pattern, trim($code), $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $prefix.'-'.str_pad((string) ($highest + 1), $width, '0', STR_PAD_LEFT);
    }

    /** dayCount() — whole days, unless a half-day leave landed in the count. */
    public static function dayCount(mixed $value): string
    {
        $v = is_numeric($value) ? (float) $value : 0.0;

        return $v === floor($v) ? (string) (int) $v : number_format($v, 1);
    }

    /* ------------------------------------------------------------------ */
    /* Draft arithmetic — thin wrappers over the domain layer */
    /* ------------------------------------------------------------------ */

    /**
     * applyDeductionOverrides (payroll.ts:332-356): a manager-typed figure
     * replaces the computed statutory line, then the deduction total and net
     * pay are re-footed through the same normalisation finalize uses.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    public static function applyDeductionOverrides(PayslipDraft $payslip, ?array $overrides): PayslipDraft
    {
        if ($overrides === null || $overrides === []) {
            return $payslip;
        }

        $changes = [];

        foreach (PayslipDraft::EDITABLE_DEDUCTION_KEYS as $key) {
            $value = $overrides[$key] ?? null;

            if (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                $changes[$key] = Money::round($value);
            }
        }

        $adjusted = $payslip->with($changes);
        $normalized = PayslipDraft::normalizeDeductions($adjusted->toArray());
        $totalDeductions = PayslipDraft::sumDeductions($normalized);

        return $adjusted->with(array_merge($normalized, [
            'total_deductions' => $totalDeductions,
            'net_pay' => Money::round($adjusted->gross_pay - $totalDeductions),
        ]));
    }

    /** payrollDraftError (payroll.ts:325-330). */
    public static function draftError(PayslipDraft|Payslip $payslip): ?string
    {
        return $payslip->total_deductions > $payslip->gross_pay
            ? 'Total deductions exceed gross pay. Adjust the draft deductions before finalizing.'
            : null;
    }

    /**
     * payrollTotals (use-payroll-lines.ts): a sum over the same keys the body
     * cells read. Works on PayslipDraft DTOs and posted Payslip rows alike.
     *
     * @param  iterable<object>  $rows
     * @return array<string, float>
     */
    public static function totals(iterable $rows): array
    {
        $totals = array_fill_keys(self::TOTALLED_COLUMNS, 0.0);

        foreach ($rows as $row) {
            foreach (self::TOTALLED_COLUMNS as $key) {
                $totals[$key] += (float) ($row->{$key} ?? 0);
            }
        }

        return $totals;
    }

    /* ------------------------------------------------------------------ */
    /* The one compute path */
    /* ------------------------------------------------------------------ */

    /**
     * Port of calculatePayrollDrafts (src/lib/payroll-server-calculation.ts).
     *
     * Assembles the inputs — employees, punches (±1 day for overnight
     * shifts), shifts, leaves, formulas, holidays — and hands each employee to
     * PayslipCalculator::compute + PayrollReadiness::issues. Every amount on
     * every payroll screen comes out of here.
     *
     * `$strict` is the server/client split of the reference collapsed into a
     * flag: finalize (`true`) refuses when a selected employee no longer
     * exists ("Payroll could not load every selected employee."), while the
     * draft preview (`false`) silently skips them exactly as
     * usePayrollCalculation's `.filter((e) => e !== undefined)` did.
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, array<string, mixed>>  $draftDeductions
     * @param  array<string, mixed>  $draftAllowances
     * @param  array<string, array<string, mixed>>  $draftEarnings
     * @return array{
     *     drafts: list<PayslipDraft>,
     *     employees: Collection<int, EmployeeProfile>,
     *     issues: list<array{employee_id: string, message: string}>,
     *     draftErrors: list<array{employee_id: string, message: string}>,
     *     combined: list<array{employee_id: string, message: string}>,
     * }
     */
    public static function calculateDrafts(
        array $employeeIds,
        string $periodStart,
        string $periodEnd,
        array $draftDeductions = [],
        array $draftAllowances = [],
        array $draftEarnings = [],
        bool $strict = false,
    ): array {
        if ($employeeIds === [] || $periodStart === '' || $periodEnd === '') {
            return ['drafts' => [], 'employees' => new Collection, 'issues' => [], 'draftErrors' => [], 'combined' => []];
        }

        $range = PayrollPeriod::attendanceDateRange($periodStart, $periodEnd);
        $instants = PayrollPeriod::attendanceInstants($range['from'], $range['to']);

        $employees = EmployeeProfile::query()->whereIn('id', $employeeIds)->get();

        $logRows = AttendanceLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('logged_at', [$instants['gte'], $instants['lte']])
            ->get(['id', 'employee_id', 'log_type', 'logged_at']);

        $shiftRows = Shift::query()->whereIn('employee_id', $employeeIds)->get();

        $leaveRows = LeaveTravelRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->get(['id', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date', 'status', 'is_paid']);

        $settings = [
            'formulas' => array_values(PayrollFormula::query()->get()->map->toArray()->all()),
            'holidays' => HolidaySetting::listFromArrays(Holiday::query()->get()->map->toArray()->all()),
        ];

        $employeeById = $employees->keyBy('id');

        if ($strict && $employeeById->count() !== count(array_unique($employeeIds))) {
            throw new RuntimeException('Payroll could not load every selected employee.');
        }

        $logsByEmployee = [];
        foreach ($logRows as $log) {
            $logsByEmployee[$log->employee_id][] = DomainLog::fromArray([
                'id' => $log->id,
                'employee_id' => $log->employee_id,
                'log_type' => $log->log_type,
                'logged_at' => $log->logged_at,
            ]);
        }

        $shiftByEmployee = [];
        foreach ($shiftRows as $shiftRow) {
            $shiftByEmployee[$shiftRow->employee_id] = DomainShift::fromArray($shiftRow->toArray());
        }

        $leavesByEmployee = [];
        foreach ($leaveRows as $leaveRow) {
            $leavesByEmployee[$leaveRow->employee_id][] = DomainLeave::fromArray([
                'id' => $leaveRow->id,
                'employee_id' => $leaveRow->employee_id,
                'request_type' => $leaveRow->request_type,
                'day_type' => $leaveRow->day_type,
                'start_date' => $leaveRow->start_date,
                'end_date' => $leaveRow->end_date,
                'status' => $leaveRow->status,
                'is_paid' => $leaveRow->is_paid,
            ]);
        }

        $computeDtr = static fn (array $logs, mixed $shift, array $leaves, string $start, string $end, array $holidays): array => DtrCalculator::compute(array_values($logs), $shift, array_values($leaves), $start, $end, array_values($holidays));

        $drafts = [];
        $issues = [];
        $draftErrors = [];
        // The server folded readiness issues and draft errors into ONE list,
        // per employee, readiness first (payroll-server-calculation.ts:83-118)
        // — finalize's `issues[0]` reads from this order.
        $combined = [];

        // Member ORDER is user-visible: the drafts come out in employee_ids
        // order, exactly as calculatePayrollDrafts maps over the input list.
        foreach ($employeeIds as $employeeId) {
            /** @var EmployeeProfile|null $employee */
            $employee = $employeeById->get($employeeId);

            if ($employee === null) {
                continue; // non-strict preview: filtered exactly as the client hook did
            }

            $employeeLogs = $logsByEmployee[$employeeId] ?? [];
            $employeeLeaves = $leavesByEmployee[$employeeId] ?? [];
            // Null degrades to DEFAULT_SHIFT inside the domain layer, the
            // counterpart of `shifts.find(...) ?? DEFAULT_SHIFT`.
            $shift = $shiftByEmployee[$employeeId] ?? null;

            foreach (PayrollReadiness::issues(
                $computeDtr,
                $employeeLogs,
                $shift,
                $employeeLeaves,
                $periodStart,
                $periodEnd,
                PayrollPeriod::dateKeyFromDate($employee->date_hired),
                $employee->date_resigned !== null ? PayrollPeriod::dateKeyFromDate($employee->date_resigned) : null,
                $settings['holidays'],
            ) as $issue) {
                $row = ['employee_id' => $employeeId, 'message' => $issue['message']];
                $issues[] = $row;
                $combined[] = $row;
            }

            $allowanceOverride = $draftAllowances[$employeeId] ?? null;
            $earningsOverride = $draftEarnings[$employeeId] ?? null;

            $computed = PayslipCalculator::compute(
                $employee,
                $employeeLogs,
                $shift,
                $employeeLeaves,
                $periodStart,
                $periodEnd,
                0.0,
                is_numeric($allowanceOverride) ? (float) $allowanceOverride : null,
                is_array($earningsOverride) ? $earningsOverride : null,
                $settings,
            );

            $draft = self::applyDeductionOverrides($computed, $draftDeductions[$employeeId] ?? null);

            $error = self::draftError($draft);
            if ($error !== null) {
                $row = ['employee_id' => $employeeId, 'message' => $error];
                $draftErrors[] = $row;
                $combined[] = $row;
            }

            $drafts[] = $draft;
        }

        return ['drafts' => $drafts, 'employees' => $employees, 'issues' => $issues, 'draftErrors' => $draftErrors, 'combined' => $combined];
    }

    /* ------------------------------------------------------------------ */
    /* Override maps <-> payroll_run_members columns */
    /* ------------------------------------------------------------------ */

    /**
     * Rebuild the three override maps the screens work in from the pivot rows.
     *
     * Null columns stay ABSENT from the maps — null is "no override", which is
     * not the same as an override of 0 (see the PayrollRunMember docblock).
     *
     * @param  iterable<PayrollRunMember>  $members
     * @return array{
     *     deductions: array<string, array<string, float>>,
     *     allowances: array<string, float>,
     *     earnings: array<string, array<string, mixed>>,
     * }
     */
    public static function overridesFromMembers(iterable $members): array
    {
        $deductions = [];
        $allowances = [];
        $earnings = [];

        foreach ($members as $member) {
            if ($member->allowance !== null) {
                $allowances[$member->employee_id] = (float) $member->allowance;
            }

            foreach (self::DEDUCTION_COLUMNS as $key => $column) {
                if ($member->{$column} !== null) {
                    $deductions[$member->employee_id][$key] = (float) $member->{$column};
                }
            }

            foreach (self::EARNING_COLUMNS as $key => $column) {
                if ($member->{$column} !== null) {
                    $earnings[$member->employee_id][$key] = (float) $member->{$column};
                }
            }

            if ($member->overtime_acknowledged !== null) {
                $earnings[$member->employee_id]['overtime_acknowledged'] = (bool) $member->overtime_acknowledged;
            }
        }

        return ['deductions' => $deductions, 'allowances' => $allowances, 'earnings' => $earnings];
    }

    /**
     * The pivot columns one member row snapshots for one employee — the
     * "full snapshot" a bare attach() cannot write.
     *
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  array<string, mixed>  $allowances
     * @param  array<string, array<string, mixed>>  $earnings
     * @return array<string, mixed>
     */
    public static function memberColumns(
        string $runId,
        string $employeeId,
        int $position,
        string $periodStart,
        string $periodEnd,
        array $deductions,
        array $allowances,
        array $earnings,
    ): array {
        $columns = [
            'run_id' => $runId,
            'employee_id' => $employeeId,
            'position' => $position,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'allowance' => null,
            'deduction_sss' => null,
            'deduction_philhealth' => null,
            'deduction_pagibig' => null,
            'deduction_withholding_tax' => null,
            'deduction_other' => null,
            'deduction_absence' => null,
            'earning_night_differential' => null,
            'earning_leave_pay' => null,
            'earning_other' => null,
            'overtime_acknowledged' => null,
        ];

        $allowance = $allowances[$employeeId] ?? null;
        if (is_numeric($allowance)) {
            $columns['allowance'] = (float) $allowance;
        }

        foreach (self::DEDUCTION_COLUMNS as $key => $column) {
            $value = $deductions[$employeeId][$key] ?? null;
            if (is_numeric($value)) {
                $columns[$column] = (float) $value;
            }
        }

        foreach (self::EARNING_COLUMNS as $key => $column) {
            $value = $earnings[$employeeId][$key] ?? null;
            if (is_numeric($value)) {
                $columns[$column] = (float) $value;
            }
        }

        if (array_key_exists('overtime_acknowledged', $earnings[$employeeId] ?? [])) {
            $columns['overtime_acknowledged'] = (bool) $earnings[$employeeId]['overtime_acknowledged'];
        }

        return $columns;
    }

    /**
     * A run's member ids in position order, shaped like the reference's
     * `employee_ids` column for the validation helpers.
     *
     * @param  iterable<PayrollRunMember>  $members
     * @return list<string>
     */
    public static function memberIds(iterable $members): array
    {
        $ids = [];

        foreach ($members as $member) {
            $ids[] = $member->employee_id;
        }

        return $ids;
    }

    /**
     * The port of `lockPayrollMembership` — the advisory lock every payroll
     * membership write takes first. An InnoDB row lock on the `locks` sentinel
     * row, released on COMMIT and on ROLLBACK. Call INSIDE a transaction.
     */
    public static function lockMembership(): void
    {
        DB::table('locks')
            ->where('name', PayrollValidation::MEMBERSHIP_LOCK)
            ->lockForUpdate()
            ->get();
    }
}
