<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $contribution_id
 * @property-read string $occurred_on
 * @property-read int|null $quantity_scaled
 * @property-read int|null $value_minor
 * @property-read string|null $valuation_basis
 * @property-read string|null $reference
 * @property-read string|null $notes
 * @property-read string|null $replaces_id
 * @property-read CarbonImmutable|null $voided_at
 * @property-read int|null $voided_by
 * @property-read string|null $void_reason
 * @property-read int $created_by
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class ContributionDeliveryView extends Record {}
