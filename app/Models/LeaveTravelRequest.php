<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A leave filing or an official-business travel request.
 *
 * `request_type` is a `leave_types.name` normalised to a key, or
 * `BUSINESS_TRAVEL` for official business (dtr.ts: OFFICIAL_BUSINESS). It is
 * not an enum: the list is user-editable in Records Management. `day_type`
 * (WHOLE_DAY | HALF_DAY) and `status` (PENDING | APPROVED | REJECTED |
 * WITHDRAWN | CANCELLED) are free text for the same reason. Partial date
 * cancellations keep APPROVED; their evidence excludes only the named dates.
 *
 * `is_paid` is genuinely THREE-STATE and the nullable boolean is load-bearing:
 * true = paid leave, false = leave without pay, NULL = official business.
 * Branch on `is_null()` before `=== false` — a NOT NULL `is_paid` would record
 * every OB travel request as unpaid leave, which is a payroll deduction.
 *
 * `reviewed_by` is an ACTOR column: session-supplied, never mass-assigned.
 *
 * @property string $id
 * @property string $code
 * @property string $employee_id
 * @property string $request_type
 * @property string|null $day_type
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property string|null $reason
 * @property string $status
 * @property bool|null $is_paid
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 * @property-read User|null $reviewer
 * @property-read Collection<int, LeaveRequestCancellation> $cancellations
 */
class LeaveTravelRequest extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'leave_travel_requests';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'employee_id',
        'request_type',
        'day_type',
        'start_date',
        'end_date',
        'reason',
        'status',
        'is_paid',
        'reviewed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
            'is_paid' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<LeaveRequestCancellation, $this> */
    public function cancellations(): HasMany
    {
        return $this->hasMany(LeaveRequestCancellation::class, 'leave_travel_request_id');
    }

    /** @return list<string> */
    public function cancelledDates(): array
    {
        $dates = $this->cancellations
            ->map(static fn (LeaveRequestCancellation $row): string => $row->date->format('Y-m-d'))
            ->values()->all();
        sort($dates, SORT_STRING);

        return $dates;
    }
}
