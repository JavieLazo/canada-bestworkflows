<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached LeaveType data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $description
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class LeaveTypeView extends Record {}
