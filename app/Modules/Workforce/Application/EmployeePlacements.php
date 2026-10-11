<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use App\Modules\Organization\Contracts\OrganizationReferences;
use Illuminate\Support\Collection;

/**
 * Where each employee's position sits — its department, and its unit when it
 * is placed in one — attached to the record views beside the stored title.
 * Organization owns the structure, so the names come through its contract
 * in ONE batched placements() call for the whole set of records, never one
 * per employee.
 *
 * Every view gets the same keys (PLACEMENT_KEYS): all NULL for a record with
 * no linked position, and for every record while `position_id` does not
 * exist yet (PositionLink, asked once per request), so a page renders before
 * the release's migration has run. `position_unlisted` is true only for a
 * stored title that is not linked — a title typed before the Position list
 * (the record shows "… is not on the Position list.") — and never before the
 * migration, when nothing could be linked.
 */
final class EmployeePlacements
{
    /** @var list<string> */
    public const array PLACEMENT_KEYS = ['department_id', 'department_name', 'unit_id', 'unit_name', 'position_unlisted'];

    /** Whether records can be linked yet: the `position_id` column exists. */
    public static function ready(): bool
    {
        return PositionLink::ready();
    }

    /**
     * The placement keys for each row, keyed by employee id.
     *
     * @param  Collection<int, EmployeeProfile>  $rows
     * @return array<string, array<string, mixed>>
     */
    public static function of(Collection $rows): array
    {
        $ready = self::ready();
        $placements = [];

        if ($ready) {
            $ids = [];

            foreach ($rows as $row) {
                $id = $row->getAttribute('position_id');

                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }

            $placements = $ids === [] ? [] : app(OrganizationReferences::class)->placements(array_values(array_unique($ids)));
        }

        $extras = [];

        foreach ($rows as $row) {
            $positionId = $ready ? $row->getAttribute('position_id') : null;
            $placement = is_string($positionId) ? ($placements[$positionId] ?? null) : null;
            $title = $row->getAttribute('position');

            $extras[(string) $row->getKey()] = [
                'department_id' => $placement?->department_id,
                'department_name' => $placement?->department_name,
                'unit_id' => $placement?->unit_id,
                'unit_name' => $placement?->unit_name,
                'position_unlisted' => $ready && $positionId === null && is_string($title) && trim($title) !== '',
            ];
        }

        return $extras;
    }
}
