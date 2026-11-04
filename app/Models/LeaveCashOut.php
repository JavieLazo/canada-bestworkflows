<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;

/**
 * One request to turn part of a leave type's credits into pay
 * (`leave_cash_outs`, 2026_10_31_0008): the employee, the type's token,
 * the days, its status and who did what to it. PENDING until a leave
 * manager decides; APPROVED waits for the employee's next regular payroll,
 * which marks it PAID with the payslip and the pesos and takes the days
 * off the ledger (CASH_OUT). Leave data, read behind the leave list's
 * scope (LEAVES). Actor columns are set by the commands, never filled.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $request_type
 * @property float $days
 * @property string $status
 * @property string|null $note
 * @property int|null $requested_by
 * @property CarbonImmutable $requested_at
 * @property int|null $reviewed_by
 * @property string|null $reviewed_by_name
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property string|null $payslip_id
 * @property float|null $amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveCashOut extends BaseModel
{
    use ScopesToActor;

    public const string PENDING = 'PENDING';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    public const string WITHDRAWN = 'WITHDRAWN';

    public const string PAID = 'PAID';

    public const array STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::WITHDRAWN, self::PAID];

    protected $table = 'leave_cash_outs';

    protected $fillable = ['employee_id', 'request_type', 'days', 'status', 'note', 'requested_at', 'review_note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'days' => 'float',
            'amount' => 'float',
            'requested_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
