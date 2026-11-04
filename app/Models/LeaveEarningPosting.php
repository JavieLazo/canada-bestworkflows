<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;

/**
 * What the leave earning job posted for one employee, month (`period`,
 * YYYY-MM; the year-end step uses YYYY-YE) and leave type: the policy
 * version, the month's facts (`inputs`), the credits and the ledger entry
 * written. Append-only; the unique key on (employee, period, type) is
 * what lets the job run every half hour and post once. Leave data: read
 * behind the leave list's scope (LEAVES).
 *
 * @property string $id
 * @property string $employee_id
 * @property string $period
 * @property string $request_type
 * @property string|null $policy_id
 * @property float $days
 * @property array<string, mixed> $inputs
 * @property string|null $entry_id
 * @property CarbonImmutable $created_at
 */
class LeaveEarningPosting extends BaseModel
{
    use ScopesToActor;

    public const UPDATED_AT = null;

    protected $table = 'leave_earning_postings';

    protected $fillable = ['employee_id', 'period', 'request_type', 'policy_id', 'days', 'inputs', 'entry_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'days' => 'float',
            'inputs' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
