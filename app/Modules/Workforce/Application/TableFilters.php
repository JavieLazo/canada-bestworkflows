<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use App\Platform\ReadModels\SqlText;
use Illuminate\Database\Eloquent\Builder;

/**
 * The status and job-title filters every employee TABLE applies — Employee
 * Records and the Shift Roster (employees-page.tsx:133-145,
 * roster-page.tsx:88-98) — as SQL, so a list can page in the database and
 * still keep exactly the rows the PHP filter kept. Applied after the
 * caller's row scope; they only narrow.
 */
final class TableFilters
{
    /**
     * INACTIVE keeps separated staff; a status name keeps the records whose
     * status has the same `statusKey`, active or not; ALL keeps everyone. A
     * key folds legacy tokens and spelling (JOB_ORDER, "Job order"), which
     * SQL cannot, so the stored values it matches are found first and then
     * matched exactly.
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    public static function status(Builder $query, string $status): void
    {
        if ($status === 'ALL') {
            return;
        }

        if ($status === 'INACTIVE') {
            $query->where('is_active', false);

            return;
        }

        $key = EmployeeFields::statusKey($status);
        $matching = array_values(array_filter(
            SqlText::distinctValues(EmployeeProfile::query(), 'employment_status'),
            static fn (string $value): bool => EmployeeFields::statusKey($value) === $key,
        ));
        SqlText::whereExactIn($query, 'employment_status', $matching);
    }

    /**
     * The stored job title, exactly as PHP compares it (`($e->position ?? '')
     * !== $position`): '' keeps the records with no title; ALL keeps everyone.
     *
     * @param  Builder<EmployeeProfile>  $query
     */
    public static function position(Builder $query, string $position): void
    {
        if ($position === 'ALL') {
            return;
        }

        if ($position === '') {
            $query->where(static function (Builder $row): void {
                $row->whereNull('position')->orWhere(static fn (Builder $blank) => SqlText::whereExactIn($blank, 'position', ['']));
            });

            return;
        }

        SqlText::whereExactIn($query, 'position', [$position]);
    }
}
