<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preserves the movements behind an annual leave allocation. Entries name
 * the employee, year and copied leave token, never a leave_credits row, so
 * clearing an allocation cannot erase its history. Usage remains derived
 * from requests. LEAVES alone widens employee scope, as for LeaveCredit.
 *
 * recorded_by is attribution supplied by the command, NEVER wire input.
 * A baseline has no actor because earlier credit movements were not kept.
 *
 * @property string $id
 * @property string $employee_id
 * @property int $year
 * @property string $request_type
 * @property string $kind
 * @property float $days
 * @property string $note
 * @property int|null $source_year
 * @property int|null $recorded_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class LeaveCreditEntry extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'leave_credit_entries';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'year', 'request_type', 'kind', 'days', 'note', 'source_year',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'days' => 'float',
            'source_year' => 'integer',
            'recorded_by' => 'integer',
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
