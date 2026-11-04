<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollAdjustment data with its category's name, kind and tax
 * and the employee's display name, with no persistence or lazy
 * relationships. `target_run_code` names the open payroll the approver
 * picked, `payslip_run_code` the payroll that posted it.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string $employee_name
 * @property-read string $employee_code
 * @property-read string $category_id
 * @property-read string $category_name
 * @property-read string $kind
 * @property-read string|null $tax
 * @property-read float $amount
 * @property-read string $reason
 * @property-read string|null $reference
 * @property-read string $source
 * @property-read string $status
 * @property-read string|null $target_run_id
 * @property-read string|null $target_run_code
 * @property-read string|null $payslip_id
 * @property-read string|null $payslip_run_code
 * @property-read int|null $prepared_by
 * @property-read string|null $prepared_by_name
 * @property-read int|null $approved_by
 * @property-read string|null $approved_by_name
 * @property-read CarbonImmutable|null $approved_at
 * @property-read string|null $review_note
 * @property-read CarbonImmutable $created_at
 */
final readonly class AdjustmentView extends Record {}
