<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached WorkSite data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read float $latitude
 * @property-read float $longitude
 * @property-read int $radius_meters
 * @property-read bool $geofence_exempt
 * @property-read bool $is_active  false once retired: kept on records, withheld from new assignments
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class WorkSiteView extends Record {}
