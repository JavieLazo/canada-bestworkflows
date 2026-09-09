<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note written against one employee's day on the eDTR. Unique on
 * `(employee_id, date)`.
 *
 * The DTR itself is derived from punches — there is no stored row per day — so
 * a remark someone types needs one of its own. It replaces the sentence the
 * eDTR would otherwise write for that day; it never changes what the day is
 * worth, which still comes from the punches and the shift.
 *
 * The ONLY table in the schema with a real `updated_at` (`@updatedAt`), so it
 * is also the only one running Eloquent's full timestamp handling.
 *
 * `author_id` is an actor column and, in the source, carries NO foreign key and
 * NO Prisma relation — so no `belongsTo` is declared here either. Adding one
 * would be a new constraint the reference does not have.
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable $date
 * @property string $note
 * @property int|null $author_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class AttendanceRemark extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'attendance_remarks';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'date',
        'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
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
