<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string|null $code
 * @property-read string $name
 * @property-read string $kind
 * @property-read string|null $description
 * @property-read array{sections: list<string>, fields: list<string>} $definition
 * @property-read bool $work
 * @property-read bool $financial
 * @property-read bool $archived
 * @property-read int $revision
 * @property-read array<int, array<string, mixed>>|null $history
 * @property-read int $created_by
 * @property-read int $updated_by
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class ReportTemplateView extends Record {}
