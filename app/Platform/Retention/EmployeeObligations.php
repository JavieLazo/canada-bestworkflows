<?php

declare(strict_types=1);

namespace App\Platform\Retention;

/** Composes outstanding obligations without coupling employee writes to another business owner. */
final class EmployeeObligations
{
    /** @param iterable<EmployeeObligationSource> $sources */
    public function __construct(private readonly iterable $sources) {}

    public function lock(): void
    {
        foreach ($this->sources as $source) {
            $source->lock();
        }
    }

    /** @return list<array{label:string,reference:string}> */
    public function outstanding(string $employeeId): array
    {
        $rows = [];
        foreach ($this->sources as $source) {
            array_push($rows, ...$source->outstanding($employeeId));
        }

        return $rows;
    }
}
