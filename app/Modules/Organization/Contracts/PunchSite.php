<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/** Immutable geofence facts used after employee authorization. */
final readonly class PunchSite
{
    public function __construct(public string $id, public string $name, public float $latitude, public float $longitude, public int $radius_meters, public bool $geofence_exempt) {}
}
