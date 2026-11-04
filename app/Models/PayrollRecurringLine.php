<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One employee's recurring payroll line (payroll_recurring_lines,
 * 2026_10_30_0002): a typed Payroll Deductions row or an unscheduled
 * Incentives & Bonuses type every Basic Payroll it is due on proposes by
 * itself, with a deduction's balance to pay off. The figures' rules are
 * Payroll's `Domain\Recurring\RecurringLine`; what was paid is the posted
 * payslip lines naming the line (RecurringLineLedger).
 *
 * Owner: Payroll. Written only by `Modules\Payroll\Application\Commands\
 * SaveRecurringLine`, `EndRecurringLine` and `DeleteRecurringLine` (all-data
 * PAYROLL). `created_by` and `created_by_name` are set by the command,
 * never filled.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $kind
 * @property string|null $deduction_id
 * @property string|null $incentive_type_id
 * @property float $amount
 * @property float|null $total
 * @property string $frequency
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $note
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollRecurringLine extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_recurring_lines';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'kind', 'deduction_id', 'incentive_type_id', 'amount', 'total', 'frequency', 'starts_on', 'ends_on', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'total' => 'float',
            'starts_on' => 'immutable_date:Y-m-d',
            'ends_on' => 'immutable_date:Y-m-d',
        ];
    }
}
