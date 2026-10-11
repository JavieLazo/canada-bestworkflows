<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Timekeeping\Application\Commands\ImportShiftTypes;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/**
 * The shift patterns as CSV, listed as the table lists them
 * ({@see ShiftTypeRows}). The table folds hours, break and work week into
 * one phrase each; the file spells them out a value per column — 24-hour
 * times, the days one by one — because it is also the import template
 * ({@see ImportShiftTypes}).
 */
final class ShiftTypesExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $search = $query['q'] ?? '';
        $sort = in_array($query['sort'] ?? '', ['id', 'name', 'mode'], true) ? (string) $query['sort'] : 'name';
        $direction = ($query['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

        $rows = static function () use ($actor, $search, $sort, $direction): Generator {
            $hm = static fn (?string $time): string => $time === null || $time === '' ? '' : substr($time, 0, 5);
            foreach (ShiftTypeRows::list($actor, $search, $sort, $direction) as $pattern) {
                $days = array_map(intval(...), $pattern->work_days);
                sort($days);
                yield [
                    $pattern->code, $pattern->name, $pattern->mode === 'FLEXIBLE' ? 'Flexible' : 'Fixed',
                    implode(', ', array_map(static fn (int $day): string => ImportShiftTypes::DAYS[$day] ?? '', $days)),
                    $hm($pattern->start_time), $hm($pattern->end_time),
                    $pattern->required_hours === null ? '' : (float) $pattern->required_hours,
                    $hm($pattern->break_start), $hm($pattern->break_end), $pattern->break_minutes,
                    $pattern->requires_break_punch ? 'Yes' : 'No', $pattern->grace_minutes,
                    // Effective from: an import column, blank on the way out.
                    '',
                ];
            }
        };

        return new CsvExport('shift-types-'.ManilaClock::todayKey().'.csv', ImportShiftTypes::COLUMNS, $rows);
    }
}
