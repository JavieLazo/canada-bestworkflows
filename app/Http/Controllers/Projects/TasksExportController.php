<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Modules\WorkManagement\Application\Queries\WorkExports;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Exports the complete filtered task list through its owning module. */
class TasksExportController extends Controller
{
    public function __invoke(Request $request, string $project): Response
    {
        $actor = Access::forActor(auth()->user());
        if ($request->boolean('template')) {
            return app(WorkExports::class)->tasksTemplate($actor, $project);
        }
        $sort = (string) $request->query('sort', 'stack');
        if (! in_array($sort, ['stack', 'code', 'task', 'group', 'assignee', 'priority', 'start', 'due', 'status'], true)) {
            $sort = 'stack';
        }
        $selection = $request->validate(['groups' => 'sometimes|array|list|max:1000', 'groups.*' => 'required|string|max:26']);

        return app(WorkExports::class)->tasks($actor, $project, [
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', 'ALL'),
            'assignee' => (string) $request->query('assignee', 'ALL'),
            'priority' => (string) $request->query('priority', 'ALL'),
            'queue' => (string) $request->query('queue', 'ALL'),
            'group' => (string) $request->query('group', 'ALL'),
            'groups' => $selection['groups'] ?? [],
            'parent' => (string) $request->query('parent', 'ALL'),
        ], $sort, $request->query('dir') === 'desc' ? 'desc' : 'asc', ManilaClock::todayKey(), $request->boolean('grouped'));
    }
}
