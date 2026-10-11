<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

/**
 * Consumer-owned port (like PostedPayrollDates): the payroll drafts a
 * schedule change can still move.
 *
 * A change never reaches a date before it takes effect, but payroll divides
 * a monthly rate by the scheduled working days of the WHOLE calendar month
 * (D7). So a change that adds or removes a working day later in a month
 * changes the day's value for that whole month in a payroll that is not
 * finalized yet. The roster shows these drafts before saving, and payroll
 * asks for a fresh preview; a finalized payroll is never touched.
 */
interface OpenPayrollDrafts
{
    /**
     * The employee's regular payroll drafts whose calendar months hold a date
     * from `$from` through `$to` (open-ended when null), oldest first.
     *
     * @return list<array{code: string, name: string, period_start: string, period_end: string}>
     */
    public function touching(string $employeeId, string $from, ?string $to = null): array;
}
