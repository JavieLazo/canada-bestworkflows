<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\Views\WorkSiteView as WorkSite;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Support\Access;

/**
 * The Site Settings rows, filtered and sorted in PHP exactly as the source's
 * memo does (sites-page.tsx:96-116): filter, comparator by key, then reverse
 * the whole list for descending order.
 *
 * Punch counts are the `stats` memo (sites-page.tsx:80-91) over
 * `useAttendance(1000)`: the most recent 1000 logs, scoped as GET
 * /api/attendance scopes them (EDTR, PAYROLL). DELIBERATE DIVERGENCE: the
 * list is never sorted by them — the table shows no Punches column, and the
 * counts only tell the row menu why a site in use cannot be deleted.
 */
final class SiteRows
{
    /** @return array{sites: list<WorkSite>, counts: array<string, int>} */
    public static function list(Access $access, string $search, string $geofence, string $sortKey, string $sortDir, string $status = 'ALL'): array
    {
        $counts = app(ReferenceData::class)->punchCounts($access);
        $needle = strtolower(trim($search));

        $sites = app(OrganizationDirectory::class)->sites($access)
            ->filter(static function (WorkSite $s) use ($needle, $geofence, $status): bool {
                if ($geofence === 'ENFORCED' && $s->geofence_exempt) {
                    return false;
                }

                if (($status === 'ACTIVE' && ! $s->is_active) || ($status === 'INACTIVE' && $s->is_active)) {
                    return false;
                }

                if ($geofence === 'EXEMPT' && ! $s->geofence_exempt) {
                    return false;
                }

                if ($needle === '') {
                    return true;
                }

                return str_contains(strtolower($s->name), $needle);
            })
            ->values()
            ->all();

        usort($sites, static function (WorkSite $a, WorkSite $b) use ($sortKey): int {
            return match ($sortKey) {
                'radius' => ($a->radius_meters <=> $b->radius_meters) ?: Hris::compareText($a->name, $b->name),
                default => Hris::compareText($a->name, $b->name),
            };
        });

        if ($sortDir === 'desc') {
            $sites = array_reverse($sites);
        }

        return ['sites' => $sites, 'counts' => $counts];
    }
}
