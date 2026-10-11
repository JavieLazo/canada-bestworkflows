<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's default shift from `effective_from` on (2026_09_30_0004).
 * Each change adds a row instead of overwriting one, so a date is judged by
 * the latest row on or before it — NULL `effective_from` is "since before
 * history was kept" — unless its `change_id` names a cancelled
 * `roster_schedule_changes` row. Read it through `ShiftRoster` or
 * `RosterVersions`, never with `->first()` for an employee.
 *
 * Times are `HH:MM:SS` STRINGS rather than a MySQL TIME column, on purpose:
 * `src/lib/dtr.ts` splits them on ":" and does its arithmetic in minutes, and
 * it has to keep working unchanged on both sides of the API. MySQL TIME also
 * normalises input and accepts -838:59:59..838:59:59, and the seeded night
 * shift wraps 22:00:00 -> 06:00:00.
 *
 * `work_days` is a Postgres `Int[]` in the source — 0 Sunday to 6 Saturday.
 * Everything not listed is a rest day, and this list is what the monthly rate
 * is divided by to reach a daily rate, so a Mon-Sat employee's day is worth
 * less than a Mon-Fri one's. MySQL has no array type; it is a JSON column read
 * whole (never queried by path), with the default supplied through
 * `$attributes` because MySQL before 8.0.13 and MariaDB before 10.2.1 reject a
 * literal default on a JSON column.
 *
 * `mode` is a `ShiftMode` (FIXED | FLEXIBLE), copied from the shift pattern at
 * assignment (`AssignShift`). An edit of the linked pattern (`SaveShiftType`)
 * copies it again as a new row from the edit's date; earlier rows keep the
 * hours they had. Posted payroll is
 * protected by `PayrollInputs` capturing each shift at finalization, not by
 * this copy. Cast as a plain string until `app/Enums/ShiftMode.php` exists;
 * the stored values are already the enum's backing strings.
 *
 * This is one of only two tables with NEITHER created_at NOR updated_at.
 *
 * @property string $id
 * @property string $employee_id
 * @property string|null $shift_type_id
 * @property string $mode
 * @property float|null $required_hours
 * @property list<int> $work_days
 * @property string $start_time
 * @property string $end_time
 * @property int $grace_minutes
 * @property string|null $break_start
 * @property string|null $break_end
 * @property int $break_minutes
 * @property bool $requires_break_punch
 * @property CarbonImmutable|null $effective_from
 * @property string|null $change_id
 * @property-read EmployeeProfile|null $employee
 * @property-read ShiftType|null $shiftType
 */
class Shift extends BaseModel
{
    use ScopesToActor;

    /** No created_at and no updated_at on this table. */
    public $timestamps = false;

    /** @var string */
    protected $table = 'shifts';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'shift_type_id',
        'mode',
        'required_hours',
        'work_days',
        'start_time',
        'end_time',
        'grace_minutes',
        'break_start',
        'break_end',
        'break_minutes',
        'requires_break_punch',
        'effective_from',
        'change_id',
    ];

    /**
     * MySQL cannot carry a literal default on a JSON column, so the schema's
     * `@default([1, 2, 3, 4, 5])` lives here. The raw form is the stored JSON.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'work_days' => '[1,2,3,4,5]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'required_hours' => 'float',
            'work_days' => 'array',
            'grace_minutes' => 'integer',
            'break_minutes' => 'integer',
            'requires_break_punch' => 'boolean',
            'effective_from' => 'immutable_date:Y-m-d',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }

    /**
     * The Shifting Settings pattern this was assigned from — and the link
     * `SaveShiftType` follows to re-copy an edited pattern onto this row. The
     * FK is nullOnDelete: a row whose pattern was deleted keeps its last copy
     * and is never rewritten again.
     *
     * @return BelongsTo<ShiftType, $this>
     */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class, 'shift_type_id');
    }
}
