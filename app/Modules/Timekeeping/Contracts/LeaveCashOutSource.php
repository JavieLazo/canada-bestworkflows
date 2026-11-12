<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Support\Access;

/**
 * The leave credit cash-outs a payroll pays: each member's APPROVED
 * requests no payroll has paid yet, as detached arrays payroll's evidence
 * can carry — the request id, the leave type's token and name, the days
 * and what it is for: MONETIZATION (an employee's own request; a request
 * made before purposes were kept reads as one) or TERMINAL (the balance
 * paid out on separation) — so a replay never reads the live table. The
 * pesos are payroll's: the days at the value the paying Incentives &
 * Bonuses type sets, a terminal one from the highest salary on record.
 */
interface LeaveCashOutSource
{
    /** An employee's own request. */
    public const string MONETIZATION = 'MONETIZATION';

    /** The balance paid out on separation. */
    public const string TERMINAL = 'TERMINAL';

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{id: string, request_type: string, name: string, days: float, purpose: string}>>
     */
    public function forPayroll(Access $actor, array $employeeIds): array;
}
