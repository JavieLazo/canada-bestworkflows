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

        $assigneeIds = array_values(array_unique(array_filter(
            array_map(static fn (ProjectTask $t): ?string => $t->assignee_id, $projectTasks),
        )));
        $employees = EmployeeProfile::query()->whereIn('id', $assigneeIds)->get();

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
            $employee = $task->assignee_id === null ? null : $employees->firstWhere('id', $task->assignee_id);

            $rows[] = [
                $task->code,
                $task->title,
                ProjectsData::employeeName($employee),
                TaskPolicy::priorityLabel($task->priority),
                ProjectsData::dateKey($task->due_date) ?? '',
                TaskPolicy::statusLabel($task->status),
            ];
        }

        $csv = ProjectsData::toCsv(
            ['Code', 'Task', 'Assignee', 'Priority', 'Due date', 'Status'],
            $rows,
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv;charset=utf-8;',
            'Content-Disposition' => 'attachment; filename="'.mb_strtolower($projectRow->code).'-tasks-'.$today.'.csv"',
        ]);
    }
}
