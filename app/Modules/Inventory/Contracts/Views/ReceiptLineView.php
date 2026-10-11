<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached inventory facts; presentation cannot query or mutate persistence.
 *
 * @property-read string $id
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read string $receipt_id
 * @property-read string $item_id
 * @property-read string $status
 * @property-read array<string,mixed> $item_snapshot
 * @property-read string|null $ended_on
 */
final readonly class ReceiptLineView extends Record {}
