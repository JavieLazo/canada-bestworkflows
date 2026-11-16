<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $entity_type
 * @property-read string $name
 * @property-read string $type
 * @property-read string|null $unit
 * @property-read string $visibility
 * @property-read bool $required
 * @property-read bool $total
 * @property-read bool $archived
 * @property-read array<string, mixed>|null $options
 * @property-read int $revision
 * @property-read array<int, array<string, mixed>>|null $history
 * @property-read int $created_by
 * @property-read int $updated_by
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class ReportFieldView extends Record {}
