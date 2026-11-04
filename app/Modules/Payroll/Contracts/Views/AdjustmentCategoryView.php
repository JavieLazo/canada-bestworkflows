<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollAdjustmentCategory data, with no persistence or lazy
 * relationships. `adjustments_count` is how many lines were made under it.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read string $kind
 * @property-read string|null $tax
 * @property-read int|null $created_by
 * @property-read string|null $created_by_name
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read int $adjustments_count
 */
final readonly class AdjustmentCategoryView extends Record {}
