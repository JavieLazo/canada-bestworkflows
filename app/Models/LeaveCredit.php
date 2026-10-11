<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many days of ONE leave type an employee is entitled to in ONE calendar
 * year — entered by hand on Leave Management's Leave Credits tab
 * (leave_credits, 2026_09_26_0003). The reference has no counterpart: it
 * carries two fixed numbers on the employee record that nothing reads; ems
 * has no such columns (2026_09_25_0002) and keeps a row per Leave Settings
 * type instead.
 *
 * One row per (employee, year, request_type). `request_type` is the SAME
 * token `leave_travel_requests.request_type` stores — a Leave Settings name
 * through `HrisFormat::normalizeRequestType` — and a plain string, not a key
 * into `leave_types`, for the same reason requests store it that way: the
 * list is user-editable in Records Management. The ABSENCE of a row means
 * "not entered"; an explicit 0.00 is a row.
 *
 * The balance is NEVER stored. `Modules\Timekeeping\Application\Queries\
 * LeaveCreditRecords` derives "used" from the approved with-pay requests of
 * the same type and year at read time, so nothing here goes stale when a
 * request is re-reviewed or withdrawn. `SaveLeaveCredits` and
 * `RecordLeaveCredit` preserve each allocation change in LeaveCreditEntry.
 *
 * `credits` is DECIMAL(6,2) cast `float`, never `decimal:N`. `employee()`
 * exists for scoping and tests. Row scoping widens on LEAVES alone, exactly
 * as `leave_travel_requests` does — and the My Portal read pins the actor's
 * own row on top of it.
 *
 * @property string $id
 * @property string $employee_id
 * @property int $year
 * @property string $request_type
 * @property float $credits
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class LeaveCredit extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'leave_credits';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'year',
        'request_type',
        'credits',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'credits' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
