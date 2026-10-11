<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeDocument;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;

/** Supplies reference usage without allowing the Records screen to query Workforce tables. */
final class ReferenceUsage
{
    /**
     * The employment status of each employee the actor's directory shows,
     * for the Employment list's "in use by" counts. Position holders are
     * counted by link on the Position page instead (PositionHolders).
     *
     * @return list<string>
     */
    public function employmentStatuses(Access $actor): array
    {
        return array_values(app(OperationalDirectory::class)->list($actor)->map(static fn (DirectoryEmployee $row): string => $row->employment_status)->all());
    }

    /** @return list<string|null> */
    public function documents(Access $actor): array
    {
        return array_values(EmployeeDocument::query()->toEmployee($actor, 'employee_id', ModuleCode::EMPLOYEES, ModuleCode::RECORDS)->get(['category'])->map(static fn (EmployeeDocument $row): string => $row->category)->all());
    }
}
