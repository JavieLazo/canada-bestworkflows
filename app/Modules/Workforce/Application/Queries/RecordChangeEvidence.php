<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Support\Access;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves frozen proposal files through the same current scope as the request. */
final class RecordChangeEvidence
{
    public function download(Access $actor, string $requestId, string $which): StreamedResponse
    {
        $file = app(RecordChangeRequests::class)->evidence($actor, $requestId, $which);
        abort_if($file === null, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file['path']), 404, 'That file could not be read.');

        return $disk->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
