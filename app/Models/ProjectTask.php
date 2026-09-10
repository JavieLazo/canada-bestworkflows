<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One SHARED unit of work inside a project, assigned to 0..N employees.
 *
 * The source (and this port until 2026-09-10) owned a task by exactly one
 * employee through a nullable `assignee_id` column. Membership now lives in
 * the `project_task_assignees` pivot ({@see ProjectTaskAssignee}): "unassigned"
 * means zero pivot rows, and every assignee sees, starts and submits the SAME
 * task. `status`, `remarks` and `submitted_at` therefore stay one-per-task —
 * the first assignee to submit submits for everyone — and `submitted_by` (an
 * `employee_profiles` id) records who that was.
 *
 * `assigned_by` and `reviewed_by` are `users` ids. Both are ACTOR columns
 * (session-supplied, never mass-assigned) and are the reason this table
 * carries two foreign keys to the same table.
 *
 * `status` (PENDING | IN_PROGRESS | SUBMITTED | RETURNED | COMPLETED |
 * CANCELLED) and `priority` are free text. `TaskInput` only ever accepts
 * `status: "PENDING"` on create; every later transition is a server action.
 *
 * `remarks` and `review_remarks` hold the LATEST message only — the thread in
 * `task_comments` is what keeps the history of a task that bounces twice.
 *
 * Row scoping goes THROUGH the pivot:
 *
 *     ProjectTask::query()->toEmployeeVia($actor, 'assignments', 'employee_id', ModuleCode::PROJECTS)
 *     Project::query()->toEmployeeVia($actor, 'tasks.assignments', 'employee_id', ModuleCode::PROJECTS)
 *
 * There is no employee column on this table any more, so `toEmployee()` has
 * nothing to point at here — always use the `Via` form.
 *
 * @property string $id
 * @property string $code
 * @property string $project_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property CarbonImmutable|null $due_date
 * @property int|null $assigned_by
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $completed_at
 * @property int|null $reviewed_by
 * @property string|null $remarks
 * @property string|null $review_remarks
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project|null $project
 * @property-read Collection<int, ProjectTaskAssignee> $assignments
 * @property-read Collection<int, EmployeeProfile> $assignees
 * @property-read EmployeeProfile|null $submitter
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
        'status',
        'priority',
        'due_date',
        'assigned_at',
        'submitted_at',
        // An employee id, not an actor column: it names which ASSIGNEE
        // submitted, and the writer resolves it from the pivot, never from
        // a request body.
        'submitted_by',
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

    /**
     * The membership rows themselves — what ScopesToActor narrows through.
     *
     * @return HasMany<ProjectTaskAssignee, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectTaskAssignee::class, 'task_id');
    }

    /**
     * The employees the task is assigned to. Eager-load this wherever tasks
     * are listed (ProjectsData::scopedTasks() already does) so the name
     * helpers do not go N+1.
     *
     * No ->using(ProjectTaskAssignee::class): the pivot is a first-class model
     * with its own ULID, not an Eloquent Pivot subclass. Do not attach()
     * through this relation — it would insert without an id. Use
     * {@see self::syncAssignees()}.
     *
     * @return BelongsToMany<EmployeeProfile, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(EmployeeProfile::class, 'project_task_assignees', 'task_id', 'employee_id')
            ->withPivot(['assigned_by', 'created_at']);
    }

    /**
     * The assignee who last submitted the task, if anyone has.
     *
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'submitted_by');
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

    /* ----------------------------- assignees ------------------------------ */

    /**
     * The assignees' employee ids, sorted, from the loaded (or lazily loaded)
     * `assignees` relation. Empty list = unassigned.
     *
     * @return list<string>
     */
    public function assigneeIds(): array
    {
        $ids = [];

        foreach ($this->assignees as $employee) {
            $ids[] = (string) $employee->getKey();
        }

        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Whether the given employee is one of the assignees. False for null or
     * '' — never a match against "unassigned" — and a strict comparison, so
     * this is safe to feed an `Access::$employeeId` directly.
     */
    public function hasAssignee(?string $employeeId): bool
    {
        if ($employeeId === null || $employeeId === '') {
            return false;
        }

        return in_array($employeeId, $this->assigneeIds(), true);
    }

    /**
     * Make the pivot match exactly `$employeeIds`: rows for newcomers are
     * created THROUGH ProjectTaskAssignee (so BaseModel mints the ULID) with
     * `assigned_by` force-filled, rows for everyone else are deleted, and the
     * retained set is left untouched — its original assigned_by/created_at
     * survive.
     *
     * Returns the diff so the caller can notify: ADDED get TASK_ASSIGNED,
     * REMOVED get TASK_REASSIGNED, RETAINED get nothing. The ids are deduped
     * and compared against what the database holds NOW, not the loaded
     * relation. Callers own the surrounding DB::transaction and have already
     * validated that each id is an active employee.
     *
     * @param  iterable<array-key, string>  $employeeIds
     * @return array{added: list<string>, removed: list<string>}
     */
    public function syncAssignees(iterable $employeeIds, ?int $byUserId): array
    {
        $wanted = [];

        foreach ($employeeIds as $id) {
            $id = trim((string) $id);

            if ($id !== '' && ! in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }

        /** @var list<string> $current */
        $current = $this->assignments()->pluck('employee_id')->map(static fn ($id): string => (string) $id)->all();

        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        foreach ($added as $employeeId) {
            $row = new ProjectTaskAssignee;
            $row->fill(['task_id' => $this->getKey(), 'employee_id' => $employeeId]);
            $row->forceFill(['assigned_by' => $byUserId]);
            $row->save();
        }

        if ($removed !== []) {
            $this->assignments()->whereIn('employee_id', $removed)->delete();
        }

        // Whatever was loaded is stale now; the next read reloads.
        $this->unsetRelation('assignments');
        $this->unsetRelation('assignees');

        return ['added' => $added, 'removed' => $removed];
    }
}
