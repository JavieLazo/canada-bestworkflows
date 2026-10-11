<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached inventory evidence for authorized presentation.
 *
 * @property-read string $id
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read string $item_id
 * @property-read string|null $employee_id
 * @property-read string|null $receipt_id
 * @property-read string $kind
 * @property-read CarbonImmutable $reported_on
 * @property-read string $reference
 * @property-read string $details
 * @property-read array<string, mixed> $item_snapshot
 * @property-read array<string, mixed>|null $employee_snapshot
 * @property-read string $status
 * @property-read string|null $resolution
 * @property-read string|null $resolution_reference
 * @property-read int $actor_id
 * @property-read string $actor_name
 * @property-read string|null $actor_employee_id
 * @property-read int|null $reviewed_by
 * @property-read string|null $reviewer_name
 * @property-read CarbonImmutable|null $resolved_at
 */
final readonly class IncidentView extends Record {}
