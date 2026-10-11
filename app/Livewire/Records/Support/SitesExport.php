<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Organization\Application\Commands\ImportSites;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/**
 * Site Settings as CSV: the sites the table shows, searched, filtered by
 * geofence and sorted as on screen ({@see SiteRows}). Coordinates and
 * radius are written as plain numbers — even for an exempt site, whose cell
 * the table blanks — because the file is also the import template
 * ({@see ImportSites}). Punches is read-only.
 */
final class SitesExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $search = $query['q'] ?? '';
        $geofence = in_array($query['geofence'] ?? '', ['ENFORCED', 'EXEMPT'], true) ? (string) $query['geofence'] : 'ALL';
        $sort = in_array($query['sort'] ?? '', ['name', 'radius', 'punches'], true) ? (string) $query['sort'] : 'name';
        $direction = ($query['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $status = in_array($query['status'] ?? '', ['ACTIVE', 'INACTIVE'], true) ? (string) $query['status'] : 'ALL';

        $rows = static function () use ($actor, $search, $geofence, $sort, $direction, $status): Generator {
            ['sites' => $sites, 'counts' => $counts] = SiteRows::list($actor, $search, $geofence, $sort, $direction, $status);
            foreach ($sites as $site) {
                yield [
                    (string) $site->getKey(), $site->name, $site->latitude, $site->longitude, $site->radius_meters,
                    $site->geofence_exempt ? 'Exempt' : 'Enforced', $counts[(string) $site->getKey()] ?? 0,
                    $site->is_active ? 'Active' : 'Inactive',
                ];
            }
        };

        return new CsvExport('sites-'.ManilaClock::todayKey().'.csv', [...ImportSites::COLUMNS, 'Punches', 'Status'], $rows);
    }
}
