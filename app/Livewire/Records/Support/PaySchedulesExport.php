<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Application\Queries\PayScheduleRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\LocalClock;
use Generator;

/**
 * Pay Schedules as CSV: every schedule as the page lists it, searched as
 * on screen ({@see PayScheduleRecords}). Export only: a schedule is set up
 * in its dialog.
 */
final class PaySchedulesExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $needle = mb_strtolower(trim((string) ($query['q'] ?? '')));

        $rows = static function () use ($actor, $needle): Generator {
            foreach (app(PayScheduleRecords::class)->list($actor) as $view) {
                if ($needle !== '' && ! str_contains(mb_strtolower($view->name), $needle)) {
                    continue;
                }
                $schedule = PayScheduleText::schedule($view);
                yield $schedule === null
                    ? [$view->name, 'Unreadable', '', '', '', '', $view->is_default ? 'Default' : '', $view->runs_count]
                    : [
                        $view->name, PayScheduleText::periods($schedule), PayScheduleText::payDays($schedule), PayScheduleText::holidayRule($schedule),
                        PayScheduleText::deadlines($schedule), PayScheduleText::contributionMonth($schedule), $view->is_default ? 'Default' : '', $view->runs_count,
                    ];
            }
        };

        return new CsvExport('pay-schedules-'.LocalClock::todayKey().'.csv', ['Name', 'Salary periods', 'Pay days', 'Weekend or holiday', 'Deadlines', 'Contribution month', 'Default', 'Payrolls'], $rows);
    }
}
