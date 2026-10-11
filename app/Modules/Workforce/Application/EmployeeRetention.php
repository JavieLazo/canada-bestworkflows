<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Platform\Retention\EmployeeReferenceSource;

/** Checks other owners' history without following cross-module model relationships. */
final class EmployeeRetention
{
    /** @param iterable<EmployeeReferenceSource> $sources */
    public function __construct(private readonly iterable $sources) {}

    public function any(string $employeeId): bool
    {
        foreach ($this->sources as $source) {
            if ($source->references($employeeId)) {
                return true;
            }
        }

        return false;
    }
}
