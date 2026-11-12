<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Domain\Enums\PayrollRunKind;
use App\Modules\Payroll\Application\Queries\PayrollRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/**
 * One kind of payroll run list as CSV — Basic Payroll or the 13th month,
 * decided by the route (`kind` default), never the link — filtered and
 * sorted as on screen ({@see PayrollRunRows}). All-data + PAYROLL reads the
 * runs; anyone else exports the empty list they see. Export only: runs are
 * created in their workspace, and a finalized run is evidence.
 */
final class PayrollRunsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $kind = in_array($query['kind'] ?? '', PayrollRunKind::all(), true) ? (string) $query['kind'] : PayrollRunKind::REGULAR;
        $search = $query['q'] ?? '';
        $status = in_array($query['status'] ?? '', ['DRAFT', 'FINALIZED'], true) ? (string) $query['status'] : 'ALL';
        $sort = in_array($query['sort'] ?? '', ['id', 'name', 'from', 'to', 'employees', 'status'], true) ? (string) $query['sort'] : 'from';
        $direction = ($query['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $rows = static function () use ($actor, $kind, $search, $status, $sort, $direction): Generator {
            $runs = app(PayrollRecords::class)->list($actor, $kind);
            foreach (PayrollRunRows::arrange($runs, $search, $status, $sort, $direction) as $run) {
                yield [$run->code, $run->name, $run->period_start->format('Y-m-d'), $run->period_end->format('Y-m-d'), $run->members_count, $run->status];
            }
        };

        $slug = $kind === PayrollRunKind::THIRTEENTH_MONTH ? 'thirteenth-month-payrolls' : 'payrolls';

        return new CsvExport($slug.'-'.ManilaClock::todayKey().'.csv', ['ID', 'Payroll name', 'Period from', 'Period to', 'No. of employees', 'Status'], $rows);
    }
}
