<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\TaskPolicy;
use App\Http\Controllers\Controller;
use App\Livewire\Projects\ProjectsData;
use App\Models\EmployeeProfile;
use App\Models\ProjectTask;
use App\Support\Access;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /projects/{project}/tasks/export — the project-detail.tsx "Export CSV"
 * button: the project's *visible* (filtered, sorted) task list as
 * `{code}-tasks-YYYY-MM-DD.csv` with the same six columns in the same order.
 *
 * "Assignees" mirrors the table column — every assignee, ordered by last name
 * then first — but joined with "; " rather than the table's ", ", so a cell
 * holding two people is not mistaken for one "Last, First" name.
 */
class TasksExportController extends Controller
{
    public function __invoke(Request $request, string $project): Response
    {
        $actor = Access::forActor(auth()->user());
        $today = ProjectsData::todayKey();

        $projectRow = ProjectsData::scopedProjects($actor)->whereKey($project)->first();
        if ($projectRow === null) {
            abort(404, 'That project no longer exists.');
        }

        /** @var list<ProjectTask> $projectTasks */
        $projectTasks = ProjectsData::scopedTasks($actor)
            ->where('project_id', $projectRow->id)
            ->orderBy('code')
            ->get()
            ->all();

        // scopedTasks() eager-loaded the pivot; the union of every task's
        // assignee set is what the sort key resolves names against.
        $assigneeIds = [];
        foreach ($projectTasks as $t) {
            foreach ($t->assigneeIds() as $id) {
                $assigneeIds[$id] = true;
            }
        }
        $employees = EmployeeProfile::query()->whereIn('id', array_keys($assigneeIds))->get();

        $sort = (string) $request->query('sort', 'stack');
        if (! in_array($sort, ['stack', 'code', 'task', 'assignee', 'priority', 'due', 'status'], true)) {
            $sort = 'stack';
        }
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $visible = ProjectsData::visibleTasks(
            $projectTasks,
            $employees,
            [
                'q' => (string) $request->query('q', ''),
                'status' => (string) $request->query('status', 'ALL'),
                'assignee' => (string) $request->query('assignee', 'ALL'),
                'priority' => (string) $request->query('priority', 'ALL'),
                'queue' => (string) $request->query('queue', 'ALL'),
            ],
            $sort,
            $dir,
            $today,
        );

        $rows = [];
        foreach ($visible as $task) {
            $rows[] = [
                $task->code,
                $task->title,
                self::assigneeCell($task),
                TaskPolicy::priorityLabel($task->priority),
                ProjectsData::dateKey($task->due_date) ?? '',
                TaskPolicy::statusLabel($task->status),
            ];
        }

        $csv = ProjectsData::toCsv(
            ['Code', 'Task', 'Assignees', 'Priority', 'Due date', 'Status'],
            $rows,
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv;charset=utf-8;',
            'Content-Disposition' => 'attachment; filename="'.mb_strtolower($projectRow->code).'-tasks-'.$today.'.csv"',
        ]);
    }

    /**
     * The table's assignee order (last name, then first — what
     * {@see ProjectsData::assigneeNames()} prints) joined with "; "; "—" when
     * the task is unassigned.
     */
    private static function assigneeCell(ProjectTask $task): string
    {
        $employees = $task->assignees->all();

        if ($employees === []) {
            return ProjectsData::employeeName(null);
        }

        usort($employees, static function (EmployeeProfile $a, EmployeeProfile $b): int {
            $last = ProjectsData::compareText($a->last_name, $b->last_name);

            return $last !== 0 ? $last : ProjectsData::compareText($a->first_name, $b->first_name);
        });

        return implode('; ', array_map(static fn (EmployeeProfile $e): string => ProjectsData::employeeName($e), $employees));
    }
}
