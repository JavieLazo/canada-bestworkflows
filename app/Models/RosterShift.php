<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday's copied hours or explicit rest day, from `effective_from` on
 * (2026_09_30_0005). The latest row for a weekday on or before a date
 * decides it (NULL = since before history was kept), unless its `change_id`
 * names a cancelled change. A row with NULL `shift_data` and `shift_name`
 * sends that weekday back to the default shift from its date; no row at all
 * does the same. Pattern deletion clears only the link, preserving the
 * copied hours. Employee is exposed only for established actor scoping and
 * model tests.
 *
 * @property string $id
 * @property string $employee_id
 * @property int $weekday
 * @property string|null $shift_type_id
 * @property string|null $shift_name
 * @property bool $is_rest_day
 * @property array<string, mixed>|null $shift_data
 * @property CarbonImmutable|null $effective_from
 * @property string|null $change_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class RosterShift extends BaseModel
{
    use ScopesToActor;

    /** @var list<string> */
    protected $fillable = ['employee_id', 'weekday', 'shift_type_id', 'shift_name', 'is_rest_day', 'shift_data', 'effective_from', 'change_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weekday' => 'integer', 'is_rest_day' => 'boolean', 'shift_data' => 'array', 'effective_from' => 'immutable_date:Y-m-d', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
