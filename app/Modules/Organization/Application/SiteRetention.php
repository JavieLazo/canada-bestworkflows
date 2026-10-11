<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Platform\Retention\SiteReferenceSource;

/** Checks site references in the existing employee, attendance, project order. */
final class SiteRetention
{
    /** @param iterable<SiteReferenceSource> $sources */
    public function __construct(private readonly iterable $sources) {}

    public function refusal(string $siteId): ?string
    {
        foreach ($this->sources as $source) {
            $reason = $source->refusal($siteId);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
