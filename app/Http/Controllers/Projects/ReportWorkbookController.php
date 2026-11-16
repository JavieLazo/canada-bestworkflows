<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Modules\WorkManagement\Application\Queries\ProjectReportWorkbooks;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReportWorkbookController extends Controller
{
    public function __invoke(Request $request, string $project, string $report): Response
    {
        $data = $request->validate(['submission' => 'nullable|string|size:26']);

        return app(ProjectReportWorkbooks::class)->download(Access::forActor(auth()->user()), $project, $report, $data['submission'] ?? null);
    }
}
