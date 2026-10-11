<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached inventory facts; presentation cannot query or mutate persistence.
 *
 * @property-read string|null $from_employee_name
 * @property-read string|null $to_employee_name
 * @property-read string|null $receipt_code
 * @property-read string|null $receipt_mr_number
 * @property-read string $id
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read string $item_id
 * @property-read string|null $receipt_id
 * @property-read string $event_type
 * @property-read string|null $from_employee_id
 * @property-read string|null $to_employee_id
 * @property-read string $occurred_on
 * @property-read int|null $actor_id
 * @property-read string $actor_name
 * @property-read string|null $remarks
 */
final readonly class MovementView extends Record {}
