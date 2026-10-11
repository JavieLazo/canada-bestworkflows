<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Modules\Organization\Contracts\Views\WorkSiteView;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached AttendanceLog data, with no persistence or lazy relationships.
 *
 * @property-read DirectoryEmployee|null $employee
 * @property-read WorkSiteView|null $site
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string|null $site_id
 * @property-read string $location_kind ONSITE | WFH — "Work from home" in a feed is this, never a null site
 * @property-read string $log_type
 * @property-read string $source
 * @property-read bool $hand_entered Manual punches must not present the kiosk's coordinates as a captured employee location
 * @property-read float|null $latitude
 * @property-read float|null $longitude
 * @property-read float|null $distance_meters
 * @property-read float|null $accuracy_meters
 * @property-read bool $is_geofenced
 * @property-read bool $is_anomaly
 * @property-read string|null $anomaly_note
 * @property-read CarbonImmutable $logged_at
 */
final readonly class AttendanceLogView extends Record {}
