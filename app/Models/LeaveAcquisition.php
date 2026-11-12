<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;

/**
 * The day an employee ACQUIRED a leave type that has a waiting period: the
 * date, the rule version that gave it, how the date was reached (`basis`:
 * the hire date, the months waited, the days without pay that extended the
 * wait) and the credits granted on it with their ledger entry. Written once
 * by the earning job and never changed; the unique key on (employee, type)
 * is what lets the job run every half hour and record it once. Leave data:
 * read behind the leave list's scope (LEAVES).
 *
 * @property string $id
 * @property string $employee_id
 * @property string $request_type
 * @property CarbonImmutable $acquired_on
 * @property string|null $rule_id
 * @property array<string, mixed> $basis
 * @property float $granted
 * @property string|null $entry_id
 * @property CarbonImmutable $created_at
 */
class LeaveAcquisition extends BaseModel
{
    use ScopesToActor;

    public const UPDATED_AT = null;

    protected $table = 'leave_acquisitions';

    protected $fillable = ['employee_id', 'request_type', 'acquired_on', 'rule_id', 'basis', 'granted', 'entry_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'acquired_on' => 'immutable_date:Y-m-d',
            'basis' => 'array',
            'granted' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }
}
