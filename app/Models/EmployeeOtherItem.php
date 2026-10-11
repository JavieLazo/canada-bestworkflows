<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an employee's Personal Data Sheet other information — a
 * special skill or hobby, a non-academic distinction, or a membership in an
 * association or organization — `position` being the line within its list
 * (0 first).
 *
 * Rows of their own (migration 2026_10_07_0009 says why), one per line: a
 * list is saved whole and line N stays on row N, updated in place, so the
 * unique on (employee, kind, position) never collides. Written only by the
 * Workforce commands, inside the profile's transaction after its row is
 * locked, and read only through `EmployeeRecords`, behind the record's own
 * visibility rule — so this model carries no actor scope, like
 * `EmployeeChild`.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $kind
 * @property int $position
 * @property string $text
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeOtherItem extends BaseModel
{
    /** "Special skills and hobbies". */
    public const string SKILL = 'SKILL';

    /** "Non-academic distinctions / recognition". */
    public const string DISTINCTION = 'DISTINCTION';

    /** "Membership in association / organization". */
    public const string MEMBERSHIP = 'MEMBERSHIP';

    /** @var string */
    protected $table = 'employee_other_items';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'kind',
        'position',
        'text',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
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
