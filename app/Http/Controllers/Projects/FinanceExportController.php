<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Modules\WorkManagement\Application\Queries\FinanceExports;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Support\Access;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceExportController extends Controller
{
    public function __invoke(Request $request, string $project): StreamedResponse
    {
        $actor = Access::forActor(auth()->user());
        abort_unless(app(ProjectFinancials::class)->canView($actor, $project), 403);
        $request->validate([
            'kind' => 'nullable|string|in:budget,cash', 'budget' => 'nullable|string|ulid', 'q' => 'nullable|string|max:200',
            'type' => 'nullable|string|in:ALL,RECEIPT,EXPENSE', 'voided' => 'nullable|boolean',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'category' => 'nullable|string|ulid',
        ]);
        $exports = app(FinanceExports::class);
        if ($request->query('kind') === 'cash') {
            return $exports->cash($actor, $project, (string) $request->query('q', ''), (string) $request->query('type', 'ALL'), $request->boolean('voided'), ['from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''), 'category' => (string) $request->query('category', '')]);
        }
        $budget = (string) $request->query('budget', '');
        if ($budget === '') {
            $budget = app(ProjectFinancials::class)->versions($actor, $project)->first()->id ?? '';
        }
        abort_if($budget === '', 404);

        return $exports->budget($actor, $project, $budget, (string) $request->query('q', ''), (string) $request->query('category', ''));
    }
}
