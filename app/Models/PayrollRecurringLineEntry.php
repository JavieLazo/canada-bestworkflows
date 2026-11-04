<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;

/**
 * One entry of a loan's ledger beside the payslips
 * (payroll_recurring_line_entries, 2026_11_02_0013): an OUTSIDE_PAYMENT
 * made straight to the lender, which lowers what the recurring deduction
 * line has left to collect, or an ADJUSTMENT of the balance from the
 * lender's statement — positive when more is owed, negative when less —
 * with the date, the reason and the paper behind it. A DRAFT waits for
 * approval; only an APPROVED entry joins what the
 * payslips paid (RecurringLineLedger).
 *
 * `prepared_by` and `approved_by` are actor columns: set from Access,
 * never fillable. Row scoping widens on PAYROLL alone, like the payslips.
 *
 * Owner: Payroll.
 *
 * @property string $id
 * @property string $line_id
 * @property string $employee_id
 * @property string $kind
 * @property float $amount
 * @property CarbonImmutable $entry_date
 * @property string $reason
 * @property string|null $reference
 * @property string $status
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollRecurringLineEntry extends BaseModel
{
    use ScopesToActor;

    public const string DRAFT = 'DRAFT';

    public const string APPROVED = 'APPROVED';

    public const string KIND_OUTSIDE_PAYMENT = 'OUTSIDE_PAYMENT';

    public const string KIND_ADJUSTMENT = 'ADJUSTMENT';

    /** The two kinds, with their names on screen. */
    public const array KINDS = [self::KIND_OUTSIDE_PAYMENT => 'Payment made outside payroll', self::KIND_ADJUSTMENT => 'Balance adjustment'];

    /** @var string */
    protected $table = 'payroll_recurring_line_entries';

    /** @var list<string> */
    protected $fillable = ['line_id', 'employee_id', 'kind', 'amount', 'entry_date', 'reason', 'reference', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'entry_date' => 'immutable_date:Y-m-d',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
