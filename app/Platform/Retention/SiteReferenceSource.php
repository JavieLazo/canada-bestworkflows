<?php

declare(strict_types=1);

namespace App\Platform\Retention;

/** Each record owner explains why a referenced work site must be retained. */
interface SiteReferenceSource
{
    public function refusal(string $siteId): ?string;
}
