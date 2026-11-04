<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Payroll\PayslipDraft;
use App\Domain\Payroll\StatutorySchedule;
use App\Modules\Payroll\Application\Queries\ThirteenthMonthBasis;
use App\Modules\Payroll\Domain\Benefits\ExemptBenefitCeiling;
use App\Modules\Payroll\Domain\Tax\SupplementaryWithholding;
use App\Modules\Payroll\Domain\ThirteenthMonth\ThirteenthMonthPay;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\Money;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The ONE compute path of a 13th month run — the yearly counterpart of
 * PayrollData::calculateDrafts, shared by the workspace preview, the CSV
 * and finalize.
 *
 * For each member, from captured facts only (ThirteenthMonthInputs):
 *
 *  1. the 13th month = (EMS basic pay in the year + the typed outside figure)
 *     / 12 — ThirteenthMonthPay;
 *  2. it is split against the PHP 90,000 yearly ceiling from the exempt
 *     benefits already posted that year — ExemptBenefitCeiling, the one rule
 *     the regular run's incentives use too;
 *  3. the excess is taxed at the marginal rate over the latest regular
 *     payslip's taxable pay — SupplementaryWithholding — unless the officer
 *     typed the withholding, which then wins and is recorded as an override;
 *  4. no SSS, PhilHealth or Pag-IBIG: a 13th month pay carries none.
 *
 * The result is an ordinary PayslipDraft covering 1 January to 31 December:
 * `thirteenth_month` = `gross_pay` = the amount, `withholding_tax` =
 * `total_deductions` = the tax, `exempt_benefits` / `taxable_benefits_excess`
 * the split (so the ledger sees it), `taxable_compensation` the excess, every
 * attendance figure 0. A member with nothing to base it on BLOCKS finalize —
 * no PHP 0 payslip is posted.
 */
final class ThirteenthMonthData
{
    /**
     * @param  list<string>  $employeeIds
     * @param  array<string, mixed>  $outsideBasicPay  employee id => typed basic pay earned outside EMS
     * @param  array<string, mixed>  $withholdingOverrides  employee id => typed withholding
     * @return array{
     *     drafts: list<PayslipDraft>,
     *     employees: Collection<int, EmployeePayData>,
     *     issues: list<array{employee_id: string, message: string}>,
     *     draftErrors: list<array{employee_id: string, message: string}>,
     *     combined: list<array{employee_id: string, message: string}>,
     *     inputs: ?ThirteenthMonthInputs,
     *     statutory: array<string, array<string, mixed>>,
     * }
     */
    public static function calculateDrafts(array $employeeIds, int $year, array $outsideBasicPay = [], array $withholdingOverrides = [], bool $strict = false, ?Access $actor = null): array
    {
        if ($employeeIds === []) {
            return ['drafts' => [], 'employees' => new Collection, 'issues' => [], 'draftErrors' => [], 'combined' => [], 'inputs' => null, 'statutory' => []];
        }

        $actor ??= app(Access::class);
        $inputs = PayrollReadTransaction::run(static function () use ($actor, $employeeIds, $year, $outsideBasicPay, $withholdingOverrides): ThirteenthMonthInputs {
            $basis = app(ThirteenthMonthBasis::class)->forEmployees($actor, $employeeIds, $year);

            $outside = [];
            $overrides = [];
            foreach ($employeeIds as $id) {
                $typed = PayrollData::typedOverride($outsideBasicPay[$id] ?? null);
                if ($typed !== null && $typed > 0) {
                    $outside[$id] = $typed;
                }
                $tax = PayrollData::typedOverride($withholdingOverrides[$id] ?? null);
                if ($tax !== null) {
                    $overrides[$id] = $tax;
                }
            }

            // The withholding table in force on 31 December, with its
            // figures: captured with the run, in the fingerprint. An
            // uncovered year never computes (the cut-off check refuses it).
            $tables = app(StatutoryTables::class)->resolveYear($year);
            if ($tables->error !== null) {
                throw new RuntimeException($tables->error);
            }

            // Each member's Contributions & tax version in force on the
            // year's last day laid over their pay context: the withholding
            // switches the evidence records for them.
            [$employees] = GovDeductionPolicyStore::overlay(app(EmployeePayContexts::class)->get($actor, $employeeIds), "{$year}-12-31");

            return new ThirteenthMonthInputs(
                $employeeIds, $year,
                $employees,
                array_map(static fn (array $row): float => $row['basic_pay'], $basis),
                array_map(static fn (array $row): ?float => $row['regular_taxable'], $basis),
                $outside, $overrides,
                app(ExemptBenefitLedger::class)->usedBefore($actor, $employeeIds, $year),
                CalculatorVersion::current(),
                [StatutorySchedule::WITHHOLDING_TAX => $tables->tables[StatutorySchedule::WITHHOLDING_TAX]],
                // The yearly tax-free ceiling in force on 31 December (TX05).
                BenefitCeilings::entry("{$year}-12-31"),
            );
        });

        return self::calculateInputs($inputs, $strict);
    }

    /**
     * Computes only the captured bundle, including replay after source records change.
     *
     * @return array{
     *     drafts: list<PayslipDraft>,
     *     employees: Collection<int, EmployeePayData>,
     *     issues: list<array{employee_id: string, message: string}>,
     *     draftErrors: list<array{employee_id: string, message: string}>,
     *     combined: list<array{employee_id: string, message: string}>,
     *     inputs: ThirteenthMonthInputs,
     *     statutory: array<string, array<string, mixed>>,
     * }
     */
    public static function calculateInputs(ThirteenthMonthInputs $inputs, bool $strict = false): array
    {
        if ($inputs->calculatorVersion !== CalculatorVersion::current()) {
            throw new RuntimeException('This payroll evidence requires its recorded calculation version.');
        }

        $employees = new Collection($inputs->employees);
        $byId = $employees->keyBy('id');
        if ($strict && $byId->count() !== count(array_unique($inputs->employeeIds))) {
            throw new RuntimeException('Payroll could not load every selected employee.');
        }

        $drafts = [];
        $issues = [];
        $draftErrors = [];
        $combined = [];
        $statutory = [];
        // The withholding figures the captured table version holds.
        $schedule = $inputs->schedule();

        foreach ($inputs->employeeIds as $employeeId) {
            /** @var EmployeePayData|null $employee */
            $employee = $byId->get($employeeId);
            if ($employee === null) {
                continue;
            }

            $ems = Money::round($inputs->basicPay[$employeeId] ?? 0.0);
            $outside = Money::round($inputs->outsideBasicPay[$employeeId] ?? 0.0);
            $amount = ThirteenthMonthPay::amount($ems, $outside);
            $ytdBefore = Money::round($inputs->exemptBenefitsYearToDate[$employeeId] ?? 0.0);
            $split = ExemptBenefitCeiling::split($ytdBefore, $amount, $inputs->ceiling());
            $regular = $inputs->regularTaxable[$employeeId] ?? null;
            $computedTax = SupplementaryWithholding::tax($employee, $regular ?? 0.0, $split->taxableExcess, $schedule);
            $typedTax = $inputs->withholdingOverrides[$employeeId] ?? null;
            $tax = $typedTax ?? $computedTax;

            $drafts[] = $draft = new PayslipDraft(
                employee_id: $employeeId,
                period_start: $inputs->periodStart(),
                period_end: $inputs->periodEnd(),
                daily_rate: 0.0,
                work_days: 0.0, holiday_days: 0.0, rest_days: 0.0, absence_days: 0.0, unpaid_leave_days: 0.0, paid_leave_days: 0.0,
                work_days_pay: 0.0, holiday_pay: 0.0, rest_day_pay: 0.0, basic_pay: 0.0, allowances: 0.0, total_base_pay: 0.0,
                overtime_hours: 0.0, overtime_pay: 0.0, overtime_acknowledged: false,
                night_differential: 0.0, leave_pay: 0.0, other_earnings: 0.0, bonus: 0.0,
                thirteenth_month: $amount,
                gross_pay: $amount,
                sss: 0.0, sss_er: 0.0, philhealth: 0.0, philhealth_er: 0.0, pagibig: 0.0, pagibig_er: 0.0,
                withholding_tax: $tax,
                other_deductions: 0.0,
                late_minutes: 0.0, late_deduction: 0.0, undertime_minutes: 0.0, undertime_deduction: 0.0,
                total_deductions: $tax,
                net_pay: Money::round($amount - $tax),
                exempt_benefits: $split->exempt,
                taxable_benefits_excess: $split->taxableExcess,
                taxable_compensation: $split->taxableExcess,
                ceiling_benefits: $split->exempt,
            );

            // Nothing to base it on: finalizing would post a PHP 0 payslip.
            if ($amount <= 0.0) {
                $row = ['employee_id' => $employeeId, 'message' => sprintf(
                    '%s earned no basic pay on a finalized %d payroll. Type what they earned outside EMS, or take them off this 13th month run.',
                    EmployeeName::display($employee), $inputs->year,
                )];
                $issues[] = $row;
                $combined[] = $row;
            }

            $error = PayrollData::draftError($draft);
            if ($error !== null) {
                $row = ['employee_id' => $employeeId, 'message' => $error];
                $draftErrors[] = $row;
                $combined[] = $row;
            }

            $statutory[$employeeId] = [
                'computed' => ['withholding_tax' => $computedTax],
                'overridden' => $typedTax !== null ? ['withholding_tax'] : [],
                'withholding_rederived' => false,
                'benefits' => ['ytd_before' => $ytdBefore, 'exempt' => $split->exempt, 'taxable_excess' => $split->taxableExcess],
                'basis' => ['ems' => $ems, 'outside' => $outside, 'total' => ThirteenthMonthPay::basis($ems, $outside), 'regular_taxable' => $regular],
            ];
        }

        return ['drafts' => $drafts, 'employees' => $employees, 'issues' => $issues, 'draftErrors' => $draftErrors, 'combined' => $combined, 'inputs' => $inputs, 'statutory' => $statutory];
    }
}
