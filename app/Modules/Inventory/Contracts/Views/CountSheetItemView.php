<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached current facts used to detect a stale physical-count sheet.
 *
 * @property-read string $id
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $employee_id
 * @property-read string|null $current_receipt_id
 * @property-read string|null $receipt_id
 * @property-read string $employee_name
 * @property-read string $receipt_code
 * @property-read string $receipt_mr_number
 * @property-read string|null $location_id
 * @property-read string $condition
 * @property-read string $version
 */
final readonly class CountSheetItemView extends Record {}
