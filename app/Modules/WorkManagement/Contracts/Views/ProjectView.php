<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached Project data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string|null $site_id
 * @property-read string $status
 * @property-read CarbonImmutable $start_date
 * @property-read CarbonImmutable|null $due_date
 * @property-read int|null $manager_id
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class ProjectView extends Record {}
