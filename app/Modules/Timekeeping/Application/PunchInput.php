<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

/** Raw station input; no authorization or derived geofence verdict is accepted from the UI. */
final readonly class PunchInput
{
    public function __construct(public string $employeeId, public string $siteId, public string $source, public bool $selfService, public string $lat, public string $lng) {}
}
