<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Canada;

/**
 * One employee's source deductions for one regular pay: the pension plan
 * contribution and second contribution, the insurance premium, and federal
 * and provincial income tax, with what the employer pays on top.
 *
 * It joins the calculator's steps for a payslip: the year's totals so far
 * stop each contribution at its yearly maximum, an employee the plan or the
 * insurance does not apply to pays none of it, and the extra tax an employee
 * asked for is added to the federal line.
 *
 * Pure: the rates, the employee's details and the year's totals are handed
 * in, so a payroll replays from what it captured.
 */
final class PayDeductions
{
    /** Every figure a pay carries, all zero: what a pay with nothing to deduct reads as. */
    public const NONE = [
        'cpp' => 0.0, 'cpp2' => 0.0, 'ei' => 0.0, 'federal_tax' => 0.0, 'provincial_tax' => 0.0,
        'cpp_er' => 0.0, 'cpp2_er' => 0.0, 'ei_er' => 0.0,
        'pensionable_earnings' => 0.0, 'insurable_earnings' => 0.0,
    ];

    /**
     * @param  int  $periods  pay dates in the year the pay counts in
     * @param  float  $pay  gross taxable pay for the period; also what the pension plan and the insurance count
     * @param  float  $beforeTax  the period's deductions that come off income before tax (registered plan contributions)
     * @param  array{pensionable: float, pension: float, second_pension: float, insurance: float}  $toDate  the year's totals before this pay
     * @return array{cpp: float, cpp2: float, ei: float, federal_tax: float, provincial_tax: float, cpp_er: float, cpp2_er: float, ei_er: float, pensionable_earnings: float, insurable_earnings: float}
     */
    public static function forPay(DeductionRates $rates, TaxDetails $details, int $periods, float $pay, float $beforeTax, array $toDate): array
    {
        if ($pay <= 0.0) {
            return self::NONE;
        }

        $deductions = new SourceDeductions($rates);

        $pensionable = $details->deductPension ? $pay : 0.0;
        $insurable = $details->deductInsurance ? $pay : 0.0;

        $pension = $details->deductPension ? $deductions->pension($pensionable, $periods, $toDate['pension']) : 0.0;
        $secondPension = $details->deductPension ? $deductions->secondPension($pensionable, $toDate['pensionable'], $toDate['second_pension']) : 0.0;
        $insurance = $details->deductInsurance ? $deductions->insurance($insurable, $toDate['insurance']) : 0.0;

        $tax = $deductions->incomeTax(new TaxInputs(
            periods: $periods,
            gross: $pay,
            pension: $pension,
            secondPension: $secondPension,
            insurance: $insurance,
            province: $details->province,
            federalClaim: $details->federalClaim,
            provincialClaim: $details->provincialClaim,
            registeredPlan: max(0.0, $beforeTax),
            additionalTax: $details->additionalTax,
            pensionToDate: $toDate['pension'],
            insuranceToDate: $toDate['insurance'],
        ));

        return [
            'cpp' => $pension,
            'cpp2' => $secondPension,
            'ei' => $insurance,
            // The extra tax an employee asks for is deducted with the federal tax.
            'federal_tax' => $tax->federal + $tax->additional,
            'provincial_tax' => $tax->provincial,
            // The employer matches each pension contribution and pays its own multiple of the premium.
            'cpp_er' => $pension,
            'cpp2_er' => $secondPension,
            'ei_er' => $deductions->employerInsurance($insurance),
            'pensionable_earnings' => $pensionable,
            'insurable_earnings' => $insurable,
        ];
    }

    /**
     * The five figures deducted from the employee, added up.
     *
     * @param  array<string, float>  $lines
     */
    public static function deducted(array $lines): float
    {
        return ($lines['cpp'] ?? 0.0) + ($lines['cpp2'] ?? 0.0) + ($lines['ei'] ?? 0.0) + ($lines['federal_tax'] ?? 0.0) + ($lines['provincial_tax'] ?? 0.0);
    }
}
