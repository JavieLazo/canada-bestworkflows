<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Benefits;

/**
 * One exempt amount after the PHP 90,000 ceiling has been applied to it.
 *
 * `exempt` stays out of taxable compensation, `taxableExcess` joins it, and
 * the two always add back up to the amount that was split. `usedAfter` is
 * the year's exempt total once this amount is posted, which is where the
 * next split starts. Why the ceiling exists: see ExemptBenefitCeiling.
 */
final readonly class ExemptBenefitSplit
{
    public function __construct(
        public float $exempt,
        public float $taxableExcess,
        public float $usedAfter,
    ) {}
}
