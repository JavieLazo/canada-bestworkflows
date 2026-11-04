<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\PayslipDeduction;
use App\Support\Access;
use App\Support\Money;

/**
 * How much of each recurring deduction's total is already paid: the sum of
 * the posted payslip lines naming it (`payslip_deductions.
 * recurring_line_id`). Payslip rows exist only for FINALIZED runs, which
 * are never deleted, so a draft never counts and nothing paid is ever
 * unpaid again.
 *
 * Like ExemptBenefitLedger, a payroll MUST read it inside
 * PayrollReadTransaction after PayrollData::lockMembership() and capture the
 * figures in its evidence: two drafts proposing a loan's last payment then
 * finalize one after the other, and the second sees what the first paid
 * (its preview stales, and it proposes only what is left). Pay data: an
 * all-data PAYROLL read.
 */
final class RecurringLineLedger
{
    /**
     * @param  list<string>  $lineIds
     * @return array<string, float> line id => pesos posted against it (0.0 when none)
     */
    public function paidBefore(Access $actor, array $lineIds): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $paid = [];
        foreach ($lineIds as $id) {
            $paid[$id] = 0.0;
        }
        if ($paid === [] || ! RecurringLines::ready()) {
            return $paid;
        }

        $rows = PayslipDeduction::query()
            ->whereIn('recurring_line_id', array_keys($paid))
            ->groupBy('recurring_line_id')
            ->selectRaw('recurring_line_id, SUM(amount) AS paid_total')
            ->toBase()
            ->get();
        foreach ($rows as $row) {
            $paid[(string) $row->recurring_line_id] = Money::round($row->paid_total);
        }
        // The loan's ledger beside the payslips: approved outside payments
        // count as paid, approved balance adjustments the other way.
        foreach (RecurringLineEntries::paid(array_keys($paid)) as $lineId => $entries) {
            $paid[$lineId] = Money::round($paid[$lineId] + $entries);
        }

        return $paid;
    }
}
