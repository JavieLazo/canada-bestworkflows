<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Platform\Retention\PositionReferenceSource;

/** Asks every owner whose records hold a position whether it may be deleted. */
final class PositionRetention
{
    /** @param iterable<PositionReferenceSource> $sources */
    public function __construct(private readonly iterable $sources) {}

    public function refusal(string $positionId): ?string
    {
        foreach ($this->sources as $source) {
            $reason = $source->refusal($positionId);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
