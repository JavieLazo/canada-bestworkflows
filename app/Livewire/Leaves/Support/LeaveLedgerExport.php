<?php

declare(strict_types=1);

namespace App\Livewire\Leaves\Support;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Leaves\LeaveCredits;
use App\Modules\Timekeeping\Application\Commands\RecordLeaveCredit;
use App\Modules\Timekeeping\Application\Queries\LeaveLedgerRecords;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Generator;

/** Exports the same scoped ledger balances and filtered credit history shown in Leave Management. */
final class LeaveLedgerExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->hasModule(ModuleCode::LEAVES), 403);
        $employeeId = $query['employee'] ?? '';
        $year = (int) ($query['year'] ?? LeaveCredits::currentYear());
        if ($year < LeaveCreditPolicy::YEAR_MIN || $year > LeaveCreditPolicy::YEAR_MAX) {
            $year = LeaveCredits::currentYear();
        }
        $type = $query['type'] ?? 'ALL';
        $summary = ($query['section'] ?? '') === 'balances';
        $visible = $actor->mayReadAllData(ModuleCode::LEAVES)
            || ($actor->hasEmployeeLink() && $employeeId === $actor->ownEmployeeId());
        $employee = $visible ? app(OperationalDirectory::class)->find($actor, $employeeId) : null;
        $identity = [$employee->employee_code ?? '', $employee === null ? '' : EmployeeName::display($employee)];
        $rows = static function () use ($actor, $employeeId, $year, $type, $summary, $identity): Generator {
            $records = app(LeaveLedgerRecords::class);
            if ($summary) {
                foreach ($records->summary($actor, $employeeId, $year) as $balance) {
                    yield [...$identity, $balance->label, $year, $balance->opening, $balance->accrued, $balance->carried,
                        $balance->adjustments, $balance->paid_taken, $balance->unpaid_taken,
                        $balance->closing ?? '', $balance->booked_paid, $balance->booked_unpaid, $balance->available ?? ''];
                }

                return;
            }
            foreach ($records->entries($actor, $employeeId, $year, $type) as $entry) {
                yield [...$identity, $entry->id, $entry->recorded_at->setTimezone(LocalClock::zone())->format('Y-m-d H:i:s'),
                    $year, $entry->label, RecordLeaveCredit::LABELS[$entry->kind] ?? $entry->kind,
                    $entry->days, $entry->source_year ?? '', $entry->note];
            }
        };
        $headers = $summary
            ? ['Leave type', 'Year', 'Opening balance', 'Manual accrual', 'Carried forward', 'Adjustments',
                'Credits used', 'Unpaid leave days', 'Closing balance', 'Booked paid leave', 'Booked unpaid leave', 'Available balance']
            : ['Entry ID', 'Recorded (local time)', 'Year', 'Leave type', 'Entry', 'Days', 'Source year', 'Note'];

        return new CsvExport('leave-ledger-'.($summary ? 'balances-' : 'history-').$year.'-'.LocalClock::todayKey().'.csv', ['Employee ID', 'Employee', ...$headers], $rows);
    }
}
