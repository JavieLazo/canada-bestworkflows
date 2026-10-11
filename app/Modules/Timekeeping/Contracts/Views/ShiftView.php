<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached Shift data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string|null $shift_type_id
 * @property-read string $mode
 * @property-read float|null $required_hours
 * @property-read list<int> $work_days
 * @property-read string $start_time
 * @property-read string $end_time
 * @property-read int $grace_minutes
 * @property-read string|null $break_start
 * @property-read string|null $break_end
 * @property-read int $break_minutes
 * @property-read bool $requires_break_punch
 * @property-read CarbonImmutable|null $effective_from  null = since before history was kept
 * @property-read string|null $change_id
 */
final readonly class ShiftView extends Record {}
