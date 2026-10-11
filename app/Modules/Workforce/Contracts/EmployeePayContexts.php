<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;

/** Payroll-specific employee data; not a general directory or writable model. */
interface EmployeePayContexts
{
    /**
     * @param  list<string>  $ids
     * @return list<EmployeePayData>
     */
    public function get(Access $actor, array $ids): array;
}
