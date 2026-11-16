<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string|null $description
 * @property-read int $owner_id
 * @property-read bool $can_manage
 * @property-read int $people_count
 * @property-read CarbonImmutable|null $archived_at
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class PortfolioView extends Record {}
