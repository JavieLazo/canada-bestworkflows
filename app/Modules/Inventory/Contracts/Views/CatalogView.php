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
 * @property-read string $name
 * @property-read int $item_count
 */
final readonly class CatalogView extends Record {}
