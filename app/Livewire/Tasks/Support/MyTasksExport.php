<?php

declare(strict_types=1);

namespace App\Livewire\Tasks\Support;

use App\Domain\Projects\TaskPolicy;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/**
 * My Tasks as CSV: the actor's own tasks, searched, filtered and sorted as
 * the table lists them, page after page of {@see WorkRecords::ownPage()} —
 * never widened, even for a Projects manager. The status is the one the
 * table shows (the actor's own response on an individual task). Export
 * only: work is started and handed in on the task itself.
 */
final class MyTasksExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $filters = [
            'q' => $query['q'] ?? '',
            'status' => ($query['status'] ?? '') !== '' ? (string) $query['status'] : 'ALL',
            'project' => ($query['project'] ?? '') !== '' ? (string) $query['project'] : 'ALL',
        ];
        $sort = in_array($query['sort'] ?? '', ['code', 'task', 'priority', 'start', 'due', 'status'], true) ? (string) $query['sort'] : 'priority';
        $direction = ($query['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

        $rows = static function () use ($actor, $filters, $sort, $direction): Generator {
            $records = app(WorkRecords::class);
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => $records->ownPage($actor, $filters, $sort, $direction, $page, $perPage)) as $task) {
                yield [
                    $task->code, $task->title, $task->project->name ?? '', TaskPolicy::priorityLabel($task->priority),
                    $task->start_date?->format('Y-m-d') ?? '', $task->due_date?->format('Y-m-d') ?? '', $task->myStatusLabel(),
                ];
            }
        };

        return new CsvExport('my-tasks-'.ManilaClock::todayKey().'.csv', ['Code', 'Task', 'Project', 'Priority', 'Start date', 'Due date', 'Status'], $rows);
    }
}
