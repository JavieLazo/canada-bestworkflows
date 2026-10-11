<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Domain\Payroll\PayrollReadiness;
use App\Domain\Payroll\PayslipCalculator;
use App\Domain\Payroll\PayslipDraft;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Contracts\Views\PayrollDeductionTypeView;
use App\Modules\Payroll\Contracts\Views\PayrollRunMemberView;
use App\Modules\Payroll\Contracts\Views\PayslipView;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Access;
use App\Support\CodeSequence;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use App\Support\Money;
use Illuminate\Support\Collection;

/** Compatibility adapter; business logic belongs to \App\Modules\Payroll\Application\PayrollData. */
final class PayrollData
{
    /** The employee's computed-but-overtypable statutory lines. */
    public const array STATUTORY_KEYS = \App\Modules\Payroll\Application\PayrollData::STATUTORY_KEYS;

    /** The employer shares — reported, never deducted — in payslip order. */
    public const array EMPLOYER_LABELS = \App\Modules\Payroll\Application\PayrollData::EMPLOYER_LABELS;

    /** EARNING_FIELDS (run-workspace.tsx:61-65) — the hand-entered earnings, in grid order. */
    public const array EARNING_FIELDS = \App\Modules\Payroll\Application\PayrollData::EARNING_FIELDS;

    /** payroll-run-schema.ts `money`: z.number().finite().min(0).max(999_999_999). */
    public const float MONEY_MAX = \App\Modules\Payroll\Application\PayrollData::MONEY_MAX;

    /** Editable deduction key => payroll_run_members column. */
    public const array DEDUCTION_COLUMNS = \App\Modules\Payroll\Application\PayrollData::DEDUCTION_COLUMNS;

    /** Earning key => payroll_run_members column. */
    public const array EARNING_COLUMNS = \App\Modules\Payroll\Application\PayrollData::EARNING_COLUMNS;

    /** use-payroll-lines.ts TOTALLED_COLUMNS — the keys the total row foots. */
    public const array TOTALLED_COLUMNS = \App\Modules\Payroll\Application\PayrollData::TOTALLED_COLUMNS;

    /* ------------------------------------------------------------------ */
    /* hris.ts helpers */
    /* ------------------------------------------------------------------ */
    /** hris.ts todayKey() — today's Manila calendar day, 'YYYY-MM-DD', ManilaClock's one clock read. */
    public static function todayKey(): string
    {
        return \App\Modules\Payroll\Application\PayrollData::todayKey();
    }

    /**
     * The cut-off check before anything computes: dates, Contribution & Tax
     * Tables, then the members' Pay Basis & Rates.
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

    /** peso() (hris.ts:184-189): ₱ + en-PH grouping, always two decimals. */
    public static function peso(mixed $n): string
    {
        return \App\Modules\Payroll\Application\PayrollData::peso($n);
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
     * applyDeductionOverrides (payroll.ts:332-356): a manager-typed figure
     * replaces the computed statutory line, then the deduction total and net
     * pay are re-footed through the same normalisation finalize uses.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    public static function applyDeductionOverrides(PayslipDraft $payslip, ?array $overrides): PayslipDraft
    {
        return \App\Modules\Payroll\Application\PayrollData::applyDeductionOverrides($payslip, $overrides);
    }

    /** payrollDraftError (payroll.ts:325-330). */
    public static function draftError(PayslipDraft|Payslip|PayslipView $payslip): ?string
    {
        return \App\Modules\Payroll\Application\PayrollData::draftError($payslip);
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
        return \App\Modules\Payroll\Application\PayrollData::totals($rows);
    }

    /** A payslip's incentives and bonuses in one figure: the taxed part plus the tax-exempt part. */
    public static function incentivesTotal(object $payslip): float
    {
        return \App\Modules\Payroll\Application\PayrollData::incentivesTotal($payslip);
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
     * usePayrollCalculation's `.filter((e) => e !== undefined)` did.
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, array<string, mixed>>  $draftDeductions
     * @param  array<string, mixed>  $draftAllowances
     * @param  array<string, array<string, mixed>>  $draftEarnings
     * @param  array<string, array<string, mixed>>  $draftIncentives  employee id => [incentive type id => amount]
     * @param  array<string, array<string, mixed>>  $draftCustomDeductions  employee id => [deduction type id => amount]
     * @return array{
     *     drafts: list<PayslipDraft>,
     *     employees: Collection<int, EmployeePayData>,
     *     issues: list<array{employee_id: string, message: string}>,
     *     draftErrors: list<array{employee_id: string, message: string}>,
     *     combined: list<array{employee_id: string, message: string}>,
     *     inputs: ?PayrollInputs,
     *     statutory: array<string, array{computed: array<string, float>, overridden: list<string>, off: list<string>, withholding_rederived: bool, benefits: array{ytd_before: float, exempt: float, ceiling: float, taxable_excess: float}}>,
     *     incentiveLines: array<string, list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool, automatic: bool, over_ceiling: float}>>,
     *     customDeductionLines: array<string, list<array{type_id: string, code: string, name: string, amount: float, employer_amount: ?float, automatic: bool}>>,
     * }
     */
    public static function calculateDrafts(array $employeeIds, string $periodStart, string $periodEnd, array $draftDeductions = [], array $draftAllowances = [], array $draftEarnings = [], bool $strict = false, array $draftIncentives = [], array $draftCustomDeductions = []): array
    {
        return \App\Modules\Payroll\Application\PayrollData::calculateDrafts($employeeIds, $periodStart, $periodEnd, $draftDeductions, $draftAllowances, $draftEarnings, $strict, draftIncentives: $draftIncentives, draftCustomDeductions: $draftCustomDeductions);
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
     *     additional: array<string, float>,
     * }
     */
    public static function overridesFromMembers(iterable $members): array
    {
        return \App\Modules\Payroll\Application\PayrollData::overridesFromMembers($members);
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
    public static function memberColumns(string $runId, string $employeeId, int $position, string $periodStart, string $periodEnd, array $deductions, array $allowances, array $earnings): array
    {
        return \App\Modules\Payroll\Application\PayrollData::memberColumns($runId, $employeeId, $position, $periodStart, $periodEnd, $deductions, $allowances, $earnings);
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

    /**
     * The port of `lockPayrollMembership` — the advisory lock every payroll
     * membership write takes first. An InnoDB row lock on the `locks` sentinel
     * row, released on COMMIT and on ROLLBACK. Call INSIDE a transaction.
     */
    public static function lockMembership(): void
    {
        \App\Modules\Payroll\Application\PayrollData::lockMembership();
    }
}
