<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A temporary assignment: from `date` through `end_date` (NULL = that one
 * day) it replaces the weekly hours, the weekly place, or both. Null
 * shift_data and location_kind independently mean follow the weekly roster.
 * Copied hours survive pattern deletion; each date is a shift START date.
 *
 * A row is never overwritten or deleted (2026_09_30_0007). It applies from
 * `date` through `cut_after`, else `end_date`, else `date`. Removing or
 * replacing it sets `status` CANCELLED with who and when and a `cut_after`
 * (before `date` when it never began); a pattern edit that reaches a running
 * assignment closes it instead — ACTIVE, with `cut_after` at the day before
 * the edit, because it did apply until then — and adds the rest as a new row
 * naming it in `revision_of`. With `include_rest_days` FALSE the hours apply
 * only on the dates the weekly schedule works; a one-date change keeps TRUE.
 * `created_by` and `cancelled_by` are actor columns: set from Access by
 * property assignment, never fillable (ModelIntegrityTest).
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable $date
 * @property string|null $shift_type_id
 * @property string|null $shift_name
 * @property bool $is_rest_day
 * @property array<string, mixed>|null $shift_data
 * @property string|null $location_kind
 * @property string|null $site_id
 * @property CarbonImmutable|null $end_date
 * @property bool $include_rest_days
 * @property string $status
 * @property CarbonImmutable|null $cut_after
 * @property string|null $revision_of
 * @property int|null $created_by
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class RosterDayOverride extends BaseModel
{
    use ScopesToActor;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'date', 'end_date', 'shift_type_id', 'shift_name', 'is_rest_day', 'include_rest_days', 'shift_data', 'location_kind', 'site_id', 'status', 'cut_after', 'revision_of', 'cancelled_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['include_rest_days' => true, 'status' => self::STATUS_ACTIVE];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date:Y-m-d', 'end_date' => 'immutable_date:Y-m-d', 'cut_after' => 'immutable_date:Y-m-d', 'is_rest_day' => 'boolean', 'include_rest_days' => 'boolean',
            'shift_data' => 'array', 'created_by' => 'integer', 'cancelled_by' => 'integer', 'cancelled_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
