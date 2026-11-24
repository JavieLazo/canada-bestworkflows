<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Canada;

/**
 * One pay period's income tax, with the annual figures it was worked out
 * from so a reviewer can follow the arithmetic.
 */
final class TaxResult
{
    public function __construct(
        /** Federal income tax for the period. */
        public readonly float $federal,
        /** Provincial income tax for the period. */
        public readonly float $provincial,
        /** Extra tax the employee asked for, as given. */
        public readonly float $additional,
        /** Annual taxable income the tax was worked out on. */
        public readonly float $annualTaxable,
        /** Annual federal tax before it is divided over the pay periods. */
        public readonly float $annualFederal,
        /** Annual provincial tax before it is divided over the pay periods. */
        public readonly float $annualProvincial,
    ) {}

    /** Everything deducted as income tax this period. */
    public function total(): float
    {
        return $this->federal + $this->provincial + $this->additional;
    }
}
