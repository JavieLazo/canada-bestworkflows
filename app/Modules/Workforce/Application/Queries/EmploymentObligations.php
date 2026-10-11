<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Platform\Retention\EmployeeObligations;
use App\Support\Access;

/** Shows an authorized employment editor the obligations an inactivation must acknowledge. */
final class EmploymentObligations
{
    /** @return list<array{label:string,reference:string}> */
    public function forEmployee(Access $actor, string $employeeId): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        if (app(EmployeeRecords::class)->find($actor, $employeeId) === null) {
            return [];
        }

        return app(EmployeeObligations::class)->outstanding($employeeId);
    }
}
