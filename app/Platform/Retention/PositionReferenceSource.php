<?php

declare(strict_types=1);

namespace App\Platform\Retention;

/** Each record owner explains why a position its records hold must be retained. */
interface PositionReferenceSource
{
    public function refusal(string $positionId): ?string;
}
