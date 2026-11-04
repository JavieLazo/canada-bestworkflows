<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollBenefitCeiling data: one dated version of the yearly
 * tax-free ceiling, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read CarbonImmutable $effective_from
 * @property-read float $ceiling
 * @property-read string $reason
 * @property-read int|null $created_by
 * @property-read string|null $created_by_name
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class BenefitCeilingView extends Record {}
