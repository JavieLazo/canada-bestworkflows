<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;

/**
 * One day's attendance charged to leave credits by a finalized payroll
 * (`leave_attendance_charges`, 2026_10_31_0006): the day, the kind (LATE —
 * lateness and undertime together — or ABSENCE), the minutes it came from,
 * the days it converted to, what each leave type covered (`covered`: a list
 * of type, days and ledger entry) and what was left for pay. Append-only;
 * (employee, date, kind) is charged once. Leave data, read behind the leave
 * list's scope (LEAVES).
 *
 * @property string $id
 * @property string $employee_id
 * @property string|null $payslip_id
 * @property CarbonImmutable $date
 * @property string $kind
 * @property int|null $minutes
 * @property float $days
 * @property list<array{type: string, days: float, entry_id: ?string}> $covered
 * @property float $uncovered_days
 * @property CarbonImmutable $created_at
 */
class LeaveAttendanceCharge extends BaseModel
{
    use ScopesToActor;

    public const UPDATED_AT = null;

    public const string LATE = 'LATE';

    public const string ABSENCE = 'ABSENCE';

    protected $table = 'leave_attendance_charges';

    protected $fillable = ['employee_id', 'payslip_id', 'date', 'kind', 'minutes', 'days', 'covered', 'uncovered_days'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'minutes' => 'integer',
            'days' => 'float',
            'covered' => 'array',
            'uncovered_days' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }
}
