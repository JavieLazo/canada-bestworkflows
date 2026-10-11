<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\PayrollRunKind;

/**
 * Unsaved payroll values; membership and current status are checked under the
 * payroll lock. `incentives` is employee id => [incentive type id =>
 * amount]; `customDeductions` is employee id => [deduction type id =>
 * amount]; `kind` is REGULAR or THIRTEENTH_MONTH (PayrollRunKind),
 * fixed once the run exists; `additionalBasicPay` is, on a 13th month run,
 * employee id => basic pay earned outside EMS. SavePayroll validates all of
 * them again.
 */
final readonly class PayrollDraft
{
    /**
     * @param  list<string>  $members
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  array<string, float>  $allowances
     * @param  array<string, array<string, mixed>>  $earnings
     * @param  array<string, array<string, mixed>>  $incentives
     * @param  array<string, mixed>  $additionalBasicPay
     * @param  array<string, array<string, mixed>>  $customDeductions
     */
    public function __construct(public ?string $runId, public string $name, public string $start, public string $end, public array $members, public array $deductions, public array $allowances, public array $earnings, public array $incentives = [], public string $kind = PayrollRunKind::REGULAR, public array $additionalBasicPay = [], public array $customDeductions = []) {}
}
