<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollPaySchedule data: one named pay schedule, with no
 * persistence or lazy relationships. `params` is the PaySchedule shape.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read array<string, mixed> $params
 * @property-read bool $is_default
 * @property-read int|null $created_by
 * @property-read string|null $created_by_name
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read int $runs_count how many payrolls took their dates from it
 */
final readonly class PayScheduleView extends Record {}
