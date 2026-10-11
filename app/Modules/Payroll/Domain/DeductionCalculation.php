<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * How a Payroll Deductions row is priced on a regular payslip, as
 * `payroll_deductions.calculation` spells it: the four statutory tables the
 * engine computes, the late and undertime minutes the DTR prices, or NULL —
 * an amount an officer types per employee in that row's own column of the
 * Basic Payroll grid.
 *
 * Every statutory or typed row of the list is a column of the grid; this is
 * what tells a computed column from a typed one. LATE_UNDERTIME is the one
 * tag that heads NO column of its own: the grid's Undertimes and Late
 * columns are attendance results and sit, fixed, after Other Deductions;
 * the tag keeps that row from being typed or deleted. The tag is system
 * data: migration 2026_09_30_0010 and the seeder set it, and no screen
 * offers it. A constants class rather than an enum, like ModuleCode and
 * PayrollRunStatus: the column stays a plain string and
 * `pded_calculation_chk` carries the literal list. Pure.
 *
 * The order of `columns()` is the order the grid falls back to when a
 * calculation has no list row (deleted on an install seeded without this
 * column): the engine still computes that money, so the column still
 * shows, under `defaultLabel()`.
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
     * The calculations that head a column of the grid: the four statutory
     * tables, in grid order.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return [self::SSS, self::PHILHEALTH, self::PAGIBIG, self::WITHHOLDING_TAX];
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

    /** The overridable statutory lines: the engine's key on the payslip draft. */
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
