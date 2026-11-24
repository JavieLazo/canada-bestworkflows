<?php

declare(strict_types=1);

namespace App\Livewire\Edtr\Support;

use App\Domain\Attendance\Minutes;
use App\Livewire\Edtr\LiveFeed;
use App\Modules\Timekeeping\Application\Queries\PunchFeed;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Generator;

/**
 * A punch feed as CSV — the rows its card shows, newest first, through the
 * same {@see PunchFeed::list()} read as the eDTR Live Feed (the actor's
 * attendance scope, corrections left out, latest {@see LiveFeed}'s 300).
 * Timestamps are local wall-clock. Export only: punches are captured at the
 * station and corrected on the DTR, never imported.
 */
final class PunchesExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        // The card's own default page of punches, read off the component rather than restated.
        $limit = (int) (new \ReflectionProperty(LiveFeed::class, 'limit'))->getDefaultValue();

        $rows = static function () use ($actor, $limit): Generator {
            foreach (app(PunchFeed::class)->list($actor, $limit) as $punch) {
                yield [
                    $punch->employee->employee_code ?? '',
                    $punch->employee === null ? '' : EmployeeName::display($punch->employee),
                    $punch->logged_at->setTimezone(LocalClock::zone())->format('Y-m-d H:i:s'),
                    LocationKind::label($punch->location_kind, $punch->site?->name),
                    str_replace('_', ' ', $punch->log_type),
                    str_replace('_', ' ', $punch->source),
                    $punch->distance_meters === null ? '' : (int) Minutes::round((float) $punch->distance_meters),
                ];
            }
        };

        $headers = ['ID', 'Employee', 'Timestamp', 'Site', 'Type', 'Source', 'Distance (m)'];

        return new CsvExport('punches-'.LocalClock::todayKey().'.csv', $headers, $rows);
    }
}
