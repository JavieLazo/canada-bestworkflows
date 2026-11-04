<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollGovDeductionPolicy data: one dated Contributions & tax
 * version of one employee, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read CarbonImmutable $effective_from
 * @property-read array<string, mixed> $params
 * @property-read string $reason
 * @property-read string $status
 * @property-read int|null $prepared_by
 * @property-read string|null $prepared_by_name
 * @property-read int|null $approved_by
 * @property-read string|null $approved_by_name
 * @property-read CarbonImmutable|null $approved_at
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class GovDeductionPolicyView extends Record {}
