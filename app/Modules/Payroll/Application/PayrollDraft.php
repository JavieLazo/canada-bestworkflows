<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

/**
 * Unsaved payroll values; membership and current status are checked under the
 * payroll lock. `incentives` is employee id => [incentive type id =>
 * amount]; `customDeductions` is employee id => [deduction type id =>
 * amount]; `skippedRecurring` is
 * employee id => the ids of their recurring lines skipped on this payroll;
 * `attendanceThrough` is the advance payroll's "attendance counted through"
 * date (PayrollAdvance), blank or null for the whole period;
 * `scheduleId` is the pay schedule the run's dates come from and `payDate`
 * the day the pay goes out (PaySchedules), blank or null on a run made
 * without one. SavePayroll validates all of them again.
 */
final readonly class PayrollDraft
{
    /**
     * @param  list<string>  $members
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  array<string, float>  $allowances
     * @param  array<string, array<string, mixed>>  $earnings
     * @param  array<string, array<string, mixed>>  $incentives
     * @param  array<string, array<string, mixed>>  $customDeductions
     * @param  array<string, list<string>>  $skippedRecurring
     */
    public function __construct(public ?string $runId, public string $name, public string $start, public string $end, public array $members, public array $deductions, public array $allowances, public array $earnings, public array $incentives = [], public array $customDeductions = [], public array $skippedRecurring = [], public ?string $attendanceThrough = null, public ?string $scheduleId = null, public ?string $payDate = null) {}
}
