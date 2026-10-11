<?php

declare(strict_types=1);

namespace App\Platform\Files;

/**
 * Each attachment owner reports the bytes its rows hold. Tagged
 * `attachment-usage-sources`, a source counts toward the budget documents and
 * task files share; Community and Chat's counts toward their own.
 */
interface StorageUsageSource
{
    public function bytes(): int;
}
