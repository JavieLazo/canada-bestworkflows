<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Organization\Application\Commands\ImportHolidays;
use App\Modules\Organization\Application\Commands\SaveHoliday;
use App\Modules\Organization\Application\Queries\HolidayRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\LocalClock;
use Generator;

/**
 * The holiday calendar as CSV, through {@see HolidayRecords::page()} with
 * the table's type filter, search and sort. Dates are `YYYY-MM-DD` and the
 * schedule and type are the labels the table prints, so the file is also
 * the import template ({@see ImportHolidays}).
 */
final class HolidaysExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $type = isset(SaveHoliday::TYPES[$query['type'] ?? '']) ? (string) $query['type'] : 'ALL';
        $search = $query['q'] ?? '';
        $sort = in_array($query['sort'] ?? '', ['id', 'name', 'date', 'type'], true) ? (string) $query['sort'] : 'date';
        $direction = ($query['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

        $rows = static function () use ($actor, $type, $search, $sort, $direction): Generator {
            $records = app(HolidayRecords::class);
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => $records->page($actor, $type, $search, $sort, $direction, $page, $perPage)) as $holiday) {
                yield [
                    $holiday->code, $holiday->name, $holiday->date->format('Y-m-d'),
                    SaveHoliday::SCHEDULES[$holiday->schedule] ?? $holiday->schedule,
                    SaveHoliday::TYPES[$holiday->type] ?? $holiday->type,
                    $holiday->percent + 0,
                ];
            }
        };

        return new CsvExport('holidays-'.LocalClock::todayKey().'.csv', ImportHolidays::COLUMNS, $rows);
    }
}
