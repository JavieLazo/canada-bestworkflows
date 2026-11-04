<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollEmployeeBenefit data: one benefit assigned to one
 * employee for a span of dates, with the type's name copied beside it,
 * and no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string $type_id
 * @property-read string $type_name
 * @property-read CarbonImmutable $starts_on
 * @property-read CarbonImmutable|null $ends_on
 * @property-read float|null $amount
 * @property-read string $reason
 * @property-read string|null $reference
 * @property-read string $status
 * @property-read int|null $prepared_by
 * @property-read string|null $prepared_by_name
 * @property-read int|null $approved_by
 * @property-read string|null $approved_by_name
 * @property-read CarbonImmutable|null $approved_at
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class BenefitAssignmentView extends Record {}
