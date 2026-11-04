<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * One day's attendance a posted payroll charged to leave credits, as Leave
 * Management reads it.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string|null $payslip_id
 * @property-read CarbonImmutable $date
 * @property-read string $kind  LATE | ABSENCE
 * @property-read int|null $minutes
 * @property-read float $days
 * @property-read list<array{type: string, days: float, entry_id: ?string}> $covered
 * @property-read string $covered_text  "Vacation Leave 0.0625, Sick Leave 0.5"
 * @property-read float $uncovered_days
 * @property-read CarbonImmutable $created_at
 */
final readonly class LeaveChargeView extends Record {}
