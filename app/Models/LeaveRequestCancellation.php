<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Immutable evidence excluding one approved leave date without rewriting its approval.
 *
 * @property string $id
 * @property string $leave_travel_request_id
 * @property CarbonImmutable $date
 * @property int $cancelled_by
 * @property CarbonImmutable $cancelled_at
 * @property string $reason
 * @property CarbonImmutable $created_at
 */
class LeaveRequestCancellation extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['leave_travel_request_id', 'date', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
