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
 * A paid leave whose credits ran short names its days without pay in
 * `unpaid_dates` (LeavePayDates): paid up to them, without pay from them on.
 *
 * `reviewed_by` is an ACTOR column: session-supplied, never mass-assigned.
 *
 * @property string $id
 * @property string $code
 * @property string $employee_id
 * @property string $request_type
 * @property string|null $day_type
 * @property string|null $half AM or PM on a half day that says which half is off (2026_11_02_0015); null otherwise
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property string|null $reason
 * @property string $status
 * @property bool|null $is_paid
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $credits_year the year whose credits an approved paid leave draws on; null reads the start year (2026_10_31_0009)
 * @property float|null $credit_days the scheduled working days the approval charged; null counts calendar days
 * @property list<string>|null $credit_dates the dates `credit_days` counts
 * @property list<string>|null $unpaid_dates the dates an approval with pay left without pay because the credits or set days ran short (2026_11_07_0002); null when none
 * @property string|null $disapproval_reason why a REJECTED (disapproved) request was refused, shown to the employee (2026_11_08_0001); null on every other row
 * @property string|null $leave_detail the details of leave picked for the type's section (LeaveApplication, 2026_11_08_0003); null when the type asks none
 * @property string|null $leave_detail_note what the detail specifies: where abroad, or the illness
 * @property string|null $approval_note the application's "others (specify)", written on approving a leave (2026_11_11_0003)
 * @property string|null $commutation NOT_REQUESTED or REQUESTED on a leave; null on official business
 * @property string|null $recommendation FOR_APPROVAL or FOR_DISAPPROVAL, recorded on a pending request (2026_11_08_0004); null while none is
 * @property string|null $recommendation_reason why disapproval was recommended
 * @property int|null $recommended_by the account that recorded the recommendation — an ACTOR column
 * @property CarbonImmutable|null $recommended_at
 * @property array<string, mixed>|null $approved_form what the Application for Leave stated when approved (LeaveFormFreeze, 2026_11_11_0006), never written over; null until then
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
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
        'half',
        'start_date',
        'end_date',
        'reason',
        'leave_detail',
        'leave_detail_note',
        'commutation',
        'status',
        'is_paid',
        'reviewed_at',
    ];

    /**
     * Kept out of every read projection: it holds the employee's salary, and
     * only Queries\LeaveFormRecords reads it.
     *
     * @var list<string>
     */
    protected $hidden = ['approved_form'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
            'is_paid' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
            'recommended_at' => 'immutable_datetime',
            'credits_year' => 'integer',
            'credit_days' => 'float',
            'credit_dates' => 'array',
            'unpaid_dates' => 'array',
            'approved_form' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
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
