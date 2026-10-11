<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One civil service eligibility on an employee's Personal Data Sheet — a
 * career service, board or bar eligibility or licence, with its own license
 * number and validity.
 *
 * Rows of their own, one per eligibility (migration 2026_10_07_0005 says
 * why), each identified by its id. Written only by the Workforce commands,
 * inside the profile's transaction after its row is locked, and read only
 * through `PdsRows` / `EmployeeRecords`, behind the record's own visibility
 * rule — so this model carries no actor scope, like `EmployeeChild`. A row is
 * edited in place.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $name
 * @property string|null $rating
 * @property CarbonImmutable|null $exam_date
 * @property string|null $exam_place
 * @property string|null $license_no
 * @property CarbonImmutable|null $license_valid_until
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeEligibility extends BaseModel
{
    /** @var string */
    protected $table = 'employee_eligibilities';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'name',
        'rating',
        'exam_date',
        'exam_place',
        'license_no',
        'license_valid_until',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'exam_date' => 'immutable_date:Y-m-d',
            'license_valid_until' => 'immutable_date:Y-m-d',
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
