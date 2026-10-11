<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Transfer\CsvDownload;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every table's "Export CSV" link, and the import template (`?template=1`).
 *
 * Each route is registered in its module's route file, behind that page's
 * own `module:` gate, with the table key as a route DEFAULT — so the key is
 * the server's, never the query string's. The owning module's TableExport
 * re-authorizes the actor, whitelists the filters it reads from the link and
 * reads through the same query as the screen.
 */
class TableExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $actor = Access::forActor(auth()->user());
        // Read by NAME: Laravel hands route parameters to the method in route
        // order, so on /employees/{employee}/documents/export a `string $table`
        // argument would receive the employee id.
        $table = (string) $request->route('table');
        $query = [];
        foreach ($request->query() as $key => $value) {
            // TableExport accepts scalar filters. Dropping an array silently
            // removes the filter and can export a broader selection instead.
            if (is_string($key) && is_array($value)) {
                throw ValidationException::withMessages([$key => 'Export filters must contain a single value.']);
            }
            if (is_string($key) && is_scalar($value)) {
                $query[$key] = (string) $value;
            }
        }
        // Route parameters (a project, a run) outrank a query string of the same name.
        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            if ($key !== 'table' && is_scalar($value)) {
                $query[$key] = (string) $value;
            }
        }

        $export = app(TableTransfers::class)->export($table)->export($actor, $query);

        return CsvDownload::response($request->boolean('template') ? $export->template() : $export);
    }
}
