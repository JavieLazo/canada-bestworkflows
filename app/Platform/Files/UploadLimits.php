<?php

declare(strict_types=1);

namespace App\Platform\Files;

/** Shared limits keep every business owner's upload validation and displayed cap consistent. */
final class UploadLimits
{
    public static function maxFileBytes(): int
    {
        return (int) config('copems.uploads.max_file_bytes', 2_000_000);
    }

    public static function storageCeilingBytes(): int
    {
        return (int) config('copems.uploads.storage_ceiling_bytes', 1_000_000_000);
    }
}
