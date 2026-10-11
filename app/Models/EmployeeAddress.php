<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of an employee's addresses in parts — the residential or the permanent
 * address of the 201 file, from House/Block/Lot No. to ZIP code.
 *
 * Rows of their own rather than columns on `employee_profiles`, whose legacy
 * one-line `address` is never copied, split or rewritten (migration
 * 2026_10_07_0002 says why). At most one row of each `type` per employee; a
 * type's row exists while it has at least one part, and PERMANENT has none
 * while the employee's details say "same as residential". Written only by the
 * Workforce commands that save the record, inside the profile's transaction
 * after its row is locked, and read only through `EmployeeRecords`, behind
 * the record's own visibility rule — so this model carries no actor scope,
 * like `EmployeeChild`. A row is updated in place when its parts change.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $type
 * @property string|null $house_no
 * @property string|null $street
 * @property string|null $subdivision
 * @property string|null $barangay
 * @property string|null $city
 * @property string|null $province
 * @property string|null $zip_code
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeAddress extends BaseModel
{
    /** Where the employee lives now. */
    public const string RESIDENTIAL = 'RESIDENTIAL';

    /** The home address, when it differs from the residential one. */
    public const string PERMANENT = 'PERMANENT';

    /** @var string */
    protected $table = 'employee_addresses';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'type',
        'house_no',
        'street',
        'subdivision',
        'barangay',
        'city',
        'province',
        'zip_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
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
