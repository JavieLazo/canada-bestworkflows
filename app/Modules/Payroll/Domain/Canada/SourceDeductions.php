<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Canada;

use App\Support\Money;
use InvalidArgumentException;

/**
 * The deductions an employer in Canada takes at source from regular pay:
 * Canada Pension Plan contributions, Employment Insurance premiums, and
 * federal and provincial income tax.
 *
 * Each method follows the Canada Revenue Agency's payroll deductions
 * formulas for computer programs, the general tax formula with its
 * year-to-date maximums. The agency's letters are kept beside each step so a
 * line here can be laid against the guide: C and C2 are the pension
 * contributions, EI the premium, A the annual taxable income, T3 and T4 the
 * basic federal and provincial tax.
 *
 * The figures come from a DeductionRates set; nothing here knows a year.
 * Rounding is to the cent, a half going up, at the points the guide rounds.
 */
final class SourceDeductions
{
    public function __construct(private readonly DeductionRates $rates) {}

    /**
     * The part of each period's pensionable pay no contribution is taken on:
     * the annual exemption over the pay periods, its third decimal dropped,
     * never rounded up.
     */
    public function pensionExemption(int $periods): float
    {
        self::requirePeriods($periods);

        return floor(($this->rates->pension['exemption'] / $periods) * 100 + 1e-9) / 100;
    }

    /**
     * C: the period's pension plan contribution, base and first additional
     * together. The lesser of what is left of the year's maximum and the rate
     * on the pay above the exemption.
     *
     * @param  float  $paidToDate  contributions already deducted this year by this employer
     * @param  int  $months  months of the year the plan applies to the employee
     */
    public function pension(float $pensionable, int $periods, float $paidToDate = 0.0, int $months = 12): float
    {
        $pension = $this->rates->pension;
        $left = $pension['max_contribution'] * ($months / 12) - $paidToDate;
        $onPay = $pension['rate'] * ($pensionable - $this->pensionExemption($periods));

        return max(0.0, self::cents(min($left, $onPay)));
    }

    /**
     * C2: the second additional contribution, taken only on pensionable pay
     * above the year's first ceiling, up to its own maximum.
     *
     * @param  float  $pensionableToDate  pensionable pay this year before this period
     * @param  float  $paidToDate  second contributions already deducted this year by this employer
     */
    public function secondPension(float $pensionable, float $pensionableToDate, float $paidToDate = 0.0, int $months = 12): float
    {
        $pension = $this->rates->pension;
        $left = $pension['max_second_contribution'] * ($months / 12) - $paidToDate;
        // W: the point above which this period's pay counts.
        $from = max($pensionableToDate, $pension['ceiling'] * ($months / 12));
        $onPay = ($pensionableToDate + $pensionable - $from) * $pension['second_rate'];

        return max(0.0, self::cents(min($left, $onPay)));
    }

    /**
     * EI: the period's employment insurance premium, the lesser of what is
     * left of the year's maximum and the rate on the insurable pay.
     *
     * @param  float  $paidToDate  premiums already deducted this year by this employer
     */
    public function insurance(float $insurable, float $paidToDate = 0.0): float
    {
        $insurance = $this->rates->insurance;

        return max(0.0, self::cents(min($insurance['max_premium'] - $paidToDate, $insurance['rate'] * $insurable)));
    }

    /** The employer's own premium on an employee's premium. It is paid, never deducted. */
    public function employerInsurance(float $employeePremium): float
    {
        return self::cents($employeePremium * $this->rates->insurance['employer_multiple']);
    }

    /**
     * The federal basic personal amount for an annual net income: the full
     * amount up to the fourth tax bracket, reduced in a straight line across
     * it, and the lower amount from the top bracket on.
     */
    public function federalBasicAmount(float $netIncome): float
    {
        $basic = $this->rates->federal['basic_amount'];

        if ($netIncome <= $basic['reduce_from']) {
            return $basic['max'];
        }
        if ($netIncome >= $basic['reduce_to']) {
            return $basic['min'];
        }

        return self::cents($basic['max'] - ($netIncome - $basic['reduce_from']) * (($basic['max'] - $basic['min']) / ($basic['reduce_to'] - $basic['reduce_from'])));
    }

    /** Federal and provincial income tax for one regular pay period. */
    public function incomeTax(TaxInputs $in): TaxResult
    {
        self::requirePeriods($in->periods);
        $pension = $this->rates->pension;
        $federal = $this->rates->federal;
        $province = $this->rates->province($in->province);
        $periods = $in->periods;
        $share = $in->pensionMonths / 12;

        // F5: the additional pension contributions are a deduction from
        // income; only the base contribution earns a tax credit (K2).
        $additional = $in->pension === 0.0 && $in->secondPension === 0.0
            ? 0.0
            : self::cents($in->pension * (($pension['rate'] - $pension['base_rate']) / $pension['rate']) + $in->secondPension);

        // A: annual taxable income.
        $taxable = $periods * ($in->gross - $in->registeredPlan - $in->supportPayment - $additional - $in->unionDues)
            - $in->zoneDeduction - $in->annualDeductions;

        if ($taxable < 0.0) {
            return new TaxResult(0.0, 0.0, $in->additionalTax, 0.0, 0.0, 0.0);
        }

        // The base pension contribution and the premium for the year, each
        // held to its maximum. An employee who has reached a maximum keeps
        // the whole credit for the rest of the year.
        $maxBase = $pension['max_base_contribution'] * $share;
        $pensionMaxed = $in->pensionToDate + $in->pension >= $pension['max_contribution'] * $share - 0.005;
        $pensionCredit = $pensionMaxed ? $maxBase : min($periods * $in->pension * ($pension['base_rate'] / $pension['rate']), $maxBase);
        $maxPremium = $this->rates->insurance['max_premium'];
        $insuranceMaxed = $in->insuranceToDate + $in->insurance >= $maxPremium - 0.005;
        $insuranceCredit = $insuranceMaxed ? $maxPremium : min($periods * $in->insurance, $maxPremium);

        // T3, then T1: federal tax.
        [$rate, $constant] = self::bracket($federal['brackets'], $taxable);
        $lowest = $federal['lowest_rate'];
        $claim = $in->federalClaim ?? $this->federalBasicAmount($taxable + $in->zoneDeduction);
        $k1 = self::cents($lowest * $claim);
        $k2 = self::cents($lowest * $pensionCredit + $lowest * $insuranceCredit);
        // K4: the employment amount, on gross employment income for the year.
        $k4 = self::cents(min($lowest * ($periods * $in->gross), $lowest * $federal['employment_amount']));
        $annualFederal = max(0.0, self::cents($rate * $taxable - $constant - $k1 - $k2 - $in->otherFederalCredits - $k4));

        // T4, then T2: provincial tax.
        [$provincialRate, $provincialConstant] = self::bracket($province['brackets'], $taxable);
        $provincialLowest = $province['lowest_rate'];
        $k1p = self::cents($provincialLowest * ($in->provincialClaim ?? $province['basic_amount']));
        $k2p = self::cents($provincialLowest * $pensionCredit + $provincialLowest * $insuranceCredit);
        $supplemental = $province['supplemental_credit'];
        // K5P: a further credit on the part of the credits above a set amount.
        $k5p = $supplemental === null ? 0.0 : max(0.0, self::cents(($k1p + $k2p - $supplemental['over']) * $supplemental['rate']));
        $annualProvincial = max(0.0, self::cents($provincialRate * $taxable - $provincialConstant - $k1p - $k2p - $in->otherProvincialCredits - $k5p));

        return new TaxResult(
            self::cents($annualFederal / $periods),
            self::cents($annualProvincial / $periods),
            $in->additionalTax,
            self::cents($taxable),
            $annualFederal,
            $annualProvincial,
        );
    }

    /**
     * The rate and constant of the bracket an income falls in.
     *
     * @param  list<array{float, float, float}>  $brackets
     * @return array{float, float}
     */
    private static function bracket(array $brackets, float $income): array
    {
        $found = [$brackets[0][1], $brackets[0][2]];
        foreach ($brackets as [$from, $rate, $constant]) {
            if ($income >= $from) {
                $found = [$rate, $constant];
            }
        }

        return $found;
    }

    /**
     * To the cent, a half going up, as the guide rounds. The agency's
     * arithmetic is decimal, so a product that is a half cent on paper
     * (2,150.00 at 1.63% is 35.045) must round up even though its float
     * sits a hair below the half.
     */
    private static function cents(float $amount): float
    {
        return Money::roundTo($amount, 2);
    }

    private static function requirePeriods(int $periods): void
    {
        if ($periods < 1) {
            throw new InvalidArgumentException('A year has at least one pay period.');
        }
    }
}
