<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

final readonly class CapacityTotals
{
    public function __construct(
        public int $plannedHundredths,
        public int $personWeeks,
        public int $knownWeeks,
        public int $overloadedPeople,
        public int $needsEstimate,
    ) {}
}
