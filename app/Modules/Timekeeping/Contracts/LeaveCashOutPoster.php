<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Support\Access;

/**
 * Posts a finalized payslip's leave credit cash-outs: each request it paid
 * becomes PAID with the payslip and the pesos, and the days come off the
 * employee's live ledger as a CASH_OUT entry per leave type — inside the
 * finalizing transaction, under the leave-credits lock taken after
 * payroll's own. A request that is no longer approved, or already paid,
 * fails the finalization: the preview was reviewed with it.
 */
interface LeaveCashOutPoster
{
    /**
     * @param  list<array{id: string, days: float, amount: float}>  $lines  the requests the payslip paid, with the pesos each came to
     */
    public function post(Access $actor, string $employeeId, string $payslipId, string $runCode, array $lines): void;
}
