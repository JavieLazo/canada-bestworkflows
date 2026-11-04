<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayrollReadiness;
use App\Domain\Payroll\PayrollValidation;
use App\Domain\Payroll\PayslipCalculator;
use App\Domain\Payroll\PayslipDraft;
use App\Domain\Payroll\StatutoryLines;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\PayrollDeduction;
use App\Models\PayrollFormula;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRecurringLine;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberDeduction;
use App\Models\PayrollRunMemberIncentive;
use App\Models\Payslip;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Contracts\Views\PayrollDeductionTypeView;
use App\Modules\Payroll\Contracts\Views\PayrollRunMemberView;
use App\Modules\Payroll\Contracts\Views\PayslipView;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Modules\Payroll\Domain\Benefits\ExemptBenefitCeiling;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Modules\Payroll\Domain\Deductions\PercentDeduction;
use App\Modules\Payroll\Domain\PayrollAdvance;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Modules\Payroll\Domain\Tax\AnnualWithholding;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\LeaveCashOutSource;
use App\Modules\Timekeeping\Contracts\LeaveChargeSource;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\CodeSequence;
use App\Support\CodeSort;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use App\Support\Money;
use App\Support\TextSort;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Payroll application calculations and mappings. The legacy screen helper
 * forwards here so existing views, imports, and public APIs remain stable.
 *
 * Three groups live here:
 *
 *  1. The hris.ts formatting helpers the payroll screens use (peso, fmtDate,
 *     employeeName, nextCode, dayCount). Input facts come through Workforce,
 *     Timekeeping and Organization contracts rather than their models.
 *  2. The port of `calculatePayrollDrafts` (src/lib/payroll-server-calculation.ts)
 *     — the ONE compute path. The reference has a browser copy of the
 *     arithmetic for the live draft preview and a server copy for finalize;
 *     here both are this method, which only assembles inputs and hands them to
 *     PayslipCalculator / PayrollReadiness. No screen re-derives a peso.
 *  3. The mapping between the reference's three override maps
 *     (draft_deductions / draft_allowances / draft_earnings), the typed member
 *     columns, and EMS's named incentive and deduction lines.
 */
final class PayrollData
{
    /*
     * There is no DEDUCTION_LABELS (the reference has one at
     * run-workspace.tsx:52-58): the grid's Deductions columns come from the
     * Payroll Deductions list — one per row, headed as the list names it —
     * through deductionColumns(). Only Other Deductions keeps a literal label.
     */

    /**
     * The employee's statutory lines: computed by the engine, and each one
     * overtypable in the workspace. A typed figure is an override; an absent
     * key means "use the computed figure". Which ones were overridden is
     * recorded with the payslip evidence. Not `other_deductions`: nothing
     * computes that line, it is always typed.
     */
    public const array STATUTORY_KEYS = ['sss', 'philhealth', 'pagibig', 'withholding_tax'];

    /**
     * The employer shares, in payslip order: computed and reported, never
     * deducted — they are not part of total_deductions ([R23]).
     */
    public const array EMPLOYER_LABELS = [
        'sss_er' => 'SSS (employer)',
        'philhealth_er' => 'PhilHealth (employer)',
        'pagibig_er' => 'Pag-IBIG (employer)',
    ];

    /** EARNING_FIELDS (run-workspace.tsx:61-65) — the hand-entered earnings, in grid order. */
    public const array EARNING_FIELDS = [
        'night_differential' => 'NSD',
        'leave_pay' => 'Leave',
        'other_earnings' => 'Other earnings',
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
        // Legacy: accepted so a draft carrying it still loads; absence is
        // priced inside Basic pay, so the calculation layer ignores it
        // (payroll-run-schema.ts:9-11).
        'absence_deduction' => 'deduction_absence',
    ];

    /** Earning key => payroll_run_members column. */
    public const array EARNING_COLUMNS = [
        'night_differential' => 'earning_night_differential',
        'leave_pay' => 'earning_leave_pay',
        'other_earnings' => 'earning_other',
    ];

    /** use-payroll-lines.ts TOTALLED_COLUMNS plus EMS's named line aggregates. */
    public const array TOTALLED_COLUMNS = [
        'work_days',
        'holiday_days',
        'rest_days',
        'paid_leave_days',
        'presumed_days',
        'settlement',
        'leave_charged_days',
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
        'custom_deductions',
        'late_deduction',
        'undertime_deduction',
        'total_deductions',
        'net_pay',
        // Incentives and bonuses: the taxed part (with any excess over the
        // PHP 90,000 ceiling) and the tax-exempt part.
        'bonus',
        'exempt_benefits',
    ];

    /* ------------------------------------------------------------------ */
    /* hris.ts helpers */
    /* ------------------------------------------------------------------ */

    /** hris.ts todayKey() — today's Manila calendar day, 'YYYY-MM-DD', ManilaClock's one clock read. */
    public static function todayKey(): string
    {
        return ManilaClock::todayKey();
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

    /**
     * fmtDate (hris.ts:209-214) — `Aug 05, 2026` from a 'Y-m-d' key or a
     * Carbon, shared through HrisFormat, so payroll pads the day like the
     * reference's `day: "2-digit"` and every other module. Anything that is
     * not a day echoes back.
     */
    public static function fmtDate(mixed $d): string
    {
        try {
            return HrisFormat::date($d instanceof \DateTimeInterface ? $d : (string) $d);
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
        // One comparator for every sorted list, so no screen orders text its
        // own way (a case-only tiebreak on one, ICU collation on another).
        // It is a wrapper so the call sites and Blade imports keep this name;
        // TextSortTest pins it.
        return TextSort::compare($a, $b);
    }

    /**
     * hris.ts nextCode() — the next run id past the highest in use, through
     * the one mint in CodeSequence. A BARE NUMBER: `payroll_runs.code` is
     * one of the eleven columns the conversion migration renumbers, so this
     * returns `8`, not `PR-008`. `$prefix` is the LEGACY prefix, read so a
     * table the conversion has not reached yet still mints above its
     * unconverted rows, and `$width` is accepted and discarded (see
     * CodeSequence::next).
     *
     * Safe only under lockMembership(), which serialises every run write.
     * Deletes are hard, so the highest code CAN be handed out again; the
     * unique index is the backstop.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    public static function nextCode(string $prefix, iterable $codes, int $width = 3): string
    {
        return CodeSequence::next($prefix, $codes, $width);
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
            $value = self::typedOverride($overrides[$key] ?? null);

            if ($value !== null) {
                $changes[$key] = $value;
            }
        }

        $adjusted = $payslip->with($changes);
        $normalized = PayslipDraft::normalizeDeductions($adjusted->toArray());
        $totalDeductions = PayslipDraft::sumDeductions($normalized);

        return $adjusted->with(array_merge($normalized, [
            'total_deductions' => $totalDeductions,
            'net_pay' => Money::round($adjusted->gross_pay - $totalDeductions + $adjusted->tax_refund),
        ]));
    }

    /**
     * A typed override as applyDeductionOverrides accepts it — a finite,
     * non-negative number, rounded to the centavo — or null when the cell
     * carries none (payroll.ts:332-356 ignores anything else).
     */
    public static function typedOverride(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0
            ? Money::round($value)
            : null;
    }

    /**
     * DELIBERATE DIVERGENCE from payroll.ts:332-356, which replaces an
     * overtyped line and leaves the withholding as the engine computed it
     * from the TABLE's shares. NIRC Sec. 32(B)(7)(f) excludes the
     * mandatory contributions actually withheld, so when an officer overtypes
     * an employee share (SSS, PhilHealth or Pag-IBIG) and NOT the tax, the tax
     * is computed again from the shares the payslip will actually carry —
     * through the same StatutoryLines functions the engine uses, so the base
     * and the rounding are the engine's. A typed withholding figure still
     * wins over everything. The golden vectors never carry overrides, so
     * they do not see this.
     *
     * @param  array<string, mixed>|null  $overrides
     * @return array{0: PayslipDraft, 1: bool} the draft, and whether the tax was re-derived
     */
    public static function rederiveWithholding(PayslipDraft $computed, object $employee, ?array $overrides, bool $force = false, ?StatutorySchedule $schedule = null, float $preTax = 0.0): array
    {
        return self::rederiveWithholdingBody($computed, $employee, $overrides, $force, $schedule, $preTax);
    }

    /** Whether a member is priced by the whole-year estimate: the switch on, withholding deducted, no fixed or percent tax (divergence 20). */
    public static function wholeYearPriced(EmployeePayData $employee): bool
    {
        return $employee->whole_year_tax && $employee->allow_withholding_tax && $employee->fixed_tax <= 0.0 && $employee->percent_tax <= 0.0;
    }

    /** How a member's withholding is worked out, as the payslip records it (TABLE, ANNUALIZED, FIXED, PERCENT, WHOLE_YEAR or NONE). */
    public static function taxMethodOf(EmployeePayData $employee): string
    {
        return $employee->allow_withholding_tax
            ? GovDeductionInput::methodOf($employee->fixed_tax, $employee->percent_tax, $employee->annualize_tax, $employee->whole_year_tax)
            : 'NONE';
    }

    /**
     * DIVERGENCE 20: the whole-year estimate's withholding for a member
     * priced by it — the year's remaining tax spread over the payrolls
     * left (AnnualWithholding) — in place of the engine's per-cut-off
     * figure, on the same taxable base the re-derivation uses: the shares
     * the payslip actually carries, less what was deducted before tax. A
     * typed withholding still wins. Totals and the net pay are refooted;
     * the refund joins the net.
     *
     * @param  array{year: int, payrolls_left: int, posted: array<string, array{taxable: float, withheld: float}>}|null  $taxYear
     * @param  array<string, mixed>|null  $overrides
     * @return array{0: PayslipDraft, 1: bool} the draft, and whether the tax was re-derived
     */
    public static function applyWholeYear(PayslipDraft $draft, EmployeePayData $employee, ?array $overrides, ?array $taxYear, ?StatutorySchedule $schedule, float $preTax, bool $rederived): array
    {
        if ($taxYear === null || ! self::wholeYearPriced($employee) || self::typedOverride($overrides['withholding_tax'] ?? null) !== null) {
            return [$draft, $rederived];
        }
        $posted = $taxYear['posted'][$employee->id] ?? ['taxable' => 0.0, 'withheld' => 0.0];
        $taxable = max(0.0, StatutoryLines::taxable(
            $draft->gross_pay,
            self::typedOverride($overrides['sss'] ?? null) ?? $draft->sss,
            self::typedOverride($overrides['philhealth'] ?? null) ?? $draft->philhealth,
            self::typedOverride($overrides['pagibig'] ?? null) ?? $draft->pagibig,
            $draft->late_deduction,
            $draft->undertime_deduction,
        ) - $preTax);
        $result = AnnualWithholding::compute($posted['taxable'], $taxable, $taxYear['payrolls_left'], $posted['withheld'], $employee->tax_spread ?? AnnualWithholding::SPREAD_EQUAL, $schedule);
        $changed = $draft->with(['withholding_tax' => $result['withholding'], 'tax_refund' => $result['refund']]);
        $normalized = PayslipDraft::normalizeDeductions($changed->toArray());
        $totalDeductions = PayslipDraft::sumDeductions($normalized);

        return [$changed->with(array_merge($normalized, [
            'total_deductions' => $totalDeductions,
            'net_pay' => Money::round($changed->gross_pay - $totalDeductions + $result['refund']),
        ])), true];
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     * @return array{0: PayslipDraft, 1: bool}
     */
    private static function rederiveWithholdingBody(PayslipDraft $computed, object $employee, ?array $overrides, bool $force, ?StatutorySchedule $schedule, float $preTax): array
    {
        if (self::typedOverride($overrides['withholding_tax'] ?? null) !== null) {
            return [$computed, false];
        }

        $sss = self::typedOverride($overrides['sss'] ?? null);
        $philhealth = self::typedOverride($overrides['philhealth'] ?? null);
        $pagibig = self::typedOverride($overrides['pagibig'] ?? null);

        // `$force`: the late and undertime lines are switched off, so the
        // base the engine taxed does not hold even with every share as
        // computed. `$preTax`: a percentage deduction taken before tax
        // comes off the base too.
        if (! $force && $preTax <= 0.0 && $sss === null && $philhealth === null && $pagibig === null) {
            return [$computed, false];
        }

        $taxable = max(0.0, StatutoryLines::taxable(
            $computed->gross_pay,
            $sss ?? $computed->sss,
            $philhealth ?? $computed->philhealth,
            $pagibig ?? $computed->pagibig,
            $computed->late_deduction,
            $computed->undertime_deduction,
        ) - $preTax);

        return [$computed->with(['withholding_tax' => max(0.0, StatutoryLines::withholding($employee, $taxable, $schedule))]), true];
    }

    /**
     * Which of the employee's statutory lines a draft overtypes, in
     * STATUTORY_KEYS order.
     *
     * @param  array<string, mixed>|null  $overrides
     * @return list<string>
     */
    public static function overriddenStatutoryKeys(?array $overrides): array
    {
        return array_values(array_filter(
            self::STATUTORY_KEYS,
            static fn (string $key): bool => self::typedOverride($overrides[$key] ?? null) !== null,
        ));
    }

    /**
     * Price the computed deductions the Payroll Deductions list switched OFF
     * (a deleted row is no deduction on any draft). Each statutory line off
     * becomes a 0 override — over any typed figure, since the grid has no
     * column for it — and late/undertime off zeroes both DTR-priced lines on
     * the engine's draft, with the footing redone through the same
     * normalize/sum path every override takes.
     *
     * @param  array<string, mixed>|null  $typed  the officer's typed overrides
     * @param  list<string>  $active  the calculations switched on when captured
     * @return array{0: PayslipDraft, 1: array<string, mixed>|null, 2: list<string>} the draft, the effective overrides, the calculations off
     */
    public static function switchOff(PayslipDraft $computed, ?array $typed, array $active): array
    {
        $off = array_values(array_diff(DeductionCalculation::all(), $active));
        if ($off === []) {
            return [$computed, $typed, []];
        }

        $overrides = $typed ?? [];
        foreach ($off as $calculation) {
            $key = DeductionCalculation::statutoryKey($calculation);
            if ($key !== null) {
                $overrides[$key] = 0.0;
            }
        }

        if (in_array(DeductionCalculation::LATE_UNDERTIME, $off, true)) {
            $zeroed = $computed->with(['late_deduction' => 0.0, 'undertime_deduction' => 0.0]);
            $normalized = PayslipDraft::normalizeDeductions($zeroed->toArray());
            $totalDeductions = PayslipDraft::sumDeductions($normalized);
            $computed = $zeroed->with(array_merge($normalized, [
                'total_deductions' => $totalDeductions,
                'net_pay' => Money::round($zeroed->gross_pay - $totalDeductions + $zeroed->tax_refund),
            ]));
        }

        return [$computed, $overrides, $off];
    }

    /** payrollDraftError (payroll.ts:325-330). */
    public static function draftError(PayslipDraft|Payslip|PayslipView $payslip): ?string
    {
        return $payslip->total_deductions > $payslip->gross_pay
            ? 'Total deductions exceed gross pay. Adjust the draft deductions before finalizing.'
            : null;
    }

    /**
     * A payslip's incentives and bonuses in one figure — the grid's
     * Incentives column: the taxed part (`bonus`, taxable lines plus any
     * excess over the PHP 90,000 ceiling) and the tax-exempt part. A footing
     * of two stored or computed figures, not a calculation.
     */
    public static function incentivesTotal(object $payslip): float
    {
        return Money::round((float) ($payslip->bonus ?? 0) + (float) ($payslip->exempt_benefits ?? 0));
    }

    /**
     * The draft grid's Deductions columns: one per Payroll Deductions row,
     * code order, automatic and typed alike, plus a fallback for a
     * calculation no row carries. Organization-wide payroll data, so an
     * actor without it gets no columns, as they get no lines.
     *
     * @return Collection<int, DeductionColumnView>
     */
    public static function deductionColumns(Access $actor): Collection
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            return new Collection;
        }

        return DeductionColumns::fromCatalog();
    }

    /**
     * The rows an officer types per employee — every row without a
     * calculation, code order. The automatic rows are the engine's.
     *
     * @return Collection<int, PayrollDeductionTypeView>
     */
    public static function customDeductionTypes(Access $actor): Collection
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            return collect();
        }

        return PayrollDeduction::query()->whereNull('calculation')->get()
            ->sort(static fn (PayrollDeduction $a, PayrollDeduction $b): int => CodeSort::compare($a->code, $b->code) ?: strcmp($a->id, $b->id))
            ->values()->map(static fn (PayrollDeduction $row): PayrollDeductionTypeView => Snapshot::of($row, PayrollDeductionTypeView::class));
    }

    /**
     * Each employee's typed deduction amounts keyed by column
     * (`DeductionColumnView::key()`), from a draft's captured lines (`code`)
     * or a payslip's posted copies (`deduction_code`) alike. A mapping, not
     * a calculation.
     *
     * @param  array<string, list<mixed>>  $customDeductionLines
     * @return array<string, array<string, float>>
     */
    public static function typedDeductionAmounts(array $customDeductionLines): array
    {
        $amounts = [];
        foreach ($customDeductionLines as $employeeId => $lines) {
            foreach ($lines as $line) {
                $code = (string) (data_get($line, 'code') ?? data_get($line, 'deduction_code'));
                $amounts[$employeeId]['row:'.$code] = (float) data_get($line, 'amount');
            }
        }

        return $amounts;
    }

    /**
     * The footing of every list-driven Deductions column, keyed like the
     * cells: the automatic ones from totals(), each typed column as the sum
     * of its cells. A footing, never a calculation (payrollTotals,
     * use-payroll-lines.ts).
     *
     * @param  Collection<int, DeductionColumnView>  $columns
     * @param  array<string, float>  $totals
     * @param  array<string, array<string, float>>  $typedAmounts
     * @return array<string, float>
     */
    public static function deductionTotals(Collection $columns, array $totals, array $typedAmounts): array
    {
        $footing = [];
        foreach ($columns as $column) {
            if (! $column->headsColumn()) {
                continue;
            }
            $key = $column->key();
            if ($column->calculation !== null) {
                $footing[$key] = (float) ($totals[DeductionCalculation::statutoryKey($column->calculation)] ?? 0.0);
            } else {
                $sum = 0.0;
                foreach ($typedAmounts as $amounts) {
                    $sum += $amounts[$key] ?? 0.0;
                }
                $footing[$key] = Money::round($sum);
            }
        }

        return $footing;
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
     * every REGULAR (cut-off) payroll screen comes out of here; the 13th month
     * run's come out of ThirteenthMonthData::calculateDrafts.
     *
     * `$strict` is the server/client split of the reference collapsed into a
     * flag: finalize (`true`) refuses when a selected employee no longer
     * exists ("Payroll could not load every selected employee."), while the
     * draft preview (`false`) silently skips them exactly as
     * usePayrollCalculation's `.filter((e) => e !== undefined)` does.
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, array<string, mixed>>  $draftDeductions
     * @param  array<string, mixed>  $draftAllowances
     * @param  array<string, array<string, mixed>>  $draftEarnings
     * @param  array<string, array<string, mixed>>  $draftIncentives  employee id => [incentive type id => amount]
     * @param  array<string, array<string, mixed>>  $draftCustomDeductions  employee id => [deduction type id => amount]
     * @param  array<string, list<string>>  $draftSkippedRecurring  employee id => the recurring lines skipped on this payroll
     * @param  string|null  $attendanceThrough  the advance payroll's "attendance counted through" date (PayrollAdvance); null, or the period's end, reads the whole period
     * @return array{
     *     drafts: list<PayslipDraft>,
     *     employees: Collection<int, EmployeePayData>,
     *     issues: list<array{employee_id: string, message: string}>,
     *     draftErrors: list<array{employee_id: string, message: string}>,
     *     combined: list<array{employee_id: string, message: string}>,
     *     inputs: ?PayrollInputs,
     *     statutory: array<string, array{computed: array<string, float>, overridden: list<string>, off: list<string>, withholding_rederived: bool, benefits: array{ytd_before: float, exempt: float, ceiling: float, taxable_excess: float}}>,
     *     incentiveLines: array<string, list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string, over_ceiling: float, cash_out?: array{id: string, days: float, amount: float}, adjustment_id?: string}>>,
     *     customDeductionLines: array<string, list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool, recurring_line_id: ?string, adjustment_id?: string}>>,
     *     settlementLines: array<string, list<array{payslip_id: string, run_code: string, from: string, to: string, presumed: array<string, float>, actual: array<string, float>, presumed_amount: float, actual_amount: float, amount: float}>>,
     *     leaveChargeLines: array<string, array{lines: list<array{date: string, kind: string, minutes: ?int, days: float, covered: array<string, float>, uncovered_days: float, fraction: float}>, balances: array<string, float>, notes: list<string>}>,
     *     presumedLines: array<string, array<string, float>>,
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
        ?Access $actor = null,
        array $draftIncentives = [],
        array $draftCustomDeductions = [],
        array $draftSkippedRecurring = [],
        ?string $attendanceThrough = null,
        ?string $payDate = null,
        ?string $contributionMonth = null,
        ?string $runId = null,
    ): array {
        if ($employeeIds === [] || $periodStart === '' || $periodEnd === '') {
            return ['drafts' => [], 'employees' => new Collection, 'issues' => [], 'draftErrors' => [], 'combined' => [], 'inputs' => null, 'statutory' => [], 'incentiveLines' => [], 'customDeductionLines' => [], 'settlementLines' => [], 'leaveChargeLines' => [], 'presumedLines' => []];
        }

        $actor ??= app(Access::class);
        // DIVERGENCE 14: an advance payroll's counted-through date; the
        // period's last day counts everything and is stored as nothing.
        $attendanceThrough = PayrollAdvance::normalize($attendanceThrough, $periodEnd);
        $inputs = PayrollReadTransaction::run(static function () use ($actor, $employeeIds, $periodStart, $periodEnd, $draftDeductions, $draftAllowances, $draftEarnings, $draftIncentives, $draftCustomDeductions, $draftSkippedRecurring, $attendanceThrough, $payDate, $contributionMonth, $runId): PayrollInputs {
            // The lines as the catalog describes them NOW, captured with the
            // run: a type renamed or re-flagged later changes a draft's
            // preview (and asks for a fresh review), never posted pay.
            $incentives = self::incentiveLines($draftIncentives, $employeeIds);
            // The catalog's scheduled benefits, as they stand: what this
            // payroll proposes on its own, captured so a replay never reads
            // the catalog and a catalog change stales the preview.
            $benefitRules = self::benefitRules();
            // The Payroll Deductions rows priced as a percentage, as they
            // stand, captured for the same reasons.
            $deductionRules = self::deductionRules();
            $customDeductions = self::customDeductionLines($draftCustomDeductions, $employeeIds);
            // Which computed deductions the Payroll Deductions list switches
            // ON right now (a deleted SSS row = no SSS on this draft):
            // captured with the run, in the fingerprint.
            $active = self::activeCalculations();
            // The Contribution & Tax Table versions in force on the period's
            // first day, with their figures: captured with the run, in the
            // fingerprint, so a new version stales the preview and a replay
            // never reads the live tables. An uncovered period never
            // computes (the cut-off check refuses it first). A payroll made
            // from a pay schedule picks the withholding table by its pay
            // date and the contributions by its contribution month.
            $tables = app(StatutoryTables::class)->resolve($periodStart, $periodEnd, $payDate, $contributionMonth);
            if ($tables->error !== null) {
                throw new RuntimeException($tables->error);
            }
            // The Pay Basis & Rates version pricing each member — a version
            // of their own in force, else their Employment status's override,
            // else the company's — captured with the run, in the fingerprint
            // (DIVERGENCE 13). Only members with versions of their own are
            // resolved one by one; the rest share their status's.
            // Each member's Contributions & tax version in force on the
            // period's first day laid over their pay context (an employee
            // with none keeps their profile's baseline), captured with the
            // run — the switches on the employee record, the version and
            // its rates of their own in `gov_policies` — so a new version
            // stales the preview and a replay never reads the live table.
            // DIVERGENCE 19: the version in force for the CONTRIBUTION MONTH
            // (its first day; the period's first day when the run has none)
            // is the one laid over the member, and what that month's posted
            // payslips already took of each share is captured for the
            // schedules that take the rest.
            $contributionOverlayDate = $contributionMonth === null ? $periodStart : $contributionMonth.'-01';
            [$employees, $govPolicies] = GovDeductionPolicyStore::overlay(app(EmployeePayContexts::class)->get($actor, $employeeIds), $contributionOverlayDate);
            $contributionPosted = ContributionMonthToDate::posted($employeeIds, $contributionMonth ?? substr($periodStart, 0, 7));
            // DIVERGENCE 20: for the members priced by the whole-year
            // estimate, the tax year (of the pay date), the regular payrolls
            // left in it and what the year already posted — captured, so a
            // payslip posted meanwhile stales the preview and a replay never
            // reads the live tables. Null when no member is priced that way,
            // so every other payroll's evidence is the reference's.
            $wholeYearIds = array_values(array_map(static fn (EmployeePayData $e): string => $e->id, array_filter($employees, self::wholeYearPriced(...))));
            $taxYear = $wholeYearIds === [] ? null : TaxYearToDate::entry($wholeYearIds, $runId === null ? null : PayrollRun::query()->whereKey($runId)->first(), $payDate, $periodEnd);
            // TX05: the yearly tax-free ceiling in force on the period's
            // first day, and each member's approved benefit assignments
            // reaching the period — both captured, so a change stales the
            // preview and a replay never reads the live tables.
            $benefitCeiling = BenefitCeilings::entry($periodStart);
            $benefitAssignments = BenefitAssignments::forPayroll($employeeIds, $periodStart, $periodEnd);
            // DIVERGENCE 18: the salary in force on the first day, and the
            // versions taking over inside the period, captured per member.
            $employees = SalaryTimeline::overlay($actor, $employees, $periodStart, $periodEnd);
            $store = app(PayRulesStore::class);
            $ownRules = $store->employeesWithOwnRules($employeeIds);
            $resolvedRules = [];
            $payRules = [];
            // DIVERGENCE 17: the versions day by day — the one in force on
            // the first day and each later one that takes over inside the
            // period — so the engine prices each day by its own.
            foreach ($employees as $employee) {
                $statusId = $employee->employment_status_id;
                $resolved = in_array($employee->id, $ownRules, true)
                    ? $store->timeline($periodStart, $periodEnd, $statusId, $employee->id)
                    : ($resolvedRules[$statusId ?? ''] ??= $store->timeline($periodStart, $periodEnd, $statusId));
                if ($resolved['error'] !== null) {
                    throw new RuntimeException($resolved['error']);
                }
                $payRules[$employee->id] = [...$resolved['entry'], 'segments' => $resolved['segments']];
            }

            // Each member's recurring lines live on the period, with what is
            // already paid against them, read in the same view as everything
            // else (under finalize's membership lock), and the ones this
            // payroll skips — only skips of lines that are live here.
            $recurring = self::recurringLines($actor, $employeeIds, $periodStart, $customDeductions);

            // What each employee with a line under the PHP 90,000 ceiling —
            // a typed tax-exempt incentive or a recurring exempt or de minimis
            // one — has already spent of it this year, read in the same view
            // as everything else (under finalize's membership lock). A
            // recurring line missing here would be priced against nothing
            // spent on every payroll and never reach the ceiling.
            $underCeiling = [BenefitRule::TAX_EXEMPT, BenefitRule::TAX_DE_MINIMIS];
            $exempting = array_values(array_filter(
                $employeeIds,
                static fn (string $id): bool => array_filter($incentives[$id] ?? [], static fn (array $line): bool => ! $line['is_taxable']) !== []
                    || array_filter($recurring[$id] ?? [], static fn (array $line): bool => $line['kind'] === RecurringLine::KIND_INCENTIVE && in_array($line['tax'], $underCeiling, true)) !== [],
            ));
            // A scheduled benefit under the ceiling may reach any member, so
            // every member's year-to-date figure is captured then.
            if (array_filter($benefitRules, static fn (array $rule): bool => in_array($rule['figures']['tax'], $underCeiling, true)) !== []) {
                $exempting = $employeeIds;
            }
            $ytd = $exempting === [] ? [] : app(ExemptBenefitLedger::class)->usedBefore($actor, $exempting, (int) substr($periodEnd, 0, 4));

            $skipped = [];
            foreach ($recurring as $employeeId => $lines) {
                $live = array_column($lines, 'line_id');
                $kept = array_values(array_intersect($draftSkippedRecurring[$employeeId] ?? [], $live));
                if ($kept !== []) {
                    sort($kept, SORT_STRING);
                    $skipped[$employeeId] = $kept;
                }
            }

            // DIVERGENCE 14: the presumed days of each member's earlier
            // advance payslips that this payroll settles, captured with the
            // run — and the punches read only through the counted date, so
            // the processing day's own punches never move the preview.
            $settlements = AdvanceSettlements::capture($actor, $employeeIds, $periodStart, ManilaClock::todayKey(), $employees);

            // DIVERGENCE 15: what each member's leave policy charges to credits,
            // the conversion table and their balances AS THEY STAND, captured
            // with the run so a charge is planned from the evidence and a
            // changed balance stales the preview.
            $leaveCharges = app(LeaveChargeSource::class)->forPayroll($actor, $employeeIds, $periodStart, $periodEnd);

            // The leave credit cash-outs this payroll pays — each member's
            // approved, unpaid requests — and the catalog type they are paid
            // as, captured with the run so an approval, a payment elsewhere
            // or a catalog change stales the preview.
            $leaveCashOuts = app(LeaveCashOutSource::class)->forPayroll($actor, $employeeIds);
            $cashOutType = self::cashOutType();

            // The approved adjustments this payroll pays — each member's
            // lines meant for this run or for the next one — captured with
            // the run, so a newly approved line stales the preview and a
            // replay never reads the ledger.
            $adjustments = Adjustments::forPayroll($employeeIds, $runId);

            return new PayrollInputs(
                $employeeIds, $periodStart, $periodEnd,
                $employees,
                app(AttendanceSource::class)->forPayroll($actor, $employeeIds, $periodStart, $periodEnd, $attendanceThrough),
                array_values(PayrollFormula::query()->get()->map->toArray()->all()),
                app(HolidayCalendar::class)->all(),
                $draftDeductions, $draftAllowances, $draftEarnings, CalculatorVersion::current(),
                $incentives, $ytd, $customDeductions, $active, $tables->tables, $payRules, $benefitRules, $deductionRules,
                $recurring, $skipped, $attendanceThrough, $settlements, $leaveCharges, $leaveCashOuts, $cashOutType, $govPolicies, $adjustments,
                $contributionMonth, $contributionPosted, $benefitCeiling, $benefitAssignments, $taxYear,
            );
        });

        return self::calculateInputs($inputs, $strict);
    }

    /**
     * The cut-off check a regular payroll makes before anything computes:
     * the dates, the Contribution & Tax Tables in force, then the Pay Basis
     * & Rates — the company's version, the override of every member's
     * Employment status and the versions of members with rates of their
     * own — none of which may change inside the period.
     *
     * @param  list<string>  $memberIds
     */
    public static function cutoffError(Access $actor, array $memberIds, string $periodStart, string $periodEnd): ?string
    {
        $error = app(StatutoryTables::class)->cutoffError($periodStart, $periodEnd);
        if ($error !== null) {
            return $error;
        }
        $store = app(PayRulesStore::class);
        $statusIds = [];
        $labels = [];
        $own = [];
        if ($memberIds !== [] && $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            $ownRules = $store->employeesWithOwnRules($memberIds);
            foreach (app(EmployeePayContexts::class)->get($actor, $memberIds) as $employee) {
                $statusIds[] = $employee->employment_status_id;
                if ($employee->employment_status_id !== null && $employee->employment_status !== null) {
                    $labels[$employee->employment_status_id] = $employee->employment_status;
                }
                if (in_array($employee->id, $ownRules, true)) {
                    $own[$employee->id] = ['status_id' => $employee->employment_status_id, 'name' => EmployeeName::display($employee)];
                }
            }
        }

        return $store->cutoffError($periodStart, $periodEnd, $statusIds, $labels, $own);
    }

    /**
     * The computed deductions the list switches on: every calculation some
     * row carries, in `DeductionCalculation::all()` order.
     *
     * @return list<string>
     */
    public static function activeCalculations(): array
    {
        return DeductionCalculation::order(PayrollDeduction::query()->whereNotNull('calculation')->pluck('calculation')->all());
    }

    /**
     * A draft's incentive amounts as captured lines: only members, only types
     * that still exist, only positive amounts (Money::round), each with the
     * type's code, name and flag, in the catalog's id order (CodeSort) — the
     * order the PHP 90,000 ceiling is spent in. A member with no line is
     * absent.
     *
     * @param  array<string, array<string, mixed>>  $draftIncentives
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool}>>
     */
    private static function incentiveLines(array $draftIncentives, array $employeeIds): array
    {
        $typeIds = [];
        foreach ($employeeIds as $employeeId) {
            foreach (array_keys($draftIncentives[$employeeId] ?? []) as $typeId) {
                $typeIds[(string) $typeId] = true;
            }
        }
        if ($typeIds === []) {
            return [];
        }

        $types = PayrollIncentiveType::query()->whereIn('id', array_keys($typeIds))->get()
            ->sort(static fn (PayrollIncentiveType $a, PayrollIncentiveType $b): int => CodeSort::compare($a->code, $b->code) ?: strcmp($a->id, $b->id))
            ->values();

        $lines = [];
        foreach ($employeeIds as $employeeId) {
            $amounts = $draftIncentives[$employeeId] ?? [];
            foreach ($types as $type) {
                $amount = $amounts[$type->id] ?? null;
                if (! is_numeric($amount) || ! is_finite((float) $amount) || Money::round($amount) <= 0) {
                    continue;
                }
                $lines[$employeeId][] = [
                    'type_id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'is_taxable' => $type->is_taxable,
                    'amount' => Money::round($amount),
                    'tax' => self::benefitRuleOf($type)->tax,
                    'counts_toward_13th' => self::benefitRuleOf($type)->countsToward13th,
                ];
            }
        }

        return $lines;
    }

    /** "2.5 days" / "1 day" for a cash-out line's name: credits are kept to four decimals, shown without trailing zeros. */
    private static function daysText(float $days): string
    {
        $text = rtrim(rtrim(number_format($days, 4, '.', ''), '0'), '.');

        return $text.($days === 1.0 ? ' day' : ' days');
    }

    /** Whether the catalog carries the cash-out flag yet (the window before the cron migrates 2026_10_31_0008). */
    public static function cashOutTypeReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payroll_incentive_types', 'pays_leave_cash_out'));
    }

    /**
     * The Incentives & Bonuses type leave credit cash-outs are paid as, as
     * the evidence captures it (PayrollInputs::$cashOutType), or null when
     * none is marked.
     *
     * @return array{type_id: string, code: string, name: string, is_taxable: bool, tax: string, counts_toward_13th: bool}|null
     */
    private static function cashOutType(): ?array
    {
        if (! self::cashOutTypeReady()) {
            return null;
        }
        $type = PayrollIncentiveType::query()->where('pays_leave_cash_out', true)->orderBy('id')->first();
        if ($type === null) {
            return null;
        }
        $rule = self::benefitRuleOf($type);

        return ['type_id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'is_taxable' => $type->is_taxable, 'tax' => $rule->tax, 'counts_toward_13th' => $rule->countsToward13th];
    }

    /**
     * The catalog's scheduled benefits — an amount payroll can work out, on
     * a schedule — as the evidence captures them, in id order.
     *
     * @return array<string, array{code: string, name: string, figures: array<string, mixed>}>
     */
    private static function benefitRules(): array
    {
        if (! self::benefitRulesReady()) {
            return [];
        }
        $rules = [];
        $types = PayrollIncentiveType::query()->get()
            ->sort(static fn (PayrollIncentiveType $a, PayrollIncentiveType $b): int => CodeSort::compare($a->code, $b->code) ?: strcmp($a->id, $b->id));
        foreach ($types as $type) {
            $rule = self::benefitRuleOf($type);
            if ($rule->isScheduled()) {
                $rules[$type->id] = ['code' => $type->code, 'name' => $type->name, 'figures' => $rule->toArray()];
            }
        }

        return $rules;
    }

    /**
     * A de minimis line's own cap: the captured rule's, for a scheduled
     * benefit. A typed de minimis line of a type the evidence holds no rule
     * for (not scheduled) is kept under the ceiling whole — the only
     * reading its captured figures allow.
     *
     * @param  array<string, mixed>  $line
     */
    private static function benefitCap(PayrollInputs $inputs, array $line): float
    {
        $cap = $inputs->benefitRules[$line['type_id']]['figures']['cap'] ?? null;

        return is_numeric($cap) ? (float) $cap : 0.0;
    }

    /**
     * The Payroll Deductions rows priced as a percentage of salary, as the
     * evidence captures them, in code order.
     *
     * @return array<string, array{code: string, name: string, figures: array<string, mixed>}>
     */
    private static function deductionRules(): array
    {
        if (! self::percentDeductionsReady()) {
            return [];
        }
        $rules = [];
        $rows = PayrollDeduction::query()->whereNull('calculation')->whereNotNull('percent')->get()
            ->sort(static fn (PayrollDeduction $a, PayrollDeduction $b): int => CodeSort::compare($a->code, $b->code) ?: strcmp($a->id, $b->id));
        foreach ($rows as $row) {
            if (! is_array($row->percent)) {
                continue;
            }
            try {
                $rules[$row->id] = ['code' => $row->code, 'name' => $row->name, 'figures' => PercentDeduction::fromArray($row->percent)->toArray()];
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException("The deduction {$row->name} holds figures payroll cannot read: ".$e->getMessage());
            }
        }

        return $rules;
    }

    /**
     * The members' recurring lines live on a period — started, not ended,
     * not paid off — as the evidence captures them: the line's figures, its
     * catalog row's code and name and an incentive's tax category AS THEY
     * ARE NOW, and what is already paid against it (RecurringLineLedger). A
     * pay data read: an all-data PAYROLL holder's only.
     *
     * A paid-off deduction is kept ONLY when the draft types an amount on
     * its row: no live line may take that amount as its payment, and without
     * the paid-off line it would post as a loose deduction past the total.
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, list<array{type_id: string, code: string, name: string, amount: float}>>  $customDeductions  the draft's typed deduction lines
     * @return array<string, list<array{line_id: string, kind: string, type_id: string, code: string, name: string, amount: float, total: ?float, paid_before: float, frequency: string, starts_on: string, ends_on: ?string, is_taxable: bool, tax: ?string, counts_toward_13th: bool}>>
     */
    private static function recurringLines(Access $actor, array $employeeIds, string $periodStart, array $customDeductions): array
    {
        if ($employeeIds === [] || ! RecurringLines::ready() || ! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            return [];
        }
        $rows = PayrollRecurringLine::query()->whereIn('employee_id', $employeeIds)
            ->where('starts_on', '<=', $periodStart)
            ->where(static fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $periodStart))
            ->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $paid = app(RecurringLineLedger::class)->paidBefore($actor, array_values($rows->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all()));
        $deductions = PayrollDeduction::query()->whereIn('id', $rows->pluck('deduction_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $types = PayrollIncentiveType::query()->whereIn('id', $rows->pluck('incentive_type_id')->filter()->unique()->values()->all())->get()->keyBy('id');

        $typedRows = [];
        foreach ($customDeductions as $employeeId => $typed) {
            foreach ($typed as $line) {
                $typedRows[$employeeId][$line['type_id']] = true;
            }
        }

        $lines = [];
        foreach ($rows as $row) {
            $rule = RecurringLines::rule($row);
            $paidBefore = $paid[$row->id] ?? 0.0;
            if ($rule->paidOff($paidBefore)
                && ! ($rule->kind === RecurringLine::KIND_DEDUCTION && isset($typedRows[$row->employee_id][(string) $row->deduction_id]))) {
                continue;
            }
            if ($rule->kind === RecurringLine::KIND_DEDUCTION) {
                $type = $deductions->get($row->deduction_id);
                if ($type === null) {
                    continue;
                }
                $tax = ['is_taxable' => false, 'tax' => null, 'counts_toward_13th' => false];
            } else {
                $type = $types->get($row->incentive_type_id);
                if ($type === null) {
                    continue;
                }
                $benefit = self::benefitRuleOf($type);
                $tax = ['is_taxable' => (bool) $type->is_taxable, 'tax' => $benefit->tax, 'counts_toward_13th' => $benefit->countsToward13th];
            }
            $lines[$row->employee_id][] = [
                'line_id' => $row->id, 'kind' => $rule->kind,
                'type_id' => (string) $type->id, 'code' => (string) $type->code, 'name' => (string) $type->name,
                'amount' => $rule->amount, 'total' => $rule->total, 'paid_before' => $paidBefore, 'frequency' => $rule->frequency,
                'starts_on' => $rule->startsOn, 'ends_on' => $rule->endsOn,
                ...$tax,
            ];
        }

        return $lines;
    }

    /** Whether the Payroll Deductions list carries percentage rules yet (the window before the cron migrates). */
    public static function percentDeductionsReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payroll_deductions', 'percent'));
    }

    /**
     * The named deduction lines of one member: the typed lines, and for
     * each captured percentage rule the shares it works out — from the
     * member's monthly rate or this payslip's basic or gross pay — as an
     * automatic line, unless the officer typed an amount on that row, which
     * is then the employee share (the employer share stays the rule's). The
     * employee shares of the before-tax rules are returned apart, to come
     * off the withholding base.
     *
     * @param  array<string, array{code: string, name: string, figures: array<string, mixed>}>  $rules
     * @param  list<array{type_id: string, code: string, name: string, amount: float}>  $typed
     * @param  array<string, float>  $ownRates  the member's own employee share per rule, from their Contributions & tax version (the row's share when absent)
     * @return array{0: list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool, recurring_line_id: ?string}>, 1: float}
     */
    private static function percentDeductionLines(array $rules, EmployeePayData $employee, PayslipDraft $computed, array $typed, string $periodStart, array $ownRates = []): array
    {
        $lines = [];
        foreach ($typed as $line) {
            $lines[$line['type_id']] = [...$line, 'employer_amount' => null, 'automatic' => false, 'recurring_line_id' => null];
        }
        $preTax = 0.0;
        foreach ($rules as $rowId => $entry) {
            $rule = PercentDeduction::fromArray($entry['figures']);
            if (isset($ownRates[$rowId])) {
                $rule = $rule->withEmployeeShare((float) $ownRates[$rowId]);
            }
            $base = match ($rule->base) {
                PercentDeduction::BASE_MONTHLY => (float) ($employee->monthly_rate ?? 0.0),
                PercentDeduction::BASE_BASIC => $computed->basic_pay,
                default => $computed->gross_pay,
            };
            $shares = $rule->sharesFor($base, $periodStart);
            if (isset($lines[$rowId])) {
                $lines[$rowId]['employer_amount'] = $shares['employer'];
            } elseif ($shares['employee'] > 0.0 || $shares['employer'] > 0.0) {
                $lines[$rowId] = [
                    'type_id' => $rowId, 'code' => $entry['code'], 'name' => $entry['name'],
                    'amount' => $shares['employee'], 'employer_amount' => $shares['employer'], 'automatic' => true, 'recurring_line_id' => null,
                ];
            } else {
                continue;
            }
            if ($rule->beforeTax) {
                $preTax += $lines[$rowId]['amount'];
            }
        }
        $lines = array_values($lines);
        usort($lines, static fn (array $a, array $b): int => CodeSort::compare($a['code'], $b['code']) ?: strcmp($a['type_id'], $b['type_id']));

        return [$lines, Money::round($preTax)];
    }

    /**
     * A member's approved adjustments as lines: the earnings in the
     * benefit-line shape (TAXABLE taxed like pay, NOT_TAXED added after
     * the tax like a benefit that is not income, never toward the 13th
     * month, never under the yearly ceiling), the deductions in the named
     * deduction shape; each names the adjustment it pays, which finalize
     * marks POSTED. Automatic: an officer cannot drop or retype one here.
     *
     * @param  list<array{id: string, category_id: string, code: string, name: string, kind: string, tax: ?string, amount: float, reason: string}>  $entries
     * @return array{0: list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string, adjustment_id: string}>, 1: list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool, recurring_line_id: ?string, adjustment_id: string}>}
     */
    private static function adjustmentLines(array $entries): array
    {
        $earnings = [];
        $deductions = [];
        foreach ($entries as $entry) {
            $typeId = 'adjustment:'.$entry['id'];
            if ($entry['kind'] === AdjustmentRule::KIND_DEDUCTION) {
                $deductions[] = [
                    'type_id' => $typeId, 'code' => $entry['code'], 'name' => $entry['name'], 'amount' => $entry['amount'],
                    'employer_amount' => null, 'automatic' => true, 'recurring_line_id' => null, 'adjustment_id' => $entry['id'],
                ];

                continue;
            }
            $taxable = $entry['tax'] === AdjustmentRule::TAX_TAXABLE;
            $earnings[] = [
                'type_id' => $typeId, 'code' => $entry['code'], 'name' => $entry['name'], 'is_taxable' => $taxable, 'amount' => $entry['amount'],
                'tax' => $taxable ? BenefitRule::TAX_TAXABLE : BenefitRule::TAX_NOT_INCOME, 'counts_toward_13th' => false,
                'automatic' => true, 'recurring_line_id' => null, 'adjustment_id' => $entry['id'],
            ];
        }

        return [$earnings, $deductions];
    }

    /**
     * The catalog types payroll would propose on its own for one member on
     * a period — due on it and qualifying — by type id. What the incentives
     * dialog needs to refuse a typed 0 on a scheduled benefit.
     *
     * @return list<string>
     */
    public static function scheduledBenefitTypes(Access $actor, string $employeeId, string $periodStart, string $periodEnd): array
    {
        $rules = self::benefitRules();
        if ($rules === [] || ! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            return [];
        }
        $employee = app(EmployeePayContexts::class)->get($actor, [$employeeId])[0] ?? null;
        if ($employee === null) {
            return [];
        }
        $end = CarbonImmutable::parse($periodEnd, 'UTC')->addDay();
        $hired = $employee->date_hired->setTimezone('UTC')->startOfDay();
        $serviceMonths = $hired > $end ? 0 : (int) floor($hired->diffInMonths($end));
        $types = [];
        foreach ($rules as $typeId => $entry) {
            $rule = BenefitRule::fromArray($entry['figures']);
            if ($rule->scheduledFor($periodStart, $periodEnd) && $rule->qualifies($employee->employment_status_id, $serviceMonths)) {
                $types[] = $typeId;
            }
        }

        return $types;
    }

    /** Whether the catalog carries the benefit rules yet (the window before the cron migrates). */
    public static function benefitRulesReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payroll_incentive_types', 'amount_type'));
    }

    /**
     * A catalog row's benefit rule: its figures, or — before the columns
     * exist — the typed shape its flag describes.
     */
    public static function benefitRuleOf(PayrollIncentiveType $type): BenefitRule
    {
        if (! self::benefitRulesReady()) {
            return BenefitRule::typed((bool) $type->is_taxable);
        }
        try {
            return BenefitRule::fromArray([
                'amount_type' => $type->amount_type, 'amount' => $type->amount, 'frequency' => $type->frequency, 'month' => $type->month,
                'statuses' => $type->statuses, 'min_service_months' => $type->min_service_months, 'proration' => $type->proration,
                'tax' => $type->tax, 'cap' => $type->cap, 'approval_reference' => $type->approval_reference, 'counts_toward_13th' => (bool) $type->counts_toward_13th,
            ]);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException("The incentive type {$type->name} holds figures payroll cannot read: ".$e->getMessage());
        }
    }

    /**
     * The scheduled benefits due to one member on this payroll, as
     * automatic lines: every captured rule due on the period that the
     * member qualifies for and has no typed line of, priced from the
     * member's rate, service and — for a per-day amount or a proration by
     * days — the days the engine counted. A rule that works out to nothing
     * adds no line.
     *
     * @param  array<string, array{code: string, name: string, figures: array<string, mixed>}>  $rules
     * @param  list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string}>  $typed  the member's typed and recurring lines
     * @param  Closure(): array{work_days: float, paid_days: float, scheduled: int}  $dayFacts  read only when a rule needs the days
     * @param  list<array{id: string, type_id: string, starts_on: string, ends_on: ?string, amount: ?float}>  $assignments  the member's approved benefit assignments reaching the period, as captured
     * @return list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string}>
     */
    private static function automaticBenefitLines(array $rules, EmployeePayData $employee, array $typed, string $periodStart, string $periodEnd, Closure $dayFacts, array $assignments = []): array
    {
        if ($rules === []) {
            return [];
        }
        $typedTypes = array_flip(array_map(static fn (array $line): string => $line['type_id'], $typed));
        $end = CarbonImmutable::parse($periodEnd, 'UTC')->addDay();
        $hired = $employee->date_hired->setTimezone('UTC')->startOfDay();
        $serviceMonths = $hired > $end ? 0 : (int) floor($hired->diffInMonths($end));
        $yearStart = CarbonImmutable::parse(substr($periodEnd, 0, 4).'-01-01', 'UTC');
        $servedFrom = $hired > $yearStart ? $hired : $yearStart;
        $monthsServedInYear = $servedFrom > $end ? 0 : (int) floor($servedFrom->diffInMonths($end));
        $facts = null;
        $lines = [];
        foreach ($rules as $typeId => $entry) {
            if (isset($typedTypes[$typeId])) {
                continue;
            }
            $rule = BenefitRule::fromArray($entry['figures']);
            if (! $rule->scheduledFor($periodStart, $periodEnd)) {
                continue;
            }
            // An approved assignment reaching the period proposes the benefit
            // whether or not the status rule qualifies the member, at the
            // assignment's own amount when it carries one (TX05, Phase 10).
            [$assigned, $ownAmount] = BenefitAssignments::covering($assignments, (string) $typeId, $periodStart, $periodEnd);
            if (! $assigned && ! $rule->qualifies($employee->employment_status_id, $serviceMonths)) {
                continue;
            }
            if ($ownAmount === null && ($rule->amountType === BenefitRule::AMOUNT_DAILY || $rule->proration === BenefitRule::PRORATION_DAYS)) {
                $facts ??= $dayFacts();
            }
            $amount = $ownAmount ?? $rule->amountFor((float) ($employee->monthly_rate ?? 0.0), $facts['work_days'] ?? 0.0, $facts['paid_days'] ?? 0.0, $facts['scheduled'] ?? 0, $monthsServedInYear);
            if ($amount <= 0.0) {
                continue;
            }
            $lines[] = [
                'type_id' => $typeId,
                'code' => $entry['code'],
                'name' => $entry['name'],
                'is_taxable' => $rule->isTaxable(),
                'amount' => $amount,
                'tax' => $rule->tax,
                'counts_toward_13th' => $rule->countsToward13th,
                'automatic' => true,
                'recurring_line_id' => null,
            ];
        }

        return $lines;
    }

    /**
     * One member's recurring incentive lines. A typed line on a recurring
     * line's type is that line's payment, so it is tagged with it — once,
     * never re-tagged by a second line of the same type; every
     * other recurring incentive due on the period and not skipped becomes an
     * automatic line at its amount, priced under the tax category it was
     * captured with.
     *
     * @param  list<array{line_id: string, kind: string, type_id: string, code: string, name: string, amount: float, total: ?float, paid_before: float, frequency: string, starts_on: string, ends_on: ?string, is_taxable: bool, tax: ?string, counts_toward_13th: bool}>  $recurring
     * @param  list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string}>  $typed
     * @param  list<string>  $skipped
     * @return array{0: list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string}>, 1: list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string}>}
     */
    private static function recurringIncentiveLines(array $recurring, array $typed, array $skipped, string $periodStart): array
    {
        $proposed = [];
        foreach ($recurring as $entry) {
            if ($entry['kind'] !== RecurringLine::KIND_INCENTIVE) {
                continue;
            }
            foreach ($typed as $index => $line) {
                if ($line['type_id'] === $entry['type_id'] && $line['recurring_line_id'] === null) {
                    $typed[$index]['recurring_line_id'] = $entry['line_id'];

                    continue 2;
                }
            }
            $rule = self::recurringRule($entry);
            if (in_array($entry['line_id'], $skipped, true) || ! $rule->dueOn($periodStart)) {
                continue;
            }
            $proposed[] = [
                'type_id' => $entry['type_id'], 'code' => $entry['code'], 'name' => $entry['name'],
                'is_taxable' => $entry['is_taxable'], 'amount' => $rule->proposed($entry['paid_before']),
                'tax' => (string) $entry['tax'], 'counts_toward_13th' => $entry['counts_toward_13th'],
                'automatic' => true, 'recurring_line_id' => $entry['line_id'],
            ];
        }

        return [$typed, $proposed];
    }

    /**
     * One member's named deduction lines with their recurring deductions:
     * a typed line on a recurring line's row is that line's payment, so it
     * is tagged with it; every other recurring deduction due on the period
     * and not skipped becomes an automatic line at its amount, or at what
     * its total has left when that is less. A line already tagged — another
     * recurring line's proposal or payment — is NEVER re-tagged, or one
     * loan's payment would post to another loan's balance. Live lines claim
     * a typed payment before a paid-off one, which is captured only to have
     * an unclaimed typed amount on its row refused (recurringBalanceErrors).
     *
     * @param  list<array{line_id: string, kind: string, type_id: string, code: string, name: string, amount: float, total: ?float, paid_before: float, frequency: string, starts_on: string, ends_on: ?string, is_taxable: bool, tax: ?string, counts_toward_13th: bool}>  $recurring
     * @param  list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool, recurring_line_id: ?string}>  $named
     * @param  list<string>  $skipped
     * @return list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool, recurring_line_id: ?string}>
     */
    private static function recurringDeductionLines(array $recurring, array $named, array $skipped, string $periodStart): array
    {
        $live = [];
        $paidOff = [];
        foreach ($recurring as $entry) {
            if (self::recurringRule($entry)->paidOff($entry['paid_before'])) {
                $paidOff[] = $entry;
            } else {
                $live[] = $entry;
            }
        }
        $added = false;
        foreach ([...$live, ...$paidOff] as $entry) {
            if ($entry['kind'] !== RecurringLine::KIND_DEDUCTION) {
                continue;
            }
            foreach ($named as $index => $line) {
                if ($line['type_id'] === $entry['type_id'] && $line['recurring_line_id'] === null) {
                    $named[$index]['recurring_line_id'] = $entry['line_id'];

                    continue 2;
                }
            }
            $rule = self::recurringRule($entry);
            $amount = $rule->proposed($entry['paid_before']);
            if (in_array($entry['line_id'], $skipped, true) || ! $rule->dueOn($periodStart) || $amount <= 0.0) {
                continue;
            }
            $named[] = [
                'type_id' => $entry['type_id'], 'code' => $entry['code'], 'name' => $entry['name'],
                'amount' => $amount, 'employer_amount' => null, 'automatic' => true, 'recurring_line_id' => $entry['line_id'],
            ];
            $added = true;
        }
        if ($added) {
            usort($named, static fn (array $a, array $b): int => CodeSort::compare($a['code'], $b['code']) ?: strcmp($a['type_id'], $b['type_id']));
        }

        return $named;
    }

    /**
     * The refusals for typed payments larger than what a recurring
     * deduction's total has left — any amount, once it is paid off. A
     * paid-off line stops holding the row only once its last date is before
     * this period starts, so the refusal names that day: the End dialog's
     * default (today) would keep it on this payroll.
     *
     * @param  list<array{line_id: string, kind: string, type_id: string, code: string, name: string, amount: float, total: ?float, paid_before: float, frequency: string, starts_on: string, ends_on: ?string, is_taxable: bool, tax: ?string, counts_toward_13th: bool}>  $recurring
     * @param  list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool, recurring_line_id: ?string}>  $named
     * @return list<string>
     */
    private static function recurringBalanceErrors(array $recurring, array $named, string $periodStart): array
    {
        $dayBefore = PayrollPeriodText::day(CarbonImmutable::parse($periodStart, 'UTC')->subDay()->format('Y-m-d'));
        $errors = [];
        foreach ($recurring as $entry) {
            $left = self::recurringRule($entry)->left($entry['paid_before']);
            if ($left === null) {
                continue;
            }
            foreach ($named as $line) {
                if (! $line['automatic'] && $line['recurring_line_id'] === $entry['line_id'] && Money::round($line['amount']) > $left) {
                    $errors[] = $left <= 0.0
                        ? "{$entry['name']} is already paid off, so ".self::peso($line['amount']).' cannot be deducted for it. Clear the amount, or end the paid-off line in the employee\'s pay settings on '.$dayBefore.' or earlier.'
                        : "{$entry['name']}: ".self::peso($line['amount']).' is more than the '.self::peso($left).' left to pay. Type at most '.self::peso($left).'.';
                }
            }
        }

        return $errors;
    }

    /**
     * The members with a recurring line this payroll proposes or counts: a
     * paid-off line captured only to refuse a typed amount gives a member
     * nothing to skip.
     *
     * @return list<string>
     */
    public static function recurringMembers(PayrollInputs $inputs): array
    {
        $members = [];
        foreach ($inputs->recurringLines as $employeeId => $lines) {
            foreach ($lines as $entry) {
                if (! self::recurringRule($entry)->paidOff($entry['paid_before'])) {
                    $members[] = (string) $employeeId;

                    continue 2;
                }
            }
        }

        return $members;
    }

    /**
     * A captured recurring line's rule.
     *
     * @param  array{line_id: string, kind: string, type_id: string, code: string, name: string, amount: float, total: ?float, paid_before: float, frequency: string, starts_on: string, ends_on: ?string, is_taxable: bool, tax: ?string, counts_toward_13th: bool}  $entry
     */
    private static function recurringRule(array $entry): RecurringLine
    {
        return RecurringLine::fromArray([
            'kind' => $entry['kind'], 'amount' => $entry['amount'], 'total' => $entry['total'],
            'frequency' => $entry['frequency'], 'starts_on' => $entry['starts_on'], 'ends_on' => $entry['ends_on'],
        ]);
    }

    /**
     * Capture the mutable catalog's names with each positive amount. A row
     * with a calculation is the engine's own line and cannot be charged
     * twice, so only rows without one are read.
     *
     * @param  array<string, array<string, mixed>>  $draftCustomDeductions
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{type_id: string, code: string, name: string, amount: float}>>
     */
    private static function customDeductionLines(array $draftCustomDeductions, array $employeeIds): array
    {
        $typeIds = [];
        foreach ($employeeIds as $employeeId) {
            foreach (array_keys($draftCustomDeductions[$employeeId] ?? []) as $typeId) {
                $typeIds[(string) $typeId] = true;
            }
        }
        if ($typeIds === []) {
            return [];
        }

        $types = PayrollDeduction::query()->whereIn('id', array_keys($typeIds))
            ->whereNull('calculation')->get()
            ->sort(static fn (PayrollDeduction $a, PayrollDeduction $b): int => CodeSort::compare($a->code, $b->code) ?: strcmp($a->id, $b->id))
            ->values();

        $lines = [];
        foreach ($employeeIds as $employeeId) {
            $amounts = $draftCustomDeductions[$employeeId] ?? [];
            foreach ($types as $type) {
                $amount = $amounts[$type->id] ?? null;
                if (! is_numeric($amount) || ! is_finite((float) $amount) || Money::round($amount) <= 0) {
                    continue;
                }
                $lines[$employeeId][] = [
                    'type_id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'amount' => Money::round($amount),
                ];
            }
        }

        return $lines;
    }

    /** Computes only the captured bundle, including replay after source records change.
     * @return array{
     *     drafts: list<PayslipDraft>, employees: Collection<int, EmployeePayData>,
     *     issues: list<array{employee_id: string, message: string}>,
     *     draftErrors: list<array{employee_id: string, message: string}>,
     *     combined: list<array{employee_id: string, message: string}>, inputs: PayrollInputs,
     *     statutory: array<string, array{computed: array<string, float>, overridden: list<string>, off: list<string>, withholding_rederived: bool, benefits: array{ytd_before: float, exempt: float, ceiling: float, taxable_excess: float}}>,
     *     incentiveLines: array<string, list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string, over_ceiling: float, cash_out?: array{id: string, days: float, amount: float}, adjustment_id?: string}>>,
     *     customDeductionLines: array<string, list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool, recurring_line_id: ?string, adjustment_id?: string}>>,
     *     settlementLines: array<string, list<array{payslip_id: string, run_code: string, from: string, to: string, presumed: array<string, float>, actual: array<string, float>, presumed_amount: float, actual_amount: float, amount: float}>>,
     *     leaveChargeLines: array<string, array{lines: list<array{date: string, kind: string, minutes: ?int, days: float, covered: array<string, float>, uncovered_days: float, fraction: float}>, balances: array<string, float>, notes: list<string>}>,
     *     presumedLines: array<string, array<string, float>>,
     * }
     */
    public static function calculateInputs(PayrollInputs $inputs, bool $strict = false): array
    {
        if ($inputs->calculatorVersion !== CalculatorVersion::current()) {
            throw new RuntimeException('This payroll evidence requires its recorded calculation version.');
        }
        $employeeIds = $inputs->employeeIds;
        $periodStart = $inputs->periodStart;
        $periodEnd = $inputs->periodEnd;
        $employees = new Collection($inputs->employees);
        $attendance = $inputs->attendance;
        $settings = ['formulas' => $inputs->formulas, 'holidays' => $inputs->holidays];
        // The figures the captured table versions hold; built-in for
        // evidence captured before the tables existed.
        $schedule = $inputs->schedule();
        $draftDeductions = $inputs->deductions;
        $draftAllowances = $inputs->allowances;
        $draftEarnings = $inputs->earnings;
        $employeeById = $employees->keyBy('id');
        if ($strict && $employeeById->count() !== count(array_unique($employeeIds))) {
            throw new RuntimeException('Payroll could not load every selected employee.');
        }
        $computeDtr = static fn (array $logs, mixed $shift, array $leaves, string $start, string $end, array $holidays): array => DtrCalculator::compute(array_values($logs), $shift, array_values($leaves), $start, $end, array_values($holidays));

        $drafts = [];
        $issues = [];
        $draftErrors = [];
        $statutory = [];
        $incentiveLines = [];
        $customDeductionLines = [];
        // DIVERGENCE 14: each member's settlements, priced from the capture.
        $settlementLines = [];
        // DIVERGENCE 14: on an advance payroll, each member's presumed days
        // priced on their own — the figure the settlement will read from
        // this run's evidence, frozen by the engine that pays them.
        $presumedLines = [];
        // DIVERGENCE 15: what each member's payroll charges to leave credits.
        $leaveChargeLines = [];
        // The last day priced from attendance (the counted date of an
        // advance payroll); what comes after it is presumed, never read.
        $attendanceEnd = $inputs->attendanceEnd();
        // The server folds readiness issues and draft errors into ONE list,
        // per employee, readiness first (payroll-server-calculation.ts:83-118)
        // — finalize's `issues[0]` reads from this order.
        $combined = [];

        // Member ORDER is user-visible: the drafts come out in employee_ids
        // order, exactly as calculatePayrollDrafts maps over the input list.
        foreach ($employeeIds as $employeeId) {
            /** @var EmployeePayData|null $employee */
            $employee = $employeeById->get($employeeId);

            if ($employee === null) {
                continue; // non-strict preview: filtered exactly as the client hook does
            }

            $employeeLogs = $attendance[$employeeId]->logs;
            $employeeLeaves = $attendance[$employeeId]->leaves;
            // Null degrades to DEFAULT_SHIFT inside the domain layer, the
            // counterpart of `shifts.find(...) ?? DEFAULT_SHIFT`.
            $shift = $attendance[$employeeId]->shift;

            // The Pay Basis & Rates this member is priced under (DIVERGENCE 13).
            $rules = $inputs->rulesFor($employeeId);

            // Readiness reads the days the payslip reads: through the counted
            // date on an advance payroll, so an open Time In on a presumed
            // day — someone at work right now — is no issue.
            foreach (PayrollReadiness::issues(
                $computeDtr,
                $employeeLogs,
                $shift,
                $employeeLeaves,
                $periodStart,
                $attendanceEnd,
                PayrollPeriod::dateKeyFromDate($employee->date_hired),
                $employee->date_resigned !== null ? PayrollPeriod::dateKeyFromDate($employee->date_resigned) : null,
                $settings['holidays'],
                $rules->prices('DOUBLE'),
            ) as $issue) {
                $row = ['employee_id' => $employeeId, 'message' => $issue['message']];
                $issues[] = $row;
                $combined[] = $row;
            }

            $allowanceOverride = $draftAllowances[$employeeId] ?? null;
            $earningsOverride = $draftEarnings[$employeeId] ?? null;

            /*
             * Incentives and bonuses (DELIBERATE DIVERGENCE: the reference
             * passes bonus = 0, payroll-server-calculation.ts:109).
             * The tax-exempt lines are split against the PHP 90,000 yearly
             * ceiling FIRST (ExemptBenefitCeiling, the one rule every run
             * kind uses), in line order, from what this employee already
             * posted this year. The taxable lines and any excess over the
             * ceiling reach the engine as `$bonus`, so they are taxed like
             * pay; the exempt part is added to gross and net AFTER the tax.
             */
            $typedLines = array_map(static fn (array $line): array => [...$line, 'automatic' => false, 'recurring_line_id' => null], $inputs->incentives[$employeeId] ?? []);
            // The member's recurring lines (captured with the run): a typed
            // line on a recurring line's type is that line's payment; every
            // other one due here, and not skipped, is proposed on its own,
            // ahead of a scheduled benefit of the same type.
            $recurring = $inputs->recurringLines[$employeeId] ?? [];
            $skippedRecurring = $inputs->skippedRecurringLines[$employeeId] ?? [];
            [$typedLines, $recurringIncentives] = self::recurringIncentiveLines($recurring, $typedLines, $skippedRecurring, $periodStart);
            // The scheduled benefits due to this member (DELIBERATE DIVERGENCE:
            // the catalog's rules, captured with the run), priced from the
            // engine's own day counts when a rule asks for them — one extra
            // pass with nothing added, read only then.
            // DIVERGENCE 14: the member's settlements of earlier presumed
            // days, priced from the capture; their sum joins this payslip's
            // gross pay through the engine.
            /*
             * DELIBERATE DIVERGENCE 15: lateness, undertime and absences are
             * charged to leave credits FIRST, by the member's policy. The
             * plan is made from the captured facts over the SAME DTR rows
             * the engine prices — this payslip's period and each settled
             * range — and the engine prices only what credits did not cover.
             */
            $chargeEntry = $inputs->leaveCharges[$employeeId] ?? null;
            $chargePlan = null;
            $engineCharges = null;
            if ($chargeEntry !== null) {
                $chargeFacts = LeaveChargeLines::dayFacts(PayslipCalculator::attendanceDays($employee, $employeeLogs, $shift, $employeeLeaves, $periodStart, $periodEnd, $settings['holidays'], $inputs->attendanceThrough)[0], $shift);
                // The settled days as the settlement's actual side prices
                // them: today's leaving date, this payroll's holidays.
                foreach ($inputs->settlements[$employeeId] ?? [] as $settled) {
                    $chargeFacts = [...$chargeFacts, ...LeaveChargeLines::dayFacts(PayslipCalculator::attendanceDays(AdvanceSettlements::actualEmployee($settled), $settled->actual->logs, $settled->actual->shift, $settled->actual->leaves, $settled->from, $settled->to, $settings['holidays'], null)[0], $settled->actual->shift)];
                }
                $chargePlan = app(LeaveChargeSource::class)->plan($chargeEntry, $chargeFacts);
                $engineCharges = LeaveChargeLines::engineShape($chargePlan['lines'], $periodStart, $periodEnd);
                if ($chargePlan['lines'] !== [] || $chargePlan['notes'] !== []) {
                    $leaveChargeLines[$employeeId] = $chargePlan;
                }
            }
            $memberSettlements = array_map(
                static fn (SettlementInputs $s): array => AdvanceSettlements::price($s, $chargePlan === null ? null : LeaveChargeLines::engineShape($chargePlan['lines'], $s->from, $s->to), $settings['holidays']),
                $inputs->settlements[$employeeId] ?? [],
            );
            if ($memberSettlements !== []) {
                $settlementLines[$employeeId] = $memberSettlements;
            }
            $settlement = Money::round(array_sum(array_column($memberSettlements, 'amount')));
            if ($inputs->attendanceThrough !== null && $inputs->attendanceThrough < $periodEnd) {
                $presumedLines[$employeeId] = AdvanceSettlements::presumedLines(
                    $employee, $shift, $employeeLeaves, CalendarDays::next($inputs->attendanceThrough), $periodEnd,
                    $inputs->formulas, $settings['holidays'], $rules,
                );
            }
            $engineArguments = [
                'employee' => $employee, 'logs' => $employeeLogs, 'shift' => $shift, 'leaves' => $employeeLeaves,
                'periodStart' => $periodStart, 'periodEnd' => $periodEnd,
                'allowancesOverride' => is_numeric($allowanceOverride) ? (float) $allowanceOverride : null,
                'earningsOverride' => is_array($earningsOverride) ? $earningsOverride : null,
                'settings' => $settings, 'overtimeApprovals' => $attendance[$employeeId]->overtimeApprovals,
                'overtimeCategories' => $attendance[$employeeId]->overtimeCategories,
                'statutory' => $schedule, 'rules' => $rules, 'timeline' => $inputs->timelineFor($employeeId),
                'contributionPosted' => $inputs->contributionPosted[$employeeId] ?? null,
                'attendanceThrough' => $inputs->attendanceThrough, 'settlement' => $settlement, 'leaveCharges' => $engineCharges,
            ];
            $automatic = self::automaticBenefitLines($inputs->benefitRules, $employee, [...$typedLines, ...$recurringIncentives], $periodStart, $periodEnd, static function () use ($engineArguments, $shift, $periodStart, $periodEnd): array {
                $days = PayslipCalculator::compute(...$engineArguments, bonus: 0.0);

                return ['work_days' => $days->work_days, 'paid_days' => $days->work_days + $days->paid_leave_days, 'scheduled' => ShiftResolver::scheduledWorkDaysForShift($periodStart, $periodEnd, $shift)];
            }, $inputs->benefitAssignments[$employeeId] ?? []);
            // The leave credit cash-outs this member is owed, each a line of
            // the catalog type marked for them, priced at the member's daily
            // rate under the captured rules (PayslipCalculator::dailyRateOn)
            // — taxed or exempt as that type says, like any benefit line.
            // Nothing marked, or no rate to price with, refuses the draft.
            $cashOutLines = [];
            foreach ($inputs->leaveCashOuts[$employeeId] ?? [] as $cashOut) {
                $type = $inputs->cashOutType;
                if ($type === null) {
                    $row = ['employee_id' => $employeeId, 'message' => 'Leave credit cash-outs are waiting, but no Incentives & Bonuses type pays them. Mark one under Payroll Settings first.'];
                    $draftErrors[] = $row;
                    $combined[] = $row;
                    break;
                }
                $amount = Money::round($cashOut['days'] * PayslipCalculator::dailyRateOn($employee, $periodStart, $shift, $rules));
                if ($amount <= 0.0) {
                    $row = ['employee_id' => $employeeId, 'message' => 'A leave credit cash-out cannot be priced: the employee has no Rate Per Month or Rate Per Day.'];
                    $draftErrors[] = $row;
                    $combined[] = $row;
                    break;
                }
                $cashOutLines[] = [
                    'type_id' => $type['type_id'], 'code' => $type['code'],
                    'name' => $type['name'].' · '.$cashOut['name'].' '.self::daysText($cashOut['days']),
                    'is_taxable' => $type['is_taxable'], 'amount' => $amount, 'tax' => $type['tax'], 'counts_toward_13th' => $type['counts_toward_13th'],
                    'automatic' => true, 'recurring_line_id' => null,
                    'cash_out' => ['id' => $cashOut['id'], 'days' => $cashOut['days'], 'amount' => $amount],
                ];
            }
            // The approved adjustments this payroll pays (the ledger, from
            // the evidence): an earning joins the benefit lines — taxable
            // like pay, or not taxed and added after the tax — and a
            // deduction joins the named deductions after the tax.
            [$adjustmentEarnings, $adjustmentDeductions] = self::adjustmentLines($inputs->adjustments[$employeeId] ?? []);
            $lines = [...$typedLines, ...$recurringIncentives, ...$automatic, ...$cashOutLines, ...$adjustmentEarnings];
            usort($lines, static fn (array $a, array $b): int => CodeSort::compare($a['code'], $b['code']) ?: strcmp($a['type_id'], $b['type_id']));
            $ytdBefore = Money::round($inputs->exemptBenefitsYearToDate[$employeeId] ?? 0.0);
            // What spends the yearly ceiling, in line order: an exempt line
            // whole, a de minimis line's part over its own cap.
            $ceilingAmounts = [];
            foreach ($lines as $index => $line) {
                if ($line['tax'] === BenefitRule::TAX_EXEMPT) {
                    $ceilingAmounts[$index] = $line['amount'];
                } elseif ($line['tax'] === BenefitRule::TAX_DE_MINIMIS) {
                    $ceilingAmounts[$index] = Money::round(max(0.0, $line['amount'] - self::benefitCap($inputs, $line)));
                }
            }
            $splits = array_combine(array_keys($ceilingAmounts), ExemptBenefitCeiling::allocate($ytdBefore, array_values($ceilingAmounts), $inputs->ceiling()));
            $taxableTotal = 0.0;
            $exemptTotal = 0.0;
            $ceilingTotal = 0.0;
            $excessTotal = 0.0;
            $posted = [];
            foreach ($lines as $index => $line) {
                $overCeiling = 0.0;
                switch ($line['tax']) {
                    case BenefitRule::TAX_TAXABLE:
                        $taxableTotal += $line['amount'];
                        break;
                    case BenefitRule::TAX_NOT_INCOME:
                        $exemptTotal += $line['amount'];
                        break;
                    case BenefitRule::TAX_DE_MINIMIS:
                        // Tax-free up to its own cap; the rest is an exempt
                        // benefit like any other, under the ceiling.
                        $exemptTotal += min($line['amount'], self::benefitCap($inputs, $line));
                        $split = $splits[$index];
                        $exemptTotal += $split->exempt;
                        $ceilingTotal += $split->exempt;
                        $excessTotal += $split->taxableExcess;
                        $overCeiling = $split->taxableExcess;
                        break;
                    default:
                        $split = $splits[$index];
                        $exemptTotal += $split->exempt;
                        $ceilingTotal += $split->exempt;
                        $excessTotal += $split->taxableExcess;
                        $overCeiling = $split->taxableExcess;
                }
                $posted[] = [...$line, 'over_ceiling' => $overCeiling];
            }
            $exemptTotal = Money::round($exemptTotal);
            $ceilingTotal = Money::round($ceilingTotal);
            $excessTotal = Money::round($excessTotal);
            if ($posted !== []) {
                $incentiveLines[$employeeId] = $posted;
            }

            // Named arguments, so a trailing engine input added later cannot
            // shift a positional one into the wrong slot.
            $computed = PayslipCalculator::compute(
                employee: $employee,
                logs: $employeeLogs,
                shift: $shift,
                leaves: $employeeLeaves,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                bonus: Money::round($taxableTotal + $excessTotal),
                allowancesOverride: is_numeric($allowanceOverride) ? (float) $allowanceOverride : null,
                earningsOverride: is_array($earningsOverride) ? $earningsOverride : null,
                settings: $settings,
                // DIVERGENCE 7: overtime is paid as approved per day on the
                // Daily Time Record, never by a run-level switch. No approval
                // means zero pay and needs no explicit rejection; finalizing
                // over an undecided day takes the officer's "Finalize anyway"
                // (OpenAttendanceItems).
                overtimeApprovals: $attendance[$employeeId]->overtimeApprovals,
                // DIVERGENCE 16: the days approved as seaport overtime.
                overtimeCategories: $attendance[$employeeId]->overtimeCategories,
                // The Contribution & Tax Table versions the evidence captured.
                statutory: $schedule,
                // The Pay Basis & Rates version the evidence captured, and
                // (DIVERGENCE 17) the versions taking over inside the period.
                rules: $rules,
                timeline: $inputs->timelineFor($employeeId),
                // DIVERGENCE 19: what the contribution month's posted payslips
                // already took, for the schedules that take the rest.
                contributionPosted: $inputs->contributionPosted[$employeeId] ?? null,
                // DIVERGENCE 14: the advance payroll's counted date and the
                // settlement of earlier presumed days, both from the evidence.
                attendanceThrough: $inputs->attendanceThrough,
                settlement: $settlement,
                // DIVERGENCE 15: what leave credits cover of this period's
                // lateness and absences, from the plan above.
                leaveCharges: $engineCharges,
            );

            $typed = $draftDeductions[$employeeId] ?? null;
            $typed = is_array($typed) ? $typed : null;
            // A computed deduction the list switched OFF (its row deleted)
            // is exempt for everyone: a 0 override, which the typed-0 path
            // already prices, with the tax re-derived from what is actually
            // deducted. Late and undertime off means neither line is taken,
            // and the tax base rises accordingly.
            [$computed, $overrides, $off] = self::switchOff($computed, $typed, $inputs->activeCalculations);
            // Named deductions: the typed lines and the percentage rules'
            // shares (DELIBERATE DIVERGENCE: the reference has one typed
            // Other line). A rule taken before tax lowers the base the
            // withholding is re-derived on.
            [$named, $preTax] = self::percentDeductionLines(
                $inputs->deductionRules, $employee, $computed,
                array_map(static fn (array $line): array => $line, $inputs->customDeductions[$employeeId] ?? []),
                $periodStart,
                $inputs->govPolicies[$employeeId]['figures']['percent_rates'] ?? [],
            );
            // Recurring deductions join the named lines after tax, like any
            // typed loan or cash advance; so do the approved adjustment
            // deductions.
            $named = [...self::recurringDeductionLines($recurring, $named, $skippedRecurring, $periodStart), ...$adjustmentDeductions];
            [$rederived, $taxRederived] = self::rederiveWithholding($computed, $employee, $overrides, force: in_array(DeductionCalculation::LATE_UNDERTIME, $off, true), schedule: $schedule, preTax: $preTax);
            // DIVERGENCE 20: a member priced by the whole-year estimate takes
            // the year's remaining tax instead of the engine's per-cut-off
            // figure, from the captured year facts; a refund on the year's
            // last payroll joins the net pay.
            [$rederived, $taxRederived] = self::applyWholeYear($rederived, $employee, $overrides, $inputs->taxYear, $schedule, $preTax, $taxRederived);

            // The tax-exempt part joins gross and net after the tax, and the
            // payslip records both halves for the ledger.
            $gross = Money::round($rederived->gross_pay + $exemptTotal);
            $rederived = $rederived->with([
                'gross_pay' => $gross,
                'net_pay' => Money::round($gross - $rederived->total_deductions + $rederived->tax_refund),
                'exempt_benefits' => $exemptTotal,
                'taxable_benefits_excess' => $excessTotal,
                'ceiling_benefits' => $ceilingTotal,
            ]);
            $draft = self::applyDeductionOverrides($rederived, $overrides);

            // DELIBERATE DIVERGENCE 9: the reference has only one typed
            // Other line. Named catalog deductions are additional, itemised
            // amounts. They join the deduction footing AFTER statutory tax
            // is computed, so loans and cash advances cannot reduce the tax
            // base or be hidden inside the free-form Other figure.
            if ($named !== []) {
                $customDeductionLines[$employeeId] = $named;
                $draft = $draft->withCustomDeductions(array_column($named, 'amount'));
            }

            // The taxable base the posted withholding stands on, from the
            // shares the payslip actually carries (payroll.ts:644-647),
            // less what was deducted before tax.
            $draft = $draft->with(['taxable_compensation' => Money::round(max(0.0, StatutoryLines::taxable(
                $computed->gross_pay, $draft->sss, $draft->philhealth, $draft->pagibig,
                $draft->late_deduction, $draft->undertime_deduction,
            ) - $preTax)), 'tax_method' => self::taxMethodOf($employee)]);

            // What the engine computed for each statutory line, which lines
            // the officer overtyped, and whether the tax was re-derived — the
            // record finalize writes beside the posted results.
            $statutory[$employeeId] = [
                'computed' => [
                    'sss' => $computed->sss, 'sss_er' => $computed->sss_er,
                    'philhealth' => $computed->philhealth, 'philhealth_er' => $computed->philhealth_er,
                    'pagibig' => $computed->pagibig, 'pagibig_er' => $computed->pagibig_er,
                    'withholding_tax' => $computed->withholding_tax,
                ],
                'overridden' => self::overriddenStatutoryKeys($typed),
                // The computed deductions the list had switched off (their
                // rows deleted) when this was priced.
                'off' => $off,
                'withholding_rederived' => $taxRederived,
                'benefits' => ['ytd_before' => $ytdBefore, 'exempt' => $exemptTotal, 'ceiling' => $ceilingTotal, 'taxable_excess' => $excessTotal],
            ];

            $error = self::draftError($draft);
            if ($error !== null) {
                $row = ['employee_id' => $employeeId, 'message' => $error];
                $draftErrors[] = $row;
                $combined[] = $row;
            }
            // A typed payment on a recurring deduction cannot pay more than
            // its total has left.
            foreach (self::recurringBalanceErrors($recurring, $named, $inputs->periodStart) as $message) {
                $row = ['employee_id' => $employeeId, 'message' => $message];
                $draftErrors[] = $row;
                $combined[] = $row;
            }

            // The minimum take-home pay of the member's Pay Basis & Rates: a
            // net pay under it is flagged, never cut on its own.
            if ($rules->minimumTakeHome !== null && $draft->net_pay < $rules->minimumTakeHome) {
                $row = ['employee_id' => $employeeId, 'message' => 'Net pay '.number_format($draft->net_pay, 2).' is under the minimum take-home pay of '.number_format($rules->minimumTakeHome, 2).'. Skip a loan or deduction on this payroll, or type a smaller amount.'];
                $issues[] = $row;
                $combined[] = $row;
            }
            $drafts[] = $draft;
        }

        return ['drafts' => $drafts, 'employees' => $employees, 'issues' => $issues, 'draftErrors' => $draftErrors, 'combined' => $combined, 'inputs' => $inputs, 'statutory' => $statutory, 'incentiveLines' => $incentiveLines, 'customDeductionLines' => $customDeductionLines, 'settlementLines' => $settlementLines, 'leaveChargeLines' => $leaveChargeLines, 'presumedLines' => $presumedLines];
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
     * A fourth map, `incentives` (employee id => [incentive type id =>
     * amount]), comes from each member's lines: eager-loaded rows
     * on a model (`members.incentives`), or the `incentives` projection
     * PayrollRecords puts on a member view; and a fifth, `additional`
     * (employee id => basic pay earned outside EMS), which only a 13th month
     * run reads.
     *
     * @param  iterable<PayrollRunMember|PayrollRunMemberView>  $members
     * @return array{
     *     deductions: array<string, array<string, float>>,
     *     allowances: array<string, float>,
     *     earnings: array<string, array<string, mixed>>,
     *     incentives: array<string, array<string, float>>,
     *     customDeductions: array<string, array<string, float>>,
     *     additional: array<string, float>,
     *     skippedRecurring: array<string, list<string>>,
     * }
     */
    public static function overridesFromMembers(iterable $members): array
    {
        $deductions = [];
        $allowances = [];
        $earnings = [];
        $incentives = [];
        $customDeductions = [];
        $additional = [];
        $skipped = [];

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

            // `overtime_acknowledged` is never read: overtime is approved per
            // day on the Daily Time Record, so a switch stored in that column
            // cannot pay anything.

            $lines = [];
            if ($member instanceof PayrollRunMember) {
                foreach ($member->incentives as $line) {
                    $lines[$line->type_id] = (float) $line->amount;
                }
            } elseif (is_array($member->incentives)) {
                foreach ($member->incentives as $typeId => $amount) {
                    $lines[(string) $typeId] = (float) $amount;
                }
            }
            if ($lines !== []) {
                ksort($lines, SORT_STRING);
                $incentives[$member->employee_id] = $lines;
            }

            $named = [];
            if ($member instanceof PayrollRunMember) {
                foreach ($member->deductions as $line) {
                    $named[$line->deduction_id] = (float) $line->amount;
                }
            } elseif (is_array($member->deductions)) {
                foreach ($member->deductions as $typeId => $amount) {
                    $named[(string) $typeId] = (float) $amount;
                }
            }
            if ($named !== []) {
                ksort($named, SORT_STRING);
                $customDeductions[$member->employee_id] = $named;
            }

            // 13th month runs: the basic pay typed as earned outside EMS.
            if ($member->additional_basic_pay !== null) {
                $additional[$member->employee_id] = (float) $member->additional_basic_pay;
            }

            // The recurring lines skipped on this payroll.
            $skips = $member->skipped_recurring_lines ?? null;
            if (is_array($skips) && $skips !== []) {
                $skipped[$member->employee_id] = $skips;
            }
        }

        return ['deductions' => $deductions, 'allowances' => $allowances, 'earnings' => $earnings, 'incentives' => $incentives, 'customDeductions' => $customDeductions, 'additional' => $additional, 'skippedRecurring' => $skipped];
    }

    /**
     * @param  array<string, string>  $memberIdsByEmployee
     * @param  array<string, array<string, float>>  $customDeductions
     */
    public static function writeMemberDeductions(array $memberIdsByEmployee, array $customDeductions): int
    {
        $written = 0;
        foreach ($memberIdsByEmployee as $employeeId => $memberId) {
            foreach ($customDeductions[$employeeId] ?? [] as $typeId => $amount) {
                PayrollRunMemberDeduction::create([
                    'member_id' => $memberId,
                    'deduction_id' => (string) $typeId,
                    'amount' => Money::round($amount),
                ]);
                $written++;
            }
        }

        return $written;
    }

    /**
     * Writes one run's incentive lines under its (just re-created) members —
     * inside SavePayroll's locked, audited transaction. Only members, only
     * positive amounts; the types were validated by the caller.
     *
     * @param  array<string, string>  $memberIdsByEmployee  employee id => payroll_run_members.id
     * @param  array<string, array<string, float>>  $incentives
     * @return int the lines written
     */
    public static function writeMemberIncentives(array $memberIdsByEmployee, array $incentives): int
    {
        $written = 0;
        foreach ($memberIdsByEmployee as $employeeId => $memberId) {
            foreach ($incentives[$employeeId] ?? [] as $typeId => $amount) {
                PayrollRunMemberIncentive::create(['member_id' => $memberId, 'type_id' => (string) $typeId, 'amount' => Money::round($amount)]);
                $written++;
            }
        }

        return $written;
    }

    /**
     * The pivot columns one member row snapshots for one employee — the
     * "full snapshot" a bare attach() cannot write.
     *
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  array<string, mixed>  $allowances
     * @param  array<string, array<string, mixed>>  $earnings
     * @param  array<string, mixed>  $additionalBasicPay  13th month runs: employee id => basic pay earned outside EMS
     * @param  array<string, list<string>>  $skippedRecurring  employee id => the recurring lines skipped on this payroll
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
        array $additionalBasicPay = [],
        array $skippedRecurring = [],
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
            'additional_basic_pay' => null,
        ];

        // 13th month runs only: basic pay earned outside EMS.
        $additional = $additionalBasicPay[$employeeId] ?? null;
        if (is_numeric($additional)) {
            $columns['additional_basic_pay'] = (float) $additional;
        }

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

        // `overtime_acknowledged` stays NULL: no run-level switch pays
        // overtime; it is approved on the Daily Time Record.

        // The recurring lines skipped on this payroll, NULL when none (only
        // once the column exists).
        if (RecurringLines::ready()) {
            $skips = $skippedRecurring[$employeeId] ?? [];
            $columns['skipped_recurring_lines'] = $skips === [] ? null : $skips;
        }

        return $columns;
    }

    /**
     * A run's member ids in position order, shaped like the reference's
     * `employee_ids` column for the validation helpers.
     *
     * @param  iterable<PayrollRunMember|PayrollRunMemberView>  $members
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
        $lock = DB::table('locks')
            ->where('name', PayrollValidation::MEMBERSHIP_LOCK)
            ->lockForUpdate()
            ->first();
        if ($lock === null) {
            throw new RuntimeException('The payroll membership lock is missing. Restore the required lock row before saving payroll.');
        }
    }
}
