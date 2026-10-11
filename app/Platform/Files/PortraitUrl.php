<?php

declare(strict_types=1);

namespace App\Platform\Files;

use Illuminate\Support\Facades\Storage;

/** Public portraits are re-encoded images; private attachments never use this URL path. */
final class PortraitUrl
{
    public static function for(?string $path): ?string
    {
        return $path === null || $path === '' ? null : Storage::disk('public')->url($path);
    }
}
