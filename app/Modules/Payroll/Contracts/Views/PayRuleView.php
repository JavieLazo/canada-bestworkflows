<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollPayRule data: one dated Pay Basis & Rates version of one
 * scope, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $scope
 * @property-read CarbonImmutable $effective_from
 * @property-read string|null $reason
 * @property-read array<string, mixed> $params
 * @property-read int|null $created_by
 * @property-read string|null $created_by_name
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class PayRuleView extends Record {}
