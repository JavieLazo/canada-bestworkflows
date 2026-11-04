<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

/**
 * The employment facts the leave earning job reads, for every employee or
 * for some: when they joined, when they left, and whether the record is
 * active. A system read with no actor — the job runs from the schedule —
 * so it exposes nothing beyond those three facts.
 */
interface EmployeeLeaveFacts
{
    /**
     * @param  list<string>|null  $ids  null for every employee record
     * @return array<string, array{id: string, date_hired: string, date_resigned: ?string, is_active: bool}> keyed by employee id
     */
    public function forPosting(?array $ids = null): array;
}
