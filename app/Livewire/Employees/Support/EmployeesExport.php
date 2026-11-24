<?php

declare(strict_types=1);

namespace App\Livewire\Employees\Support;

use App\Livewire\Kit\Support\DepartmentFilter;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\EmployeeColumns;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\LocalClock;
use Generator;

/**
 * Employee Records as CSV: the employees the table lists — same scope,
 * search, status, position and department filters and sort
 * ({@see EmployeeRecords::arranged()}, the rule the table's page() reads) —
 * each as their whole 201 record
 * ({@see EmployeeColumns}). The department narrows which employees are in
 * the file; it adds no column. Export only: Employee Records has no CSV
 * import.
 *
 * The scope decides who is in it: all_data + EMPLOYEES or PAYROLL exports
 * everyone; anyone else exports only the record they can already open,
 * their own.
 */
final class EmployeesExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $search = $query['q'] ?? '';
        $status = ($query['status'] ?? '') !== '' ? (string) $query['status'] : 'ALL';
        $position = ($query['position'] ?? '') !== '' ? (string) $query['position'] : 'ALL';
        $department = ($query['department'] ?? '') !== '' ? (string) $query['department'] : DepartmentFilter::ALL;
        $sort = in_array($query['sort'] ?? '', ['id', 'employee_id_no', 'employee', 'department', 'email', 'status'], true) ? (string) $query['sort'] : 'employee';
        $direction = ($query['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

        $rows = static function () use ($actor, $search, $status, $position, $department, $sort, $direction): Generator {
            $siteNames = [];
            foreach (app(OrganizationDirectory::class)->sites($actor) as $site) {
                $siteNames[(string) $site->getKey()] = $site->name;
            }
            $employees = app(EmployeeRecords::class)->arranged($actor, [
                'search' => $search, 'status' => $status, 'position' => $position,
                'department' => DepartmentFilter::choice($department, DepartmentFilter::options($actor)),
            ], $sort, $direction, details: true);
            foreach ($employees as $employee) {
                yield EmployeeColumns::cells($employee, $siteNames);
            }
        };

        return new CsvExport('employees-'.LocalClock::todayKey().'.csv', EmployeeColumns::row(), $rows);
    }
}
