<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayslipIncentive data: one incentive or bonus line a posted
 * payslip paid, as it was paid. `over_ceiling` is the part of a tax-exempt
 * line taxed because it went over the PHP 90,000 yearly ceiling.
 *
 * @property-read string $id
 * @property-read string $payslip_id
 * @property-read int $position
 * @property-read string $type_code
 * @property-read string $name
 * @property-read bool $is_taxable
 * @property-read float $amount
 * @property-read float $over_ceiling
 * @property-read CarbonImmutable $created_at
 */
final readonly class PayslipIncentiveView extends Record {}
