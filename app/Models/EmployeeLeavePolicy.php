<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of an employee's leave policy (LeavePolicy `params`), dated:
 * prepared as a DRAFT by one person, APPROVED by another, in force from
 * its effective date until a later approved version's, and never changed
 * once approved. Leave data: read behind the leave list's scope (LEAVES).
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable $effective_from
 * @property array<string, mixed> $params
 * @property string $reason
 * @property string $status
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmployeeLeavePolicy extends BaseModel
{
    use ScopesToActor;

    public const string DRAFT = 'DRAFT';

    public const string APPROVED = 'APPROVED';

    protected $table = 'employee_leave_policies';

    protected $fillable = ['employee_id', 'effective_from', 'params', 'reason', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'params' => 'array',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
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
