<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/** Internal site facts; the calling operation must authorize the employee first. */
interface PunchSites
{
    public function find(string $id): ?PunchSite;
}
