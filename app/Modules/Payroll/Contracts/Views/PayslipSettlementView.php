<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * One settlement a posted payslip made of an earlier payslip's presumed
 * days (the advance payroll, PayrollAdvance): the days, what was presumed,
 * what they turned out to be worth and the difference already inside the
 * payslip's gross pay. `details` itemises both figures by line.
 *
 * @property-read string $id
 * @property-read string $payslip_id
 * @property-read string $settled_payslip_id
 * @property-read string $employee_id
 * @property-read string $settled_code
 * @property-read CarbonImmutable $from_date
 * @property-read CarbonImmutable $to_date
 * @property-read float $presumed_amount
 * @property-read float $actual_amount
 * @property-read float $amount
 * @property-read array<string, array<string, float>> $details  `presumed` and `actual`, each line by line
 * @property-read CarbonImmutable $created_at
 */
final readonly class PayslipSettlementView extends Record {}
