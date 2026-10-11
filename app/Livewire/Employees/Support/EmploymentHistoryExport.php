<?php

declare(strict_types=1);

namespace App\Livewire\Employees\Support;

use App\Domain\Enums\ModuleCode;
use App\Modules\Workforce\Application\Queries\EmploymentHistoryRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/** Exports one row per changed field through the history screen's scoped read. */
final class EmploymentHistoryExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->userId !== null && $actor->hasModule(ModuleCode::EMPLOYEES), 403);
        $employee = $query['employee'] ?? '';
        $from = $query['from'] ?? '';
        $to = $query['to'] ?? '';
        $field = $query['field'] ?? '';
        $history = app(EmploymentHistoryRecords::class);
        $history->validateFilters($from, $to, $field);

        $rows = static function () use ($history, $actor, $employee, $from, $to, $field): Generator {
            foreach ($history->rows($actor, $employee, $from, $to, $field) as $entry) {
                foreach ($entry->changes as $change) {
                    yield [
                        $entry->id,
                        $entry->employee_code,
                        $entry->employee_name,
                        $entry->created_at->setTimezone(ManilaClock::TZ)->format('Y-m-d H:i:s'),
                        $change['label'],
                        EmploymentChangeDisplay::value($change['field'], $change['before'], $change['before_known']),
                        EmploymentChangeDisplay::value($change['field'], $change['after'], $change['after_known']),
                        $entry->recorded_by_name ?: 'System',
                        $entry->request_id,
                        $entry->requested_by_name,
                        $entry->requested_at?->setTimezone(ManilaClock::TZ)->format('Y-m-d H:i:s'),
                        $entry->request_reason,
                        $entry->review_note,
                    ];
                }
            }
        };

        return new CsvExport('employment-history-'.ManilaClock::todayKey().'.csv', [
            'Change ID', 'Employee record ID', 'Employee', 'Changed on (Manila)',
            'Field', 'Previous value', 'New value', 'Changed by',
            'Request ID', 'Requested by', 'Requested on (Manila)', 'Request reason', 'Review note',
        ], $rows);
    }
}
