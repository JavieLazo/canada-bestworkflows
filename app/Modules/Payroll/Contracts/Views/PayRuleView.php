<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollPayRule data: one dated Pay Basis & Rates version of one
 * scope, with no persistence or lazy relationships. `params` is null on an
 * employee's "follows" version: from that date they follow their status's
 * or the company's rules again. `status` is DRAFT or APPROVED (null on an
 * installation migrated before approval existed: in force); only an
 * approved version prices anyone.
 *
 * @property-read string $id
 * @property-read string $scope
 * @property-read string|null $scope_type
 * @property-read CarbonImmutable $effective_from
 * @property-read string|null $reason
 * @property-read array<string, mixed>|null $params
 * @property-read string|null $status
 * @property-read int|null $created_by
 * @property-read string|null $created_by_name
 * @property-read int|null $approved_by
 * @property-read string|null $approved_by_name
 * @property-read CarbonImmutable|null $approved_at
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class PayRuleView extends Record {}
