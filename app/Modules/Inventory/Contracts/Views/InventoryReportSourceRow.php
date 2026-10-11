<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/** Intermediate detached report facts retain native dates until the report formats scalar cells.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string $status
 * @property-read string $condition
 * @property-read float $cost
 * @property-read string|null $employee_id
 * @property-read string|null $category_id
 * @property-read string|null $location_id
 * @property-read string|null $current_receipt_id
 * @property-read string $item_id
 * @property-read string|null $receipt_id
 * @property-read string|null $from_employee_id
 * @property-read string|null $to_employee_id
 * @property-read CarbonImmutable $occurred_on
 * @property-read string $event_type
 * @property-read string $actor_name
 * @property-read string|null $remarks
 */
final readonly class InventoryReportSourceRow extends Record {}
