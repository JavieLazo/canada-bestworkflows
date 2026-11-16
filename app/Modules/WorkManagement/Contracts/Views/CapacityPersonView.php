<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * @property-read string $employee_id
 * @property-read string $name
 * @property-read array<string, array{planned: int, available: int|null, shared: bool}> $weeks
 */
final readonly class CapacityPersonView extends Record {}
