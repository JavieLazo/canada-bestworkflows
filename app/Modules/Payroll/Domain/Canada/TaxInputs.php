<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Canada;

/**
 * What one regular pay period's income tax is worked out from.
 *
 * Amounts are for the pay period unless a field says "annual". The claim
 * amounts are the totals an employee reports on the federal and provincial
 * personal tax credits returns; null means no return was filed, which the
 * formulas read as the basic personal amount alone.
 *
 * Bonuses, retroactive pay and other one-time payments are not regular pay:
 * the agency taxes them by a separate method that is not handled here.
 */
final class TaxInputs
{
    public function __construct(
        /** Pay periods in the year: 52 weekly, 26 biweekly, 24 semi-monthly, 12 monthly. */
        public readonly int $periods,
        /** Gross taxable pay for the period, taxable benefits included. */
        public readonly float $gross,
        /** The period's pension plan contribution, base and first additional together. */
        public readonly float $pension,
        /** The period's second additional pension plan contribution. */
        public readonly float $secondPension,
        /** The period's employment insurance premium. */
        public readonly float $insurance,
        /** The province or territory of employment, as its two-letter code. */
        public readonly string $province,
        /** Total claim amount on the federal return, or null when none was filed. */
        public readonly ?float $federalClaim = null,
        /** Total claim amount on the provincial return, or null when none was filed. */
        public readonly ?float $provincialClaim = null,
        /** The period's contributions to a registered pension or retirement savings plan, deducted at source. */
        public readonly float $registeredPlan = 0.0,
        /** The period's union dues. */
        public readonly float $unionDues = 0.0,
        /** The period's court-ordered support payment deducted at source. */
        public readonly float $supportPayment = 0.0,
        /** Annual deductions the tax office has authorized for this employee. */
        public readonly float $annualDeductions = 0.0,
        /** Annual deduction for living in a prescribed zone. */
        public readonly float $zoneDeduction = 0.0,
        /** Other annual federal tax credits the tax office has authorized. */
        public readonly float $otherFederalCredits = 0.0,
        /** Other annual provincial tax credits the tax office has authorized. */
        public readonly float $otherProvincialCredits = 0.0,
        /** Extra tax the employee asked to have deducted each period. */
        public readonly float $additionalTax = 0.0,
        /** Pension plan contributions already deducted this year, before this period. */
        public readonly float $pensionToDate = 0.0,
        /** Employment insurance premiums already deducted this year, before this period. */
        public readonly float $insuranceToDate = 0.0,
        /** Months of the year the pension plan applies to this employee. */
        public readonly int $pensionMonths = 12,
    ) {}
}
