<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Contracts\EmployeeReportContext;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\HrisFormat;

/** Attendance gets only currently permitted employees' employment dates and present organization labels. */
final class EmployeeReportContextQuery implements EmployeeReportContext
{
    public function attendanceContext(Access $actor, array $ids): array
    {
        abort_unless($actor->hasModule(ModuleCode::REPORTS) && $actor->hasModule(ModuleCode::EDTR), 403);
        $query = EmployeeProfile::query()->whereKey($ids);
        match ($actor->rowVisibility(ModuleCode::EDTR)) {
            Access::ROWS_ALL => null,
            Access::ROWS_OWN => $query->whereKey($actor->ownEmployeeId()),
            Access::ROWS_NONE => $query->whereRaw('1 = 0'),
        };
        $fields = ['id', 'position', 'work_site_id', 'employment_status', 'is_active', 'date_hired', 'date_resigned'];
        if (EmployeePlacements::ready()) {
            $fields[] = 'position_id';
        }
        $employees = $query->limit(ReportDocument::MAX_ROWS + 1)->get($fields);
        ReportDocument::guardSize($employees->count());
        $placements = EmployeePlacements::of($employees);
        $sites = $employees->isEmpty() ? collect() : app(OrganizationDirectory::class)->sites($actor)->keyBy('id');
        $facts = [];
        foreach ($employees as $employee) {
            $placement = $placements[$employee->id] ?? [];
            $facts[$employee->id] = [
                'department' => (string) ($placement['department_name'] ?? ''),
                'unit' => (string) ($placement['unit_name'] ?? ''),
                'position' => trim($employee->position ?? ''),
                'worksite' => $sites->get($employee->work_site_id)->name ?? '',
                'employment' => HrisFormat::employmentStatus($employee->employment_status),
                'active' => $employee->is_active,
                'date_hired' => $employee->date_hired->format('Y-m-d'),
                'date_resigned' => $employee->date_resigned?->format('Y-m-d'),
            ];
        }

        return $facts;
    }

    public function payrollDepartments(Access $actor, array $ids): array
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL), 403);
        if ($ids === [] || ! EmployeePlacements::ready()) {
            return [];
        }
        $query = EmployeeProfile::query()->whereKey($ids);
        match ($actor->rowVisibility(ModuleCode::PAYROLL)) {
            Access::ROWS_ALL => null,
            Access::ROWS_OWN => $query->whereKey($actor->ownEmployeeId()),
            Access::ROWS_NONE => $query->whereRaw('1 = 0'),
        };
        $employees = $query->get(['id', 'position_id']);
        $placements = EmployeePlacements::of($employees);
        $departments = [];
        foreach ($employees as $employee) {
            $departments[$employee->id] = (string) ($placements[$employee->id]['department_name'] ?? '');
        }

        return $departments;
    }
}
