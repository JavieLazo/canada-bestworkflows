<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string|null $period_id
 * @property-read string|null $period_label
 * @property-read string|null $period_starts_on
 * @property-read string|null $period_ends_on
 * @property-read string|null $funding_id
 * @property-read string|null $contribution_id
 * @property-read string|null $source_label
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $budget_id
 * @property-read string $category_id
 * @property-read string|null $group_id
 * @property-read string $category_label
 * @property-read string|null $group_label
 * @property-read string $label
 * @property-read string $basis
 * @property-read int|null $quantity_scaled
 * @property-read int|null $frequency
 * @property-read int|null $unit_minor
 * @property-read int $total_minor
 * @property-read string|null $notes
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class BudgetLineView extends Record {}
