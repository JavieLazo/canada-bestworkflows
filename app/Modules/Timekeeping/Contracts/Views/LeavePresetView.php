<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * A leave policy preset as the settings page reads it.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read array<string, mixed> $params  LeavePolicy params
 * @property-read string $summary  LeavePolicy::describe()
 * @property-read string|null $created_by_name
 * @property-read CarbonImmutable $created_at
 */
final readonly class LeavePresetView extends Record {}
