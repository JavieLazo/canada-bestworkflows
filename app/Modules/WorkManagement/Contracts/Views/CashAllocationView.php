<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $entry_id
 * @property-read int $revision
 * @property-read string $source_kind
 * @property-read string|null $source_id
 * @property-read string|null $source_label
 * @property-read string|null $installment_id
 * @property-read string|null $installment_label
 * @property-read string|null $group_id
 * @property-read string|null $group_label
 * @property-read int $amount_minor
 * @property-read string|null $reason
 * @property-read int $created_by
 * @property-read CarbonImmutable $created_at
 */
final readonly class CashAllocationView extends Record {}
