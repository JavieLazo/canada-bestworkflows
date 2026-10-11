<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Which payroll a run is — stored as `varchar(20)` on `payroll_runs.kind`,
 * default REGULAR, with the `runs_kind_chk` CHECK beside it
 * (2026_09_26_0010). A constant class, like PayrollRunStatus, never a MySQL
 * ENUM.
 *
 * REGULAR is the cut-off payroll ("Basic Payroll"); the name carries no
 * frequency or tax table of its own. THIRTEENTH_MONTH is the yearly run
 * ("13th Month Payroll") covering 1 January to 31 December. No reference
 * counterpart: the reference has one kind of run.
 */
final class PayrollRunKind
{
    public const string REGULAR = 'REGULAR';

    public const string THIRTEENTH_MONTH = 'THIRTEENTH_MONTH';

    /** The sidebar and page labels, in menu order. */
    public const array LABELS = [
        self::REGULAR => 'Basic Payroll',
        self::THIRTEENTH_MONTH => '13th Month Payroll',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REGULAR, self::THIRTEENTH_MONTH];
    }
}
