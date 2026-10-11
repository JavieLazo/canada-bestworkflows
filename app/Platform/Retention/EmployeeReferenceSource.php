<?php

declare(strict_types=1);

namespace App\Platform\Retention;

/** Internal deletion guard supplied by each owner of employee history. */
interface EmployeeReferenceSource
{
    public function references(string $employeeId): bool;
}
