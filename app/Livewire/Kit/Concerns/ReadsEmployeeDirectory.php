<?php

declare(strict_types=1);

namespace App\Livewire\Kit\Concerns;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Kit\EmployeeChecklist;
use App\Livewire\Kit\EmployeePicker;
use App\Models\EmployeeProfile;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a kit employee control may list — shared by the single-select
 * {@see EmployeePicker} and the multi-select
 * {@see EmployeeChecklist}, so the two can never disagree
 * about visibility.
 *
 * The three read tiers of GET /api/employees (api/employees/route.ts:38-70),
 * collapsed to what a picker needs:
 *
 *   full directory          mayReadAllData(EMPLOYEES, PAYROLL)
 *   operational directory   mayReadAllData(EDTR, ROSTER, LEAVES, PROJECTS, TASKS)
 *                           (the controls show only operational-directory
 *                           columns, so the reduced tier loses nothing here)
 *   otherwise               the actor's OWN row — or NO rows for an account
 *                           with no employee link, never every row
 *
 * The actor is re-read from the session EVERY request — never cached on a
 * property, never taken from the client. Every id a control accepts from the
 * browser is re-resolved through this query before it is stored.
 */
trait ReadsEmployeeDirectory
{
    /**
     * The scoped directory, ordered the way the source returned it
     * (orderBy last_name asc, api/employees/route.ts:41).
     *
     * @return Builder<EmployeeProfile>
     */
    protected function directoryQuery(): Builder
    {
        $actor = Access::forActor(auth()->user());

        $query = EmployeeProfile::query()
            ->orderBy('last_name')
            ->orderBy('first_name');

        if (
            $actor->mayReadAllData(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)
            || $actor->mayReadAllData(
                ModuleCode::EDTR,
                ModuleCode::ROSTER,
                ModuleCode::LEAVES,
                ModuleCode::PROJECTS,
                ModuleCode::TASKS,
            )
        ) {
            return $query;
        }

        // Own row only — or, for an account with no employee link, NO rows.
        // NOT a where(column, null), which would be `IS NULL` (see
        // App\Models\Concerns\ScopesToActor for the full story).
        return $actor->hasEmployeeLink()
            ? $query->whereKey($actor->ownEmployeeId())
            : $query->whereRaw('1 = 0');
    }

    /**
     * Port of `matches` (EmployeePicker.tsx:40-49): every whitespace-
     * separated term has to match somewhere in code, name or position, so
     * "bwf garcia" finds the Garcia with that code.
     *
     * @param  Builder<EmployeeProfile>  $query
     * @return Builder<EmployeeProfile>
     */
    protected function applyDirectorySearch(Builder $query, string $search): Builder
    {
        $terms = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($terms as $term) {
            $like = '%'.addcslashes($term, '\\%_').'%';

            $query->where(function (Builder $q) use ($like): void {
                $q->where('employee_code', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('position', 'like', $like);
            });
        }

        return $query;
    }
}
