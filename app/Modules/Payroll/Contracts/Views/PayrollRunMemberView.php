<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached PayrollRunMember data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string $run_id
 * @property-read string $employee_id
 * @property-read int $position
 * @property-read float|null $allowance
 * @property-read float|null $deduction_sss
 * @property-read float|null $deduction_philhealth
 * @property-read float|null $deduction_pagibig
 * @property-read float|null $deduction_withholding_tax
 * @property-read float|null $deduction_other
 * @property-read float|null $deduction_absence
 * @property-read float|null $earning_night_differential
 * @property-read float|null $earning_leave_pay
 * @property-read float|null $earning_other
 * @property-read bool|null $overtime_acknowledged
 * @property-read float|null $additional_basic_pay  13th month runs: basic pay earned outside EMS
 * @property-read array<string, float> $incentives  incentive type id => amount
 * @property-read array<string, float> $deductions  custom deduction type id => amount
 * @property-read CarbonImmutable $period_start
 * @property-read CarbonImmutable $period_end
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
final readonly class PayrollRunMemberView extends Record {}
