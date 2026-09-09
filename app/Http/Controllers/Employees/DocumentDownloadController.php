<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Support\Access;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /employees/documents/{document}/download — the file itself.
 *
 * The port of `GET /api/employees/documents/[id]`
 * (api/employees/documents/[id]/route.ts): the bytes live behind their own
 * request precisely so the list reads do not carry them, and a caller may
 * fetch a document only if they hold the Employees module with `all_data`
 * or it hangs off their OWN 201 file.
 *
 * Served as `attachment`, never inline — these are uploads of unknown
 * provenance, and rendering one in the app's own origin would let an HTML
 * or SVG upload run script against a signed-in session.
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(Access $access, string $document): StreamedResponse
    {
        $doc = EmployeeDocument::query()->find($document);

        abort_if($doc === null, 404, 'That document was not found.');

        if (! $access->mayReadAllData('EMPLOYEES') && $access->employeeId !== $doc->employee_id) {
            abort(403);
        }

        $disk = Storage::disk('local');

        abort_unless($disk->exists($doc->path), 404, 'That file could not be read.');

        // download() emits RFC 6266 Content-Disposition: attachment with an
        // ASCII fallback name plus the UTF-8 `filename*`, which is exactly
        // what fileResponse() (lib/api/file.ts:64-99) hand-built.
        return $disk->download($doc->path, $doc->file_name, [
            'Content-Type' => $doc->mime !== '' ? $doc->mime : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
