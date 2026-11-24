<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Domain\Payroll\PayrollReadiness;
use App\Domain\Payroll\PayslipCalculator;
use App\Domain\Payroll\PayslipDraft;
use App\Models\PayrollRunMember;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Contracts\Views\PayrollDeductionTypeView;
use App\Modules\Payroll\Contracts\Views\PayrollRunMemberView;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Access;
use App\Support\CodeSequence;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use App\Support\Money;
use Illuminate\Support\Collection;

/** Compatibility adapter; business logic belongs to \App\Modules\Payroll\Application\PayrollData. */
final class PayrollData
{
    /**
     * EARNING_FIELDS (run-workspace.tsx:61-65) — the hand-entered earnings, in
     * grid order, under the names the grid heads them with. The keys are the
     * module's; the labels are kept here because the module's copy sits in a
     * file the calculation version fingerprints, which a wording change must
     * not move.
     */
    public const array EARNING_FIELDS = [
        'night_differential' => 'NSD',
        'leave_pay' => 'Leave pay',
        'other_earnings' => 'Other earnings',
    ];

    /** payroll-run-schema.ts `money`: z.number().finite().min(0).max(999_999_999). */
    public const float MONEY_MAX = \App\Modules\Payroll\Application\PayrollData::MONEY_MAX;

    /** Typed deduction key => payroll_run_members column. */
    public const array DEDUCTION_COLUMNS = \App\Modules\Payroll\Application\PayrollData::DEDUCTION_COLUMNS;

    /** Earning key => payroll_run_members column. */
    public const array EARNING_COLUMNS = \App\Modules\Payroll\Application\PayrollData::EARNING_COLUMNS;

    /** use-payroll-lines.ts TOTALLED_COLUMNS — the keys the total row foots. */
    public const array TOTALLED_COLUMNS = \App\Modules\Payroll\Application\PayrollData::TOTALLED_COLUMNS;

    /* ------------------------------------------------------------------ */
    /* hris.ts helpers */
    /* ------------------------------------------------------------------ */
    /** hris.ts todayKey() — today's local calendar day, 'YYYY-MM-DD', LocalClock's one clock read. */
    public static function todayKey(): string
    {
        return \App\Modules\Payroll\Application\PayrollData::todayKey();
    }

    /**
     * The cut-off check before anything computes: the dates, then the
     * members' Pay Basis & Rates.
     *
     * @param  list<string>  $memberIds
     */
    public static function cutoffError(Access $actor, array $memberIds, string $periodStart, string $periodEnd): ?string
    {
        return \App\Modules\Payroll\Application\PayrollData::cutoffError($actor, $memberIds, $periodStart, $periodEnd);
    }

    /**
     * The catalog types payroll proposes on its own for one member on a period.
     *
     * @return list<string>
     */
    public static function scheduledBenefitTypes(Access $actor, string $employeeId, string $periodStart, string $periodEnd): array
    {
        return \App\Modules\Payroll\Application\PayrollData::scheduledBenefitTypes($actor, $employeeId, $periodStart, $periodEnd);
    }

    /** `$1,234.50`, always two decimals: the one figure format, Currency::format(). */
    public static function money(mixed $n): string
    {
        return \App\Modules\Payroll\Application\PayrollData::money($n);
    }

    /**
     * fmtDate (hris.ts:209-214) — `Aug 05, 2026` from a 'Y-m-d' key or a
     * Carbon, shared through HrisFormat. The day is zero-padded, as the
     * reference's `day: "2-digit"` and every other module's copy pad it.
     * Anything that is not a day echoes back.
     */
    public static function fmtDate(mixed $d): string
    {
        return \App\Modules\Payroll\Application\PayrollData::fmtDate($d);
    }

    /** employeeName (hris.ts:425-427). */
    public static function employeeName(?object $e): string
    {
        return \App\Modules\Payroll\Application\PayrollData::employeeName($e);
    }

    /** initials() (run-workspace.tsx:44-45). */
    public static function initials(?object $e): string
    {
        return \App\Modules\Payroll\Application\PayrollData::initials($e);
    }

    /** compareText (useSort.ts:33-40): blanks last in an ascending pass. */
    public static function compareText(?string $a, ?string $b): int
    {
        return \App\Modules\Payroll\Application\PayrollData::compareText($a, $b);
    }

    /**
     * The next id past the highest in use, through the one mint in
     * CodeSequence: a bare number. Read the codes under lock and insert in
     * the same transaction. Deletes are hard, so the highest id CAN be
     * handed out again; the unique index is the backstop.
     *
     * `$legacyPrefix` is the prefix an unconverted code in this column
     * carries — the mint reads it too, so a table the conversion has not
     * reached yet mints above its unconverted rows.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    public static function nextCode(string $legacyPrefix, iterable $codes): string
    {
        return \App\Modules\Payroll\Application\PayrollData::nextCode($legacyPrefix, $codes);
    }

    /** dayCount() — whole days, unless a half-day leave landed in the count. */
    public static function dayCount(mixed $value): string
    {
        return \App\Modules\Payroll\Application\PayrollData::dayCount($value);
    }

    /* ------------------------------------------------------------------ */
    /* Draft arithmetic — thin wrappers over the domain layer */
    /* ------------------------------------------------------------------ */
    /**
     * payrollTotals (use-payroll-lines.ts): a sum over the same keys the body
     * cells read. Works on PayslipDraft DTOs and posted Payslip rows alike.
     *
     * @param  iterable<object>  $rows
     * @return array<string, float>
     */
    public static function totals(iterable $rows): array
    {
        return \App\Modules\Payroll\Application\PayrollData::totals($rows);
    }

    /** A payslip's incentives and bonuses in one figure: the taxed part plus the tax-exempt part. */
    public static function incentivesTotal(object $payslip): float
    {
        return \App\Modules\Payroll\Application\PayrollData::incentivesTotal($payslip);
    }

    /** @return list<string> */
    public static function recurringMembers(PayrollInputs $inputs): array
    {
        return \App\Modules\Payroll\Application\PayrollData::recurringMembers($inputs);
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
     * @param  array<string, array<string, mixed>>  $draftIncentives  employee id => [incentive type id => amount]
     * @param  array<string, array<string, mixed>>  $draftCustomDeductions  employee id => [deduction type id => amount]
     * @param  array<string, list<string>>  $draftSkippedRecurring  employee id => the recurring lines skipped on this payroll
     * @param  string|null  $attendanceThrough  the advance payroll's "attendance counted through" date (PayrollAdvance)
     * @return array{
     *     drafts: list<PayslipDraft>,
     *     employees: Collection<int, EmployeePayData>,
     *     issues: list<array{employee_id: string, message: string}>,
     *     draftErrors: list<array{employee_id: string, message: string}>,
     *     combined: list<array{employee_id: string, message: string}>,
     *     inputs: ?PayrollInputs,
     *     statutory: array<string, array{off: list<string>, benefits: array{ytd_before: float, exempt: float, ceiling: float, taxable_excess: float}, source_deductions: array<string, float>|null}>,
     *     incentiveLines: array<string, list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, recurring_line_id: ?string, over_ceiling: float, adjustment_id?: string}>>,
     *     customDeductionLines: array<string, list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool, recurring_line_id: ?string, adjustment_id?: string}>>,
     *     settlementLines: array<string, list<array{payslip_id: string, run_code: string, from: string, to: string, presumed: array<string, float>, actual: array<string, float>, presumed_amount: float, actual_amount: float, amount: float}>>,
     *     leaveChargeLines: array<string, array{lines: list<array{date: string, kind: string, minutes: ?int, days: float, covered: array<string, float>, uncovered_days: float, fraction: float}>, balances: array<string, float>, notes: list<string>}>,
     *     presumedLines: array<string, array<string, float>>,
     *     countedThrough: array<string, ?string>,
     * }
     */
    public static function calculateDrafts(array $employeeIds, string $periodStart, string $periodEnd, array $draftDeductions = [], array $draftAllowances = [], array $draftEarnings = [], bool $strict = false, array $draftIncentives = [], array $draftCustomDeductions = [], array $draftSkippedRecurring = [], ?string $attendanceThrough = null, ?string $payDate = null, ?string $runId = null): array
    {
        return \App\Modules\Payroll\Application\PayrollData::calculateDrafts($employeeIds, $periodStart, $periodEnd, $draftDeductions, $draftAllowances, $draftEarnings, $strict, draftIncentives: $draftIncentives, draftCustomDeductions: $draftCustomDeductions, draftSkippedRecurring: $draftSkippedRecurring, attendanceThrough: $attendanceThrough, payDate: $payDate, runId: $runId);
    }

    /** @return Collection<int, PayrollDeductionTypeView> */
    public static function customDeductionTypes(Access $actor): Collection
    {
        return \App\Modules\Payroll\Application\PayrollData::customDeductionTypes($actor);
    }

    /**
     * The draft grid's Deductions columns: one per Payroll Deductions row,
     * code order.
     *
     * @return Collection<int, DeductionColumnView>
     */
    public static function deductionColumns(Access $actor): Collection
    {
        return \App\Modules\Payroll\Application\PayrollData::deductionColumns($actor);
    }

    /**
     * @param  array<string, list<mixed>>  $customDeductionLines
     * @return array<string, array<string, float>>
     */
    public static function typedDeductionAmounts(array $customDeductionLines): array
    {
        return \App\Modules\Payroll\Application\PayrollData::typedDeductionAmounts($customDeductionLines);
    }

    /**
     * @param  Collection<int, DeductionColumnView>  $columns
     * @param  array<string, float>  $totals
     * @param  array<string, array<string, float>>  $typedAmounts
     * @return array<string, float>
     */
    public static function deductionTotals(Collection $columns, array $totals, array $typedAmounts): array
    {
        return \App\Modules\Payroll\Application\PayrollData::deductionTotals($columns, $totals, $typedAmounts);
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
     * @param  iterable<PayrollRunMember|PayrollRunMemberView>  $members
     * @return array{
     *     deductions: array<string, array<string, float>>,
     *     allowances: array<string, float>,
     *     earnings: array<string, array<string, mixed>>,
     *     incentives: array<string, array<string, float>>,
     *     customDeductions: array<string, array<string, float>>,
     *     skippedRecurring: array<string, list<string>>,
     * }
     */
    public static function overridesFromMembers(iterable $members): array
    {
        return \App\Modules\Payroll\Application\PayrollData::overridesFromMembers($members);
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
        return \App\Modules\Payroll\Application\PayrollData::memberIds($members);
    }
}
