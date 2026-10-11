<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;

/**
 * Detached inventory facts; presentation cannot query or mutate persistence.
 *
 * @property-read string $id
 * @property-read string|null $created_at
 * @property-read string|null $updated_at
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $serial_no
 * @property-read string $acquired_on
 * @property-read float $cost
 * @property-read string|null $category_id
 * @property-read string|null $location_id
 * @property-read string|null $category_name
 * @property-read string|null $location_name
 * @property-read string $condition
 * @property-read string $status
 * @property-read string|null $employee_id
 * @property-read string|null $employee_name
 * @property-read string|null $employee_position
 * @property-read string|null $employee_department
 * @property-read string|null $employee_unit
 * @property-read string|null $current_receipt_id
 * @property-read string|null $current_receipt_code
 * @property-read string|null $current_receipt_mr_number
 * @property-read string|null $current_receipt_issued_on
 * @property-read string|null $remarks
 */
final readonly class ItemView extends Record {}
