<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Support\Access;
use Illuminate\Http\UploadedFile;

/** Refuses employee document proposals because official documents belong to Employee Records management. */
final class SubmitRecordDocument
{
    /** @param array<string, mixed> $form */
    public function attach(Access $actor, ?UploadedFile $upload, array $form, string $reason): string
    {
        abort(403, 'Documents are managed by management.');
    }

    public function delete(Access $actor, string $documentId, string $reason): string
    {
        abort(403, 'Documents are managed by management.');
    }
}
