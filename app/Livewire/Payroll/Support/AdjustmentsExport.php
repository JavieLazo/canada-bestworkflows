<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Modules\Payroll\Application\Queries\AdjustmentRecords;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\LocalClock;
use Generator;

/**
 * The adjustment ledger as CSV, filtered as on screen
 * ({@see AdjustmentRecords}): the same rows the actor sees. Export only.
 */
final class AdjustmentsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $status = in_array($query['status'] ?? '', AdjustmentRule::STATUSES, true) ? (string) $query['status'] : 'ALL';
        $search = (string) ($query['q'] ?? '');

        $rows = static function () use ($actor, $status, $search): Generator {
            foreach (app(AdjustmentRecords::class)->list($actor, $status, $search) as $row) {
                yield [
                    $row->employee_code, $row->employee_name, $row->category_name,
                    $row->kind === AdjustmentRule::KIND_DEDUCTION ? 'Deduction' : ($row->tax === AdjustmentRule::TAX_TAXABLE ? 'Earning, taxable' : 'Earning, not compensation'),
                    number_format($row->amount, 2, '.', ''), $row->reason, $row->reference ?? '', $row->status,
                    $row->payslip_run_code ?? $row->target_run_code ?? '', $row->prepared_by_name ?? '', $row->approved_by_name ?? '', $row->review_note ?? '',
                    $row->created_at->setTimezone(LocalClock::zone())->format('Y-m-d'),
                ];
            }
        };

        return new CsvExport('adjustments-'.LocalClock::todayKey().'.csv', ['Employee ID', 'Employee', 'Category', 'Kind', 'Amount', 'Reason', 'Reference', 'Status', 'Payroll', 'Prepared by', 'Reviewed by', 'Note', 'Prepared on'], $rows);
    }
}
