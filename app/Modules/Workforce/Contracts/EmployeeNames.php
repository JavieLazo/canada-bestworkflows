<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

/** Name-only account attribution is available while constructing the authenticated actor. */
interface EmployeeNames
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, string>
     */
    public function labels(array $employeeIds): array;
}
