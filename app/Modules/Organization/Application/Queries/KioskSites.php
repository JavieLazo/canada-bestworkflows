<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\WorkSite;
use App\Support\Access;

/**
 * The sites a biometric device can be set up at. A device does not find its
 * own position: whoever sets it up picks the site it stands at from this
 * list, and the device sends that site, with the site's own pin, on every
 * punch. Listing them is part of the kiosk operation, so it needs what
 * punching for someone else needs: eDTR with all data. ONLY SITES IN USE ARE
 * OFFERED — a retired site stays on the records that name it and takes no
 * new device.
 *
 * @phpstan-type KioskSite array{id: string, name: string, latitude: float, longitude: float, radius_meters: int, geofence_exempt: bool}
 */
final class KioskSites
{
    /** @return list<KioskSite> */
    public function all(Access $actor): array
    {
        if (! $actor->mayReadAllData(ModuleCode::EDTR)) {
            return [];
        }

        $sites = [];
        foreach (WorkSite::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get() as $site) {
            $sites[] = [
                'id' => (string) $site->id,
                'name' => $site->name,
                'latitude' => $site->latitude,
                'longitude' => $site->longitude,
                'radius_meters' => $site->radius_meters,
                'geofence_exempt' => $site->geofence_exempt,
            ];
        }

        return $sites;
    }
}
