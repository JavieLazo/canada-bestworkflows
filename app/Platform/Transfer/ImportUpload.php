<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The CSV an import dialog was handed: its bytes, and its removal once the
 * dialog is done with it.
 *
 * An import never stores the file. What Livewire keeps is its temporary
 * upload, and the dialog discards it on Cancel, after a successful import,
 * and the moment a preview or an import reports any problem. One abandoned
 * mid-dialog (the tab closed, another page opened) stays in `livewire-tmp`
 * until Livewire's own sweep, which deletes uploads older than a day the
 * next time anyone uploads a file. Technical file handling, not a business
 * write: it lives here, next to the runner, not in the screen.
 */
final class ImportUpload
{
    public function path(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            throw new \RuntimeException('The temporary upload is no longer available. Choose the file again.');
        }

        return $path;
    }

    /** The file's bytes, or null when they can no longer be read. */
    public function bytes(UploadedFile $file): ?string
    {
        $bytes = $file->get();

        return is_string($bytes) ? $bytes : null;
    }

    public function discard(UploadedFile $file): void
    {
        // Livewire's temporary upload removes itself from its own disk, but
        // not the note beside it (`<name>.json`: the original file name, type
        // and size, Livewire 4.4), so that goes too. Any other upload is a
        // plain temporary file.
        if ($file instanceof TemporaryUploadedFile) {
            $file->delete();
            FileUploadConfiguration::storage()->delete(FileUploadConfiguration::path($file->getFilename().'.json'));

            return;
        }
        $path = $file->getRealPath();
        if ($path !== false && is_file($path)) {
            unlink($path);
        }
    }
}
