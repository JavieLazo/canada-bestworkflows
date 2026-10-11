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
 * @property-read CarbonImmutable $disposed_on
 * @property-read string $method
 * @property-read string $reason
 * @property-read string $authorization_reference
 * @property-read string $authorized_by_employee_id
 * @property-read array<string, mixed> $authorized_by_snapshot
 * @property-read array<string, mixed> $item_snapshot
 * @property-read int $actor_id
 * @property-read string $actor_name
 */
final readonly class DisposalView extends Record {}
