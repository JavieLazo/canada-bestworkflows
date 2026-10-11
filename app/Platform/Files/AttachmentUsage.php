<?php

declare(strict_types=1);

namespace App\Platform\Files;

/**
 * The storage budget employee documents, task files and inventory attachments share, measured once,
 * consistently, across their upload surfaces. Community and Chat keep their
 * own (Social `Attachments`).
 */
final class AttachmentUsage
{
    public static function bytes(): int
    {
        $bytes = 0;
        foreach (app()->tagged('attachment-usage-sources') as $source) {
            if ($source instanceof StorageUsageSource) {
                $bytes += $source->bytes();
            }
        }

        return $bytes;
    }
}
