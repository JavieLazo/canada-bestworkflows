<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Queries\EmployeeDocuments;
use App\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /employees/documents/{document}/download — the file itself.
 *
 * The port of `GET /api/employees/documents/[id]`
 * (api/employees/documents/[id]/route.ts): the bytes live behind their own
 * request precisely so the list reads do not carry them, and a caller may
 * fetch a document only if they hold the Employees module with `all_data`.
 *
 * Served as `attachment`, never inline — these are uploads of unknown
 * provenance, and rendering one in the app's own origin would let an HTML
 * or SVG upload run script against a signed-in session.
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(Access $access, string $document): StreamedResponse
    {
        return app(EmployeeDocuments::class)->download($access, $document);
    }
}
