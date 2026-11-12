<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached Payslip data, with no persistence or lazy relationships.
 *
 * @property-read string $id
 * @property-read string|null $run_id
 * @property-read string|null $payroll_code
 * @property-read string $employee_id
 * @property-read CarbonImmutable $period_start
 * @property-read CarbonImmutable $period_end
 * @property-read float $daily_rate
 * @property-read float $work_days
 * @property-read float $holiday_days
 * @property-read float $rest_days
 * @property-read float $absence_days
 * @property-read float $unpaid_leave_days
 * @property-read float $paid_leave_days
 * @property-read float $work_days_pay
 * @property-read float $holiday_pay
 * @property-read float $rest_day_pay
 * @property-read float $basic_pay
 * @property-read float $allowances
 * @property-read float $total_base_pay
 * @property-read float $overtime_hours
 * @property-read float $overtime_pay
 * @property-read bool $overtime_acknowledged
 * @property-read float $night_differential
 * @property-read float $leave_pay
 * @property-read float $other_earnings
 * @property-read float $bonus
 * @property-read float $thirteenth_month
 * @property-read float $gross_pay
 * @property-read float $sss
 * @property-read float $sss_er
 * @property-read float $philhealth
 * @property-read float $philhealth_er
 * @property-read float $pagibig
 * @property-read float $pagibig_er
 * @property-read float $withholding_tax
 * @property-read float $other_deductions
 * @property-read float $custom_deductions
 * @property-read int $late_minutes
 * @property-read float $late_deduction
 * @property-read int $undertime_minutes
 * @property-read float $undertime_deduction
 * @property-read float $total_deductions
 * @property-read float $net_pay
 * @property-read float $late_undertime_deduction
 * @property-read float $absence_deduction
 * @property-read float $unpaid_leave_deduction
 * @property-read CarbonImmutable|null $attendance_through  this member's counted date on an advance payroll (the run's, pulled back before an unfinished day), the day before the period at the earliest; null when the whole period was read
 * @property-read float $presumed_days  days paid ahead of their attendance
 * @property-read float $settlement  the settlement of earlier presumed days inside gross pay, positive or negative
 * @property-read float $leave_charged_days  the days of leave credits the payslip charged for lateness, undertime and absences
 * @property-read float $exempt_benefits
 * @property-read float $taxable_benefits_excess
 * @property-read float|null $taxable_compensation
 * @property-read float|null $tax_refund  withholding refunded on the year's last payroll, paid into the net pay outside gross pay
 * @property-read CarbonImmutable $created_at
 */
final readonly class PayslipView extends Record {}
