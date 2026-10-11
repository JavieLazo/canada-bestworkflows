<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached Holiday data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read CarbonImmutable $date
 * @property-read string $schedule
 * @property-read string $type
 * @property-read float $percent
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class HolidayView extends Record {}
