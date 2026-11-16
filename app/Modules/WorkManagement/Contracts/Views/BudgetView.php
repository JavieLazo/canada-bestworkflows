<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read int $version
 * @property-read int $revision
 * @property-read string $status
 * @property-read string|null $reason
 * @property-read int|null $total_minor
 * @property-read int $created_by
 * @property-read int|null $approved_by
 * @property-read CarbonImmutable|null $approved_at
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class BudgetView extends Record {}
