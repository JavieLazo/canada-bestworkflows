<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\ProjectPolicy;
use App\Http\Controllers\Controller;
use App\Livewire\Projects\ProjectsData;
use App\Support\Access;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /projects/export — the projects-page.tsx "Export CSV" button.
 *
 * The reference built the file in the browser from the *visible* (filtered,
 * sorted) list; here the Livewire screen passes its current filters and sort
 * in the query string and this controller re-runs the exact same pipeline
 * (ProjectsData::visibleProjects) over the exact same scoped reads, so the
 * download always matches what the table shows. Same columns, same order,
 * same `projects-YYYY-MM-DD.csv` name.
 */
class ProjectsExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $actor = Access::forActor(auth()->user());
        $today = ProjectsData::todayKey();

        $data = ProjectsData::projectsDataset($actor);

        $sort = (string) $request->query('sort', 'code');
        if (! in_array($sort, ['code', 'name', 'manager', 'status'], true)) {
            $sort = 'code';
        }
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $visible = ProjectsData::visibleProjects(
            $data['projects'],
            $data['tasksByProject'],
            $data['accounts'],
            [
                'q' => (string) $request->query('q', ''),
                'status' => (string) $request->query('status', 'ALL'),
                'site' => (string) $request->query('site', 'ALL'),
                'manager' => (string) $request->query('manager', 'ALL'),
                'queue' => (string) $request->query('queue', 'ALL'),
            ],
            $sort,
            $dir,
            $today,
        );

        $rows = [];
        foreach ($visible as $project) {
            $projectTasks = $data['tasksByProject'][$project->id] ?? [];
            $shapes = ProjectsData::taskShapes($projectTasks);
            $progress = ProjectPolicy::progress($shapes);
            $health = ProjectPolicy::health(ProjectsData::projectShape($project), $shapes, $today);
            $manager = $project->manager_id === null
                ? ''
                : (string) ($data['accounts']->firstWhere('id', $project->manager_id)->name ?? '');

            $rows[] = [
                $project->code,
                $project->name,
                (string) ($data['sites']->firstWhere('id', $project->site_id)->name ?? ''),
                $manager,
                ProjectsData::dateKey($project->start_date) ?? '',
                ProjectsData::dateKey($project->due_date) ?? '',
                $progress['percent'].'%',
                ProjectPolicy::healthLabel($health),
                ProjectPolicy::statusLabel($project->status),
            ];
        }

        $csv = ProjectsData::toCsv(
            ['Code', 'Project', 'Site', 'Task Leader', 'Start date', 'Due date', 'Progress', 'Health', 'Status'],
            $rows,
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv;charset=utf-8;',
            'Content-Disposition' => 'attachment; filename="projects-'.$today.'.csv"',
        ]);
    }
}
