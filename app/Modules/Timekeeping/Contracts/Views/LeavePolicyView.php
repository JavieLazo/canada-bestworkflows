<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * One version of an employee's leave policy as Leave Management reads it.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read CarbonImmutable $effective_from
 * @property-read array<string, mixed> $params  LeavePolicy params
 * @property-read string $summary  LeavePolicy::describe()
 * @property-read string $reason
 * @property-read string $status  DRAFT | APPROVED
 * @property-read bool $in_force  the approved version in force today
 * @property-read int|null $prepared_by
 * @property-read string|null $prepared_by_name
 * @property-read string|null $approved_by_name
 * @property-read CarbonImmutable|null $approved_at
 * @property-read CarbonImmutable $created_at
 */
final readonly class LeavePolicyView extends Record {}
