<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use DateTimeImmutable;

/**
 * Whether a basic payroll's period is a standard semi-monthly cut-off, and
 * the warning to show when it is not.
 *
 * The engine computes SSS, PhilHealth, Pag-IBIG and withholding by the
 * reference rule: half the monthly figure on every payslip, the whole month
 * on a month-ending payslip for a MONTHLY-mode employee, and the semi-monthly
 * BIR table. That rule is exact for a 1st–15th or 16th–end-of-month cut-off
 * and approximate for any other range of days, which
 * PayrollPeriod::cutoffError still accepts. So a run over any other range is
 * WARNED about, never refused: the officer checks and, where needed,
 * overtypes the statutory cells.
 *
 * Non-blocking by design. The warning must stay out of PayrollReadiness's
 * blocking issues and out of the list FinalizePayroll refuses on; it is shown
 * beside the grid and noted in the finalize audit sentence. It applies to the
 * basic (regular) payroll only.
 *
 * Pure: no clock (the month length is read from the period's own dates), no
 * database, no framework.
 */
final class CutoffAdvice
{
    public const string WARNING = 'This payroll is not a 1st–15th or 16th–end-of-month cut-off. SSS, PhilHealth and Pag-IBIG are still charged at half a month (or in full for monthly-mode employees when the period ends after the 15th), and tax uses the semi-monthly table. Check the statutory cells before finalizing.';

    private function __construct() {}

    /** Null for a standard cut-off or for dates cutoffError already refuses; otherwise the warning. */
    public static function warning(string $start, string $end): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1 || $start > $end) {
            return null;
        }

        return self::isStandardCutoff($start, $end) ? null : self::WARNING;
    }

    /** The 1st to the 15th, or the 16th to the last day, of one month. */
    public static function isStandardCutoff(string $start, string $end): bool
    {
        if (substr($start, 0, 7) !== substr($end, 0, 7)) {
            return false;
        }

        $startDay = (int) substr($start, 8, 2);
        $endDay = (int) substr($end, 8, 2);
        $lastDay = (int) (new DateTimeImmutable(substr($start, 0, 7).'-01'))->format('t');

        return ($startDay === 1 && $endDay === 15) || ($startDay === 16 && $endDay === $lastDay);
    }
}
