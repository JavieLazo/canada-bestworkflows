<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Modules\WorkManagement\Application\Queries\WorkExports;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The owner query shares the table's scoped filters, order, and CSV representation. */
class ProjectsExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $actor = Access::forActor(auth()->user());
        if ($request->boolean('template')) {
            return app(WorkExports::class)->projectsTemplate($actor);
        }
        $sort = (string) $request->query('sort', 'code');
        if (! in_array($sort, ['code', 'name', 'manager', 'status'], true)) {
            $sort = 'code';
        }

        return app(WorkExports::class)->projects($actor, [
            'q' => (string) $request->query('q', ''),
            'client' => (string) $request->query('client', 'ALL'),
            'portfolio' => (string) $request->query('portfolio', 'ALL'),
            'status' => (string) $request->query('status', 'ALL'),
            'site' => (string) $request->query('site', 'ALL'),
            'manager' => (string) $request->query('manager', 'ALL'),
            'queue' => (string) $request->query('queue', 'ALL'),
        ], $sort, $request->query('dir') === 'desc' ? 'desc' : 'asc', LocalClock::todayKey());
    }
}
