<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * How a Payroll Deductions row is priced, as `payroll_deductions.calculation`
 * spells it: LATE_UNDERTIME — the late and undertime minutes the DTR prices
 * — or NULL, an amount an officer types per employee in that row's own
 * column of the Basic Payroll grid (or a percentage rule works out).
 *
 * LATE_UNDERTIME heads NO column of its own: the grid's Undertime and Late
 * columns are attendance results and sit, fixed, after Other Deductions;
 * the tag keeps that row from being typed. The tag is system data: the
 * seeder sets it and no screen offers it.
 *
 * SSS, PHILHEALTH, PAGIBIG and WITHHOLDING_TAX are STORED VALUES ONLY: no
 * row of the list carries one and no payroll prices one. They stay because
 * a finalized payroll's evidence names the columns it was issued with, and
 * `pded_calculation_chk` carries the literal list. A constants class rather
 * than an enum, like ModuleCode and PayrollRunStatus: the column stays a
 * plain string. Pure.
 *
 * `all()` is the order and `defaultLabel()` the name a column takes when no
 * list row carries its calculation: a finalized run whose evidence records
 * no columns shows all five that way (`DeductionColumns::fromPosted`).
 */
final class DeductionCalculation
{
    public const SSS = 'SSS';

    public const PHILHEALTH = 'PHILHEALTH';

    public const PAGIBIG = 'PAGIBIG';

    public const WITHHOLDING_TAX = 'WITHHOLDING_TAX';

    /** The DTR-priced pair (late_deduction, undertime_deduction): a tag, never a column. */
    public const LATE_UNDERTIME = 'LATE_UNDERTIME';

    private function __construct() {}

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SSS, self::PHILHEALTH, self::PAGIBIG, self::WITHHOLDING_TAX, self::LATE_UNDERTIME];
    }

    /**
     * The given calculations in `all()` order, each once — the one spelling
     * of "which computed deductions are on" the evidence fingerprint sees.
     *
     * @param  iterable<mixed>  $calculations
     * @return list<string>
     */
    public static function order(iterable $calculations): array
    {
        $present = [];
        foreach ($calculations as $calculation) {
            if (is_string($calculation)) {
                $present[$calculation] = true;
            }
        }

        return array_values(array_filter(self::all(), static fn (string $c): bool => isset($present[$c])));
    }

    public static function isValid(string $calculation): bool
    {
        return in_array($calculation, self::all(), true);
    }

    /** The payslip column a posted contribution or withholding calculation is read from; null for Late / Undertime. */
    public static function statutoryKey(string $calculation): ?string
    {
        return match ($calculation) {
            self::SSS => 'sss',
            self::PHILHEALTH => 'philhealth',
            self::PAGIBIG => 'pagibig',
            self::WITHHOLDING_TAX => 'withholding_tax',
            default => null,
        };
    }

    /** The column's name when no list row carries the calculation. */
    public static function defaultLabel(string $calculation): string
    {
        return match ($calculation) {
            self::SSS => 'SSS',
            self::PHILHEALTH => 'PhilHealth',
            self::PAGIBIG => 'Pag-IBIG',
            self::WITHHOLDING_TAX => 'Withholding Tax',
            self::LATE_UNDERTIME => 'Late / Undertime',
            default => $calculation,
        };
    }
}
