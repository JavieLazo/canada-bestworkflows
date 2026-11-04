<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached EmployeeSalaryRate data: one dated salary version of one
 * employee, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read CarbonImmutable $effective_from
 * @property-read float|null $monthly_rate
 * @property-read float|null $daily_rate
 * @property-read string $reason
 * @property-read string|null $reference
 * @property-read string $status
 * @property-read int|null $prepared_by
 * @property-read string|null $prepared_by_name
 * @property-read int|null $approved_by
 * @property-read string|null $approved_by_name
 * @property-read CarbonImmutable|null $approved_at
 * @property-read string|null $review_note
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class SalaryRateView extends Record {}
