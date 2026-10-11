<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only employment timeline row for an employee record.
 *
 * The row copies the Employee Details snapshot after a hire or HR edit. It is
 * read only through Workforce queries behind the employee record visibility
 * rule, so it carries no actor scope of its own. `UPDATED_AT` is null because a
 * timeline row is never edited in place.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $event_type
 * @property CarbonImmutable $effective_date
 * @property string|null $changed_fields
 * @property array<string, string|null>|null $previous_values
 * @property list<array{field: string, label: string, before: string|null, after: string|null, before_known: bool, after_known: bool}>|null $record_changes
 * @property string|null $request_id
 * @property int|null $requested_by
 * @property string|null $requested_by_name
 * @property CarbonImmutable|null $requested_at
 * @property string|null $request_reason
 * @property string|null $review_note
 * @property string|null $position
 * @property string|null $position_level
 * @property string|null $employment_status
 * @property string|null $work_site_id
 * @property string|null $work_site_name
 * @property CarbonImmutable|null $date_hired
 * @property CarbonImmutable|null $date_regular
 * @property CarbonImmutable|null $date_resigned
 * @property bool $is_active
 * @property float $monthly_rate
 * @property float|null $daily_rate
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property CarbonImmutable $created_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeEmploymentHistory extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'employee_employment_histories';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'event_type',
        'effective_date',
        'changed_fields',
        'previous_values',
        'position',
        'position_level',
        'employment_status',
        'work_site_id',
        'work_site_name',
        'date_hired',
        'date_regular',
        'date_resigned',
        'is_active',
        'monthly_rate',
        'daily_rate',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_date' => 'immutable_date:Y-m-d',
            'previous_values' => 'array',
            'record_changes' => 'array',
            'requested_at' => 'immutable_datetime',
            'date_hired' => 'immutable_date:Y-m-d',
            'date_regular' => 'immutable_date:Y-m-d',
            'date_resigned' => 'immutable_date:Y-m-d',
            'is_active' => 'boolean',
            'monthly_rate' => 'float',
            'daily_rate' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
