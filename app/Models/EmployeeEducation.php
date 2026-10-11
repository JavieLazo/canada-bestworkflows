<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One school on an employee's educational background — a line of the
 * Personal Data Sheet's education table, at one of its five levels.
 *
 * Rows of their own rather than columns on `employee_profiles`, whose legacy
 * college and graduate-school columns are never copied or rewritten
 * (migration 2026_10_07_0004 says why). Any number of rows per level, each
 * identified by its id. Written only by the Workforce commands, inside the
 * profile's transaction after its row is locked, and read only through
 * `PdsRows` / `EmployeeRecords`, behind the record's own visibility rule — so
 * this model carries no actor scope, like `EmployeeChild`. A row is edited in
 * place.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $level
 * @property string $school
 * @property string|null $course
 * @property int|null $attended_from
 * @property int|null $attended_to
 * @property string|null $units_earned
 * @property int|null $year_graduated
 * @property string|null $honors
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class EmployeeEducation extends BaseModel
{
    public const string ELEMENTARY = 'ELEMENTARY';

    public const string SECONDARY = 'SECONDARY';

    /** "Vocational / trade course". */
    public const string VOCATIONAL = 'VOCATIONAL';

    public const string COLLEGE = 'COLLEGE';

    /** "Graduate studies". */
    public const string GRADUATE = 'GRADUATE';

    /** The levels in the Personal Data Sheet's order — the `eedu_level_chk` list. */
    public const array LEVELS = [
        self::ELEMENTARY,
        self::SECONDARY,
        self::VOCATIONAL,
        self::COLLEGE,
        self::GRADUATE,
    ];

    /** @var string */
    protected $table = 'employee_education';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'level',
        'school',
        'course',
        'attended_from',
        'attended_to',
        'units_earned',
        'year_graduated',
        'honors',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attended_from' => 'integer',
            'attended_to' => 'integer',
            'year_graduated' => 'integer',
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
