<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached weekday hours, including the saved name when a pattern is deleted.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read int $weekday
 * @property-read string|null $shift_type_id
 * @property-read string $shift_name
 * @property-read bool $is_rest_day
 * @property-read array<string, mixed> $shift_data
 * @property-read CarbonImmutable|null $effective_from  null = since before history was kept
 * @property-read string|null $change_id
 */
final readonly class RosterShiftView extends Record {}
