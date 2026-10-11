<?php

declare(strict_types=1);

namespace App\Livewire\Employees\Support;

use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Kit\Support\DepartmentFilter;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView as EmployeeProfile;
use App\Support\CodeSort;
use App\Support\EmployeeName;
use Illuminate\Support\Collection;

/**
 * The Employee Records rows: the scoped directory (every row for all_data +
 * EMPLOYEES/PAYROLL, the actor's own otherwise, none for an unlinked
 * account — {@see EmployeeRecords::list()}), filtered and sorted in PHP
 * exactly as the source does it in the client (employees-page.tsx:133-177).
 * One copy, shared by the table and its CSV export.
 *
 * The department filter and the Department sort read the placement every
 * view carries (EmployeePlacements): a department lists the people of its
 * units too, a unit only its own ({@see DepartmentFilter}). They run on the
 * scoped rows, so they only ever narrow them.
 */
final class EmployeeRows
{
    /**
     * @param  Collection<int, EmployeeProfile>  $employees  the scoped directory, as EmployeeRecords::list() returns it
     * @param  string  $department  a DepartmentFilter choice: ALL, NONE (Not placed), or a department or unit id
     * @return list<EmployeeProfile>
     */
    public static function arrange(Collection $employees, string $search, string $status, string $position, string $department, string $sortKey, string $sortDir): array
    {
        $needle = mb_strtolower(trim($search));

        $rows = $employees->filter(static function (EmployeeProfile $e) use ($needle, $status, $position, $department): bool {
            if ($status === 'INACTIVE') {
                if ($e->is_active) {
                    return false;
                }
            } elseif ($status !== 'ALL'
                && EmployeeFields::statusKey($e->employment_status) !== EmployeeFields::statusKey($status)) {
                return false;
            }

            if ($position !== 'ALL' && ($e->position ?? '') !== $position) {
                return false;
            }

            if (! DepartmentFilter::matches($e, $department)) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            // BOTH identifiers, because both are columns here and HR types
            // whichever number the paper in front of them carries — the minted
            // ID on an older handover, the Employee ID on a newer one.
            $haystack = mb_strtolower(
                "{$e->employee_code} ".($e->employee_id_no ?? '').' '.EmployeeName::display($e, '')
                .' '.($e->position ?? '').' '.($e->email ?? '')
            );

            return str_contains($haystack, $needle);
        })->values()->all();

        // Comparator, then a whole-array reverse for desc, as the source does.
        usort($rows, static function (EmployeeProfile $a, EmployeeProfile $b) use ($sortKey): int {
            return match ($sortKey) {
                // The id column, so CodeSort — see App\Support\CodeSort.
                'id' => CodeSort::compare($a->employee_code, $b->employee_code),
                // compareText sorts blanks last, which is what an optional
                // identifier wants: the people who have one come first.
                'employee_id_no' => EmployeeFields::compareText($a->employee_id_no, $b->employee_id_no),
                'email' => EmployeeFields::compareText($a->email, $b->email),
                // Department, then the people in the department itself
                // before each unit's, then the unit's name; a record with no
                // department sorts last. Ties fall back to the name.
                'department' => EmployeeFields::compareText($a->department_name, $b->department_name)
                    ?: (($a->unit_name !== null) <=> ($b->unit_name !== null))
                    ?: EmployeeFields::compareText($a->unit_name, $b->unit_name)
                    ?: EmployeeFields::compareText($a->last_name, $b->last_name)
                    ?: EmployeeFields::compareText($a->first_name, $b->first_name),
                // Active first, then by status label — an inactive Regular
                // belongs with the other inactive rows.
                'status' => ((int) $b->is_active <=> (int) $a->is_active)
                    ?: EmployeeFields::compareText(
                        EmployeeFields::employmentStatusLabel($a->employment_status),
                        EmployeeFields::employmentStatusLabel($b->employment_status),
                    ),
                default => EmployeeFields::compareText($a->last_name, $b->last_name)
                    ?: EmployeeFields::compareText($a->first_name, $b->first_name),
            };
        });

        return $sortDir === 'desc' ? array_reverse($rows) : $rows;
    }
}
