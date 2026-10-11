<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure;

use App\Models\WorkSite;
use App\Modules\Organization\Contracts\PunchSite;
use App\Modules\Organization\Contracts\PunchSites;

/** Keeps geofence persistence inside Organization. */
final class PunchSiteQuery implements PunchSites
{
    public function find(string $id): ?PunchSite
    {
        $row = WorkSite::query()->find($id);

        return $row === null ? null : new PunchSite($row->id, $row->name, $row->latitude, $row->longitude, $row->radius_meters, $row->geofence_exempt);
    }
}
