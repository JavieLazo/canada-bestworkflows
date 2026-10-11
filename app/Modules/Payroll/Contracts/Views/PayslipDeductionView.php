<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * A named deduction copied onto a posted payslip, even if its catalog row changes.
 *
 * @property-read string $id
 * @property-read string $payslip_id
 * @property-read int $position
 * @property-read string $deduction_code
 * @property-read string $name
 * @property-read float $amount
 * @property-read float|null $employer_amount
 * @property-read CarbonImmutable $created_at
 */
final readonly class PayslipDeductionView extends Record {}
