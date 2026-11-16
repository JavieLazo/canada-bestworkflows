<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $funding_id
 * @property-read string $name
 * @property-read int $planned_minor
 * @property-read string|null $planned_on
 * @property-read string|null $conditions
 * @property-read string $status
 * @property-read int $revision
 * @property-read array<int, array<string, mixed>>|null $history
 * @property-read int $created_by
 * @property-read int $updated_by
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read int|null $received_minor
 * @property-read int|null $received_quantity
 */
final readonly class FundingInstallmentView extends Record {}
