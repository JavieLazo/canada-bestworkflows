<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Modules\Workforce\Application\Queries\RecordChangeEvidence;
use App\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Downloads frozen proposal evidence only after Workforce checks current access. */
final class RecordChangeEvidenceController
{
    public function __invoke(string $request, string $which): StreamedResponse
    {
        return app(RecordChangeEvidence::class)->download(app(Access::class), $request, $which);
    }
}
