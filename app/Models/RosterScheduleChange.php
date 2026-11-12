<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated change to an employee's default shift, weekday hours or weekday
 * places (2026_09_30_0003). The hours and places live in `shifts`,
 * `roster_shifts` and `roster_locations` rows carrying this change's id and
 * `effective_from`; this row says when, how and by whom, and whether it was
 * cancelled before it took effect. A row for a date is the latest one on or
 * before it, so a later change never re-judges an earlier date.
 *
 * BASELINE rows (`effective_from` NULL, one per employee that existed on
 * 2026_09_30_0008) carry only the markers: dates before `assumed_before`
 * read "Assumed", and dates up to `may_differ_through` "Schedule may have
 * differed". Schedule rows with a NULL `change_id` belong to that baseline.
 *
 * `created_by` and `cancelled_by` are actor columns: set from Access by
 * property assignment, never fillable (ModelIntegrityTest). Row scoping
 * widens on ROSTER | EDTR | PAYROLL, as `shifts` does.
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable|null $effective_from
 * @property string $source
 * @property string $status
 * @property CarbonImmutable|null $assumed_before
 * @property CarbonImmutable|null $may_differ_through
 * @property int|null $created_by
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class RosterScheduleChange extends BaseModel
{
    use ScopesToActor;

    public const SOURCE_BASELINE = 'BASELINE';

    public const SOURCE_MANUAL = 'MANUAL';

    public const SOURCE_IMPORT = 'IMPORT';

    public const SOURCE_PATTERN_EDIT = 'PATTERN_EDIT';

    /** The one-time move of a pattern's rest days onto the employee (`RestDayConversion`, 2026_11_12_0002). */
    public const SOURCE_REST_DAYS = 'REST_DAYS';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** @var string */
    protected $table = 'roster_schedule_changes';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'effective_from', 'source', 'status', 'assumed_before', 'may_differ_through'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'assumed_before' => 'immutable_date:Y-m-d',
            'may_differ_through' => 'immutable_date:Y-m-d',
            'created_by' => 'integer',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'immutable_datetime',
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
