<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;

/** Current employment coverage and placement labels for scoped attendance analytics, without profile or pay details. */
interface EmployeeReportContext
{
    /**
     * @param  list<string>  $ids
     * @return array<string, array{department: string, unit: string, position: string, worksite: string, employment: string, active: bool, date_hired: string, date_resigned: ?string}>
     */
    public function attendanceContext(Access $actor, array $ids): array;

    /**
     * The department each permitted employee is placed in, for the payslip
     * header: PAYROLL's row scope, and the department's name alone.
     *
     * @param  list<string>  $ids
     * @return array<string, string> employee id => department name, '' when not placed
     */
    public function payrollDepartments(Access $actor, array $ids): array;
}
