<?php

declare(strict_types=1);

namespace App\Livewire\Leaves\Support;

use App\Livewire\Leaves\LeaveCredits;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\EmployeeName;
use Generator;

/**
 * The Leave Credits tab as CSV as of one day: the employees the tab lists
 * (the directory's scope, search and order), and per creditable type the
 * credits on the books that day beside the balance the table shows, as
 * credit figures at three decimals. A type with no
 * credit is blank, where the table prints an em dash. Export only: Leave
 * Credits has no import, so nothing reads the file back; credits are set
 * through the tab's own dialog.
 */
final class LeaveCreditsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        // The day the tab is showing: the books as they stood on it.
        $asOf = LeaveCredits::asOfDate($query['asof'] ?? '');
        $search = trim($query['q'] ?? '');
        $descending = ($query['dir'] ?? '') === 'desc';
        $types = app(LeaveCreditRecords::class)->creditableTypes($actor);

        $headers = ['ID', 'Employee', 'As of'];
        foreach ($types as $name) {
            $headers[] = $name;
            $headers[] = $name.' available balance';
        }

        $rows = static function () use ($actor, $asOf, $search, $descending, $types): Generator {
            $employees = app(OperationalDirectory::class)->list($actor, null, $search)->values();
            if ($descending) {
                $employees = $employees->reverse()->values();
            }
            foreach ($employees->chunk(100) as $batch) {
                $balances = app(LeaveCreditRecords::class)->balancesAsOf($actor, $asOf, array_values($batch->map(static fn ($e): string => $e->id)->all()));
                foreach ($batch as $employee) {
                    $byKey = [];
                    foreach ($balances[$employee->id] ?? [] as $balance) {
                        $byKey[$balance->request_type] = $balance;
                    }
                    $cells = [$employee->employee_code, EmployeeName::display($employee), $asOf];
                    foreach (array_keys($types) as $key) {
                        $balance = $byKey[$key] ?? null;
                        $entered = $balance !== null && $balance->entered() && $balance->credited !== null;
                        $cells[] = $entered ? LeaveQuantity::credits($balance->credited) : '';
                        $cells[] = $entered && $balance->remaining !== null ? LeaveQuantity::credits($balance->remaining) : '';
                    }
                    yield $cells;
                }
            }
        };

        return new CsvExport('leave-credits-as-of-'.$asOf.'.csv', $headers, $rows);
    }
}
