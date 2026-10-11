<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollIncentiveType data, with no persistence or lazy
 * relationships: one incentive or bonus type from the catalog under Records →
 * Payroll Settings.
 *
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $description
 * @property-read bool $is_taxable
 * @property-read string|null $amount_type
 * @property-read float|null $amount
 * @property-read string|null $frequency
 * @property-read int|null $month
 * @property-read list<string>|null $statuses
 * @property-read int|null $min_service_months
 * @property-read string|null $proration
 * @property-read string|null $tax
 * @property-read float|null $cap
 * @property-read string|null $approval_reference
 * @property-read bool|null $counts_toward_13th
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class IncentiveTypeView extends Record {}
