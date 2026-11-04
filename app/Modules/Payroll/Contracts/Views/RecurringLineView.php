<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollRecurringLine data, with no persistence or lazy
 * relationships: one employee's recurring deduction or incentive, the
 * catalog row it charges (`type_id`, `type_code`, `type_name`), what is
 * already paid against it on posted payslips (`paid`), what its total has
 * left (`left`, null when open-ended), whether anything was posted against
 * it (`posted`: it can then only be ended) and its `status` today —
 * UPCOMING, ACTIVE, PAID_OFF or ENDED.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string $kind
 * @property-read string|null $deduction_id
 * @property-read string|null $incentive_type_id
 * @property-read string $type_id
 * @property-read string $type_code
 * @property-read string $type_name
 * @property-read float $amount
 * @property-read float|null $total
 * @property-read string $frequency
 * @property-read CarbonImmutable $starts_on
 * @property-read CarbonImmutable|null $ends_on
 * @property-read string|null $note
 * @property-read float $paid
 * @property-read float|null $left
 * @property-read bool $posted
 * @property-read string $status
 * @property-read string|null $created_by_name
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class RecurringLineView extends Record {}
