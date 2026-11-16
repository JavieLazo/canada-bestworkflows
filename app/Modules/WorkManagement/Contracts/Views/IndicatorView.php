<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string|null $outcome_id
 * @property-read string|null $group_id
 * @property-read string|null $task_id
 * @property-read string|null $outcome_label
 * @property-read string|null $work_label
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string $kind
 * @property-read string|null $unit
 * @property-read string $aggregation
 * @property-read string $direction
 * @property-read int|null $target_scaled
 * @property-read string|null $target_text
 * @property-read string|null $starts_on
 * @property-read string|null $ends_on
 * @property-read bool $evidence_required
 * @property-read string $status
 * @property-read int $revision
 * @property-read int $plan_version
 * @property-read array<int, array<string, mixed>>|null $history
 * @property-read int $created_by
 * @property-read int $updated_by
 * @property-read string|null $last_recorded_on
 * @property-read string|null $latest_entry_id
 * @property-read int|null $actual_scaled
 * @property-read string|null $actual_text
 * @property-read string|null $actual_state
 * @property-read int|null $entry_count
 * @property-read int|null $evidence_count
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class IndicatorView extends Record {}
