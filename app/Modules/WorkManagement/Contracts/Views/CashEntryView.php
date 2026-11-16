<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read int $allocation_revision
 * @property-read string $project_id
 * @property-read string $category_id
 * @property-read string|null $group_id
 * @property-read string $category_label
 * @property-read string|null $group_label
 * @property-read string $kind
 * @property-read CarbonImmutable $occurred_on
 * @property-read int $amount_minor
 * @property-read string $description
 * @property-read string|null $party
 * @property-read string|null $reference
 * @property-read string|null $notes
 * @property-read int $created_by
 * @property-read string|null $replaces_id
 * @property-read int|null $voided_by
 * @property-read CarbonImmutable|null $voided_at
 * @property-read string|null $correction_reason
 * @property-read string|null $void_reason
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class CashEntryView extends Record {}
