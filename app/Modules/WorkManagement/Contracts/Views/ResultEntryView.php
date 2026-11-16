<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $indicator_id
 * @property-read int $plan_version
 * @property-read string $occurred_on
 * @property-read int|null $value_scaled
 * @property-read string|null $value_text
 * @property-read string|null $state
 * @property-read string|null $narrative
 * @property-read string|null $issues
 * @property-read string|null $corrective_action
 * @property-read string|null $reference
 * @property-read int $created_by
 * @property-read string|null $replaces_id
 * @property-read CarbonImmutable|null $voided_at
 * @property-read int|null $voided_by
 * @property-read string|null $void_reason
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class ResultEntryView extends Record {}
