<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One unit of work inside a project, owned by exactly one employee.
 *
 * `assignee_id` is an `employee_profiles` id — an employee, not an account —
 * while `assigned_by` and `reviewed_by` are `user_accounts` ids. Those last two
 * are ACTOR columns (session-supplied, never mass-assigned) and are the reason
 * this table carries two foreign keys to the same table.
 *
 * `status` (PENDING | IN_PROGRESS | SUBMITTED | RETURNED | COMPLETED |
 * CANCELLED) and `priority` are free text. `TaskInput` only ever accepts
 * `status: "PENDING"` on create; every later transition is a server action.
 *
 * `remarks` and `review_remarks` hold the LATEST message only — the thread in
 * `task_comments` is what keeps the history of a task that bounces twice.
 *
 * @property string $id
 * @property string $code
 * @property string $project_id
 * @property string $title
 * @property string|null $description
 * @property string|null $assignee_id
 * @property string $status
 * @property string $priority
 * @property CarbonImmutable|null $due_date
 * @property int|null $assigned_by
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $reviewed_by
 * @property string|null $remarks
 * @property string|null $review_remarks
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project|null $project
 * @property-read EmployeeProfile|null $assignee
 * @property-read User|null $assigner
 * @property-read User|null $reviewer
 * @property-read Collection<int, ProjectTaskFile> $files
 * @property-read Collection<int, TaskComment> $comments
 */
class ProjectTask extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'project_tasks';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'project_id',
        'title',
        'description',
        'assignee_id',
        'status',
        'priority',
        'due_date',
        'assigned_at',
        'submitted_at',
        'completed_at',
        'remarks',
        'review_remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date:Y-m-d',
            'assigned_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Ports `assigned_at DateTime @default(now())`. MySQL will not carry a
     * CURRENT_TIMESTAMP default on a second DATETIME on older servers, and the
     * column is NOT NULL, so the default lives here — set only when the writer
     * left it absent, exactly as Prisma's default behaved.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(static function (ProjectTask $task): void {
            if ($task->getAttribute('assigned_at') === null) {
                $task->setAttribute('assigned_at', $task->freshTimestamp());
            }
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'assignee_id');
    }

    /**
     * `TaskAssignedBy` in the source.
     *
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * `TaskReviewedBy` in the source.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<ProjectTaskFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ProjectTaskFile::class, 'task_id');
    }

    /** @return HasMany<TaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }
}
