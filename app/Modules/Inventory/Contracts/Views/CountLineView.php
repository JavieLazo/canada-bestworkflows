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
 * @property-read string $count_id
 * @property-read string $item_id
 * @property-read string|null $employee_id
 * @property-read string|null $receipt_id
 * @property-read array<string, mixed> $item_snapshot
 * @property-read string $result
 * @property-read string|null $notes
 */
final readonly class CountLineView extends Record {}
