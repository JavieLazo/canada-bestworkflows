<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One voluntary work entry on an employee's Personal Data Sheet — an
 * involvement in a civic, non-government, people or voluntary organization.
 *
 * Rows of their own, one per entry (migration 2026_10_07_0008 says why), each
 * identified by its id. Only the organization is required. Written only by
 * the Workforce commands, inside the profile's transaction after its row is
 * locked, and read only through `PdsRows` / `EmployeeRecords`, behind the
 * record's own visibility rule — so this model carries no actor scope, like
 * `EmployeeChild`. A row is edited in place.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $organization
 * @property string|null $organization_address
 * @property CarbonImmutable|null $date_from
 * @property CarbonImmutable|null $date_to
 * @property float|null $hours
 * @property string|null $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeVoluntaryWork extends BaseModel
{
    /** @var string */
    protected $table = 'employee_voluntary_works';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'organization',
        'organization_address',
        'date_from',
        'date_to',
        'hours',
        'position',
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
