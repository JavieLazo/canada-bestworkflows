<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached ShiftType data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string $mode
 * @property-read list<int> $work_days
 * @property-read string|null $start_time
 * @property-read string|null $end_time
 * @property-read int $grace_minutes
 * @property-read float|null $required_hours
 * @property-read string|null $break_start
 * @property-read string|null $break_end
 * @property-read int $break_minutes
 * @property-read bool $requires_break_punch
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class ShiftTypeView extends Record {}
