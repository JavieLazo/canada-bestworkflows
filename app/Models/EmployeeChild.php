<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One child on an employee's 201 file — a "Children's Name / Birthdate" line
 * of the paper sheet, `position` being the line (0 first).
 *
 * Rows of their own rather than a JSON column, because `employee_profiles`
 * takes no TEXT or JSON column (migration 2026_10_01_0002 says why). They
 * are written only by the Workforce commands that save the record's
 * Children section or hire, through `EmployeeDetailRows::replaceChildren`,
 * which keeps child N on row N and updates it in place inside the profile's
 * transaction, and read only through `EmployeeRecords`, behind the record's
 * own visibility rule — so this model carries no actor scope, like the files
 * read behind their task. `UPDATED_AT` is null because the table has no
 * `updated_at` column.
 *
 * @property string $id
 * @property string $employee_id
 * @property int $position
 * @property string $name
 * @property CarbonImmutable|null $birth_date
 * @property CarbonImmutable $created_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeChild extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'employee_children';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'position',
        'name',
        'birth_date',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'birth_date' => 'immutable_date:Y-m-d',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
