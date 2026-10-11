<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Reads and writes one employee's rows on a Personal Data Sheet list
 * (PdsLists). An internal Workforce service, like EmployeeDetailRows: it
 * takes no Access, because every caller is a Workforce command that has
 * already authorised the write.
 *
 * LOCK ORDER. Every call runs INSIDE the caller's transaction, AFTER the
 * caller has locked the `employee_profiles` row, after account eligibility
 * and any structure locks but BEFORE any ordinary consistent read. That lock serialises every writer of one employee, so a row is
 * read plainly and written by its primary key: NEVER `lockForUpdate()` a
 * list row and never delete by employee (a gap lock on the employee index
 * deadlocks two first saves for different employees; EmployeeDetailRows
 * explains the trap).
 */
final class PdsRowWriter
{
    /**
     * Stores one row whose columns came from PdsLists::columns(). A list
     * whose table does not exist yet refuses with NOT_READY, which rolls the
     * caller's transaction back.
     *
     * @param  array<string, mixed>  $columns
     */
    public function insert(EmployeeProfile $employee, string $list, array $columns): Model
    {
        if (! PdsLists::ready($list)) {
            throw new RuntimeException(EmployeeDetailRows::NOT_READY);
        }

        $model = PdsLists::spec($list)['model'];

        return $model::query()->create(['employee_id' => $employee->id] + $columns);
    }

    /** One of the employee's rows by id, read plainly; another employee's id finds nothing. */
    public function find(EmployeeProfile $employee, string $list, string $id): ?Model
    {
        $model = PdsLists::spec($list)['model'];

        return $model::query()->where('employee_id', $employee->id)->whereKey($id)->first();
    }

    /** How many rows the employee holds on the list, read plainly. */
    public function count(EmployeeProfile $employee, string $list): int
    {
        $model = PdsLists::spec($list)['model'];

        return $model::query()->where('employee_id', $employee->id)->count();
    }
}
