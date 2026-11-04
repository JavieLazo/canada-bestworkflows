<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Daily Time Record's decision on one employee's overtime on one day
 * (overtime_approvals, 2026_09_26_0006). The reference has no counterpart:
 * it pays overtime once a payroll officer acknowledges it for the whole run;
 * ems approves it per day, on the DTR, and payroll pays only what was
 * approved.
 *
 * One row per (employee, shift-START date). No row is PENDING. APPROVED
 * carries 1..computed_minutes (an approver may approve fewer minutes than
 * the day computed); REJECTED carries 0. `computed_minutes` is what the day
 * computed when it was decided, so a later correction shows as outdated.
 * `Modules\Timekeeping\Application\Commands\DecideOvertime` is the only
 * writer.
 *
 * `reviewed_by` is an actor column: set from Access by property assignment,
 * never fillable (ModelIntegrityTest). Row scoping widens on EDTR alone, as
 * the attendance remarks do: the decision belongs to the DTR, and payroll
 * reads the approved minutes through the AttendanceSource contract.
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable $date
 * @property string $decision
 * @property int $computed_minutes
 * @property int $approved_minutes
 * @property string|null $note
 * @property string|null $category NULL = ordinary overtime; SEAPORT = priced at its own rate and never capped (2026_11_02_0006)
 * @property int $credited_minutes the approved minutes credited as Compensatory Leave instead of paid (2026_11_02_0016); 0 before the column
 * @property int|null $reviewed_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmployeeProfile|null $employee
 */
class OvertimeApproval extends BaseModel
{
    use ScopesToActor;

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    /** @var string */
    protected $table = 'overtime_approvals';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'date',
        'decision',
        'computed_minutes',
        'approved_minutes',
        'note',
        // The overtime category (2026_11_02_0006): NULL, or SEAPORT.
        'category',
        // The minutes credited as Compensatory Leave (2026_11_02_0016): 0 = all paid.
        'credited_minutes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'computed_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'credited_minutes' => 'integer',
            'reviewed_by' => 'integer',
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
