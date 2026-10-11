<?php

declare(strict_types=1);

namespace App\Platform\Retention;

/** Outstanding business obligations that employment editors must acknowledge before inactivation. */
interface EmployeeObligationSource
{
    /** Called inside the employee write transaction before its profile row is locked. */
    public function lock(): void;

    /** Internal facts for an employee the caller already authorized.
     * @return list<array{label:string,reference:string}>
     */
    public function outstanding(string $employeeId): array;
}
