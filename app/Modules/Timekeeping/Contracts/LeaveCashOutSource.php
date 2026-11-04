<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Support\Access;

/**
 * The leave credit cash-outs a payroll pays: each member's APPROVED
 * requests no payroll has paid yet, as detached arrays payroll's evidence
 * can carry — the request id, the leave type's token and name, and the
 * days — so a replay never reads the live table. The pesos are payroll's:
 * the days at the member's daily rate under the rules the run captured.
 */
interface LeaveCashOutSource
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{id: string, request_type: string, name: string, days: float}>>
     */
    public function forPayroll(Access $actor, array $employeeIds): array;
}
