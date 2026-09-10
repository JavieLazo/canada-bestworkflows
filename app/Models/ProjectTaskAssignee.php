<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's membership on one task — the row behind "this task is
 * assigned to these people".
 *
 * A task is a SHARED unit of work with 0..N assignees. Status, remarks and
 * submitted_at stay on the task (one per task, any assignee may move it), so
 * this row carries nothing but the membership itself and who created it.
 *
 * First-class model rather than an Eloquent Pivot subclass, like
 * PayrollRunMember: it has its own ULID (minted by BaseModel on create, which
 * is why ProjectTask::syncAssignees() inserts THROUGH this class rather than
 * with attach()) and its own unique constraint (task_id, employee_id).
 *
 * `assigned_by` is an ACTOR column — the account that handed the task to this
 * employee, session-supplied and never mass-assigned. ModelIntegrityTest
 * forbids it in $fillable; writers set it with forceFill().
 *
 * Append-only: no updated_at column, so UPDATED_AT is null and created_at is
 * still filled on insert (BaseModel docblock, "Timestamps").
 *
 * @property string $id
 * @property string $task_id
 * @property string $employee_id
 * @property int|null $assigned_by
 * @property CarbonImmutable|null $created_at
 * @property-read ProjectTask|null $task
 * @property-read EmployeeProfile|null $employee
 */
class ProjectTaskAssignee extends BaseModel
{
    /** The table has no updated_at column; created_at is still filled on insert. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'project_task_assignees';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'employee_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assigned_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProjectTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
