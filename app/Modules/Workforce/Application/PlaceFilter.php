<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Filter by department" as SQL: the one rule the employee picker's
 * `place` filter and Employee Records' Department filter share. A department
 * matches its own positions and its units' positions, a unit only its own,
 * PLACE_NOT_PLACED the positions placed nowhere; an unknown id matches
 * nobody, and a record with no linked position is in no place.
 *
 * Organization owns where a position sits, so the matching position ids come
 * through its contract and the narrowing here is one `position_id IN (…)`.
 * It is applied AFTER the caller's row scope, so it only ever narrows. Before
 * `position_id` exists nobody is placed, so anything but PLACE_ALL matches
 * nobody.
 */
final class PlaceFilter
{
    /** @param Builder<EmployeeProfile> $query */
    public static function apply(Builder $query, Access $actor, string $place): void
    {
        if ($place === OperationalDirectory::PLACE_ALL) {
            return;
        }

        if (! PositionLink::ready()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $ids = [];

        foreach (app(OrganizationDirectory::class)->positionChoices($actor) as $position) {
            $matches = $place === OperationalDirectory::PLACE_NOT_PLACED
                ? $position->org_node_id === null
                : ($position->department_id === $place || $position->unit_id === $place);

            if ($matches) {
                $ids[] = $position->id;
            }
        }

        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('position_id', $ids);
    }
}
