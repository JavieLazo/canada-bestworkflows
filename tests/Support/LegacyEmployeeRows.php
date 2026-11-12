<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Kit\Support\DepartmentFilter;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView as EmployeeProfile;
use App\Support\CodeSort;
use App\Support\EmployeeName;
use Illuminate\Support\Collection;

/**
 * The Employee Records rows as the table built them in PHP before it paged
 * in SQL — the filter and the comparator of employees-page.tsx:133-177 over
 * the whole scoped list, byte for byte, with the department rule inlined.
 * Tests keep it as the oracle `EmployeeRecords::page()` and `arranged()`
 * must reproduce for every filter, sort and direction. usort is stable, so
 * the order of `$employees` decides ties: hand it the rows in the SQL
 * tiebreak (last name, first name, key) and the two orders must be equal.
 */
final class LegacyEmployeeRows
{
    /**
     * @param  Collection<int, EmployeeProfile>  $employees
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

            if (! self::inDepartment($e, $department)) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            $haystack = mb_strtolower(
                "{$e->employee_code} ".($e->employee_id_no ?? '').' '.EmployeeName::display($e, '')
                .' '.($e->position ?? '').' '.($e->email ?? '')
            );

            return str_contains($haystack, $needle);
        })->values()->all();

        usort($rows, static function (EmployeeProfile $a, EmployeeProfile $b) use ($sortKey): int {
            return match ($sortKey) {
                'id' => CodeSort::compare($a->employee_code, $b->employee_code),
                'employee_id_no' => EmployeeFields::compareText($a->employee_id_no, $b->employee_id_no),
                'email' => EmployeeFields::compareText($a->email, $b->email),
                'department' => EmployeeFields::compareText($a->department_name, $b->department_name)
                    ?: (($a->unit_name !== null) <=> ($b->unit_name !== null))
                    ?: EmployeeFields::compareText($a->unit_name, $b->unit_name)
                    ?: EmployeeFields::compareText($a->last_name, $b->last_name)
                    ?: EmployeeFields::compareText($a->first_name, $b->first_name),
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

    /** The department rule the table applied to each view (DepartmentFilter::matches). */
    private static function inDepartment(EmployeeProfile $employee, string $choice): bool
    {
        if ($choice === DepartmentFilter::ALL) {
            return true;
        }

        if (! is_string($employee->position_id) || $employee->position_id === '') {
            return false;
        }

        if ($choice === DepartmentFilter::NOT_PLACED) {
            return $employee->department_id === null;
        }

        return $employee->department_id === $choice || $employee->unit_id === $choice;
    }
}
