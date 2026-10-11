<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

/** Employee identity and worksite needed to attribute attendance, without pay data. */
final readonly class EmployeeAttendanceData
{
    public function __construct(
        public string $id,
        public string $first_name,
        public string $last_name,
        public ?string $suffix,
        public ?string $work_site_id,
    ) {}
}
