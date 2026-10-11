<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One learning and development intervention or training program on an
 * employee's Personal Data Sheet.
 *
 * Rows of their own, one per training (migration 2026_10_07_0007 says why),
 * each identified by its id. Written only by the Workforce commands, inside
 * the profile's transaction after its row is locked, and read only through
 * `PdsRows` / `EmployeeRecords`, behind the record's own visibility rule — so
 * this model carries no actor scope, like `EmployeeChild`. A row is edited in
 * place.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $title
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property float|null $hours
 * @property string|null $ld_type
 * @property string|null $sponsor
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeTraining extends BaseModel
{
    /** @var string */
    protected $table = 'employee_trainings';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'title',
        'date_from',
        'date_to',
        'hours',
        'ld_type',
        'sponsor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'immutable_date:Y-m-d',
            'date_to' => 'immutable_date:Y-m-d',
            'hours' => 'float',
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
