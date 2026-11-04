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
 * The source owns a task by exactly one employee through a nullable
 * `assignee_id` column. Here membership lives in the `project_task_assignees`
 * pivot ({@see ProjectTaskAssignee}): "unassigned" means zero pivot rows, and
 * every assignee sees and starts the SAME task.
 *
 * `submission_mode` (GROUP | INDIVIDUAL) decides where the response lives.
 * copems has no such column; GROUP is the column default, so a task that
 * never chose a mode reads GROUP. UNLIKE `status`, IT IS NOT FREE TEXT: on
 * MySQL/MariaDB `tasks_submission_mode_chk` pins the two literal values, so a
 * third mode needs a new migration that drops and re-adds that CHECK. sqlite
 * never creates it, so the test suite passes either way.
 *
 *  - GROUP: one team response, ON THIS ROW. `status`, `remarks` and
 *    `submitted_at` stay one-per-task — the first assignee to submit submits
 *    for everyone — and `submitted_by` (an `employee_profiles` id) records who
 *    that was.
 *  - INDIVIDUAL: every assignee answers for themselves. Each member's latest
 *    response, and the decision on it, belongs in their own
 *    {@see ProjectTaskSubmission} row ({@see self::submissions()}), never in
 *    this row's `remarks` or `submitted_by`.
 *
 * The mode is chosen at creation (CreateTask, GROUP for a crew under two)
 * and can change only until anything has been handed in (UpdateTaskDetails).
 * On an INDIVIDUAL task SubmitTask writes the member's own row and never
 * this one, ReviewTaskResponse decides each row, and ReviewTask completes
 * the task only once every current assignee's row is APPROVED.
 *
 * `assigned_by` and `reviewed_by` are `users` ids. Both are ACTOR columns
 * (session-supplied, never mass-assigned) and are the reason this table
 * carries two foreign keys to the same table.
 *
 * `start_date` is the PLANNED start, typed by whoever hands out the work —
 * copems has no such column (DELIBERATE DIVERGENCE). Nullable: a task with
 * none simply has no plan yet. Nothing stamps it when an assignee
 * presses Start, and it never stops anyone starting early; the commands keep
 * it inside the project's window and on or before `due_date`
 * (WorkManagement's Domain\Tasks\TaskSchedule).
 *
 * `status` (PENDING | IN_PROGRESS | SUBMITTED | RETURNED | COMPLETED |
 * CANCELLED) and `priority` are free text. `TaskInput` only ever accepts
 * `status: "PENDING"` on create; every later transition is a server action.
 *
 * `remarks` and `review_remarks` hold the LATEST message only — the thread in
 * `task_comments` is what keeps the history of a GROUP task that bounces
 * twice. An INDIVIDUAL member's row is latest-only too, and its history must
 * NOT go in the thread: a member's notes are private to them and the
 * reviewers, so a writer that replaces a round may leave only a content-free
 * audit row behind.
 *
 * Row scoping goes THROUGH the pivot:
 *
 *     ProjectTask::query()->toEmployeeVia($actor, 'assignments', 'employee_id', ModuleCode::PROJECTS)
 *     Project::query()->toEmployeeVia($actor, 'tasks.assignments', 'employee_id', ModuleCode::PROJECTS)
 *
 * There is no employee column on this table, so `toEmployee()` has
 * nothing to point at here — always use the `Via` form.
 *
 * @property string $id
 * @property string $code
 * @property string $project_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property string $submission_mode
 * @property CarbonImmutable|null $start_date
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
 * @property-read Collection<int, ProjectTaskFile> $files
 * @property-read Collection<int, ProjectTaskSubmission> $submissions
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
        'submission_mode',
        'start_date',
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

    /**
     * The column's own DEFAULT 'GROUP', mirrored so that a task built in PHP
     * reads GROUP before it is saved and after it is created — the database
     * default is not read back into a model that has just been inserted.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'submission_mode' => 'GROUP',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
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

    /** @return HasMany<ProjectTaskFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ProjectTaskFile::class, 'task_id');
    }

    /**
     * The members' own responses to an INDIVIDUAL task, one row per member
     * who has submitted. A GROUP task has none.
     *
     * @return HasMany<ProjectTaskSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(ProjectTaskSubmission::class, 'task_id');
    }

    /** @return HasMany<TaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    /* ----------------------------- assignees ------------------------------ */

    /**
     * Employee ids from WorkManagement's membership rows, not Workforce's
     * employee models. Empty list = unassigned.
     *
     * @return list<string>
     */
    public function assigneeIds(): array
    {
        $ids = [];

        foreach ($this->assignments as $assignment) {
            $ids[] = (string) $assignment->employee_id;
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
     * A removed member's own response ({@see ProjectTaskSubmission}) is
     * deleted with their membership, so response rows are ALWAYS a subset of
     * the crew, whichever caller changed it. Their files stay: evidence goes
     * only with the task or the project.
     *
     * Returns the diff so the caller can notify: ADDED get TASK_ASSIGNED,
     * REMOVED get TASK_REASSIGNED, RETAINED get nothing. The ids are deduped
     * and compared against what the database holds NOW, not the loaded
     * relation. Callers own the surrounding DB::transaction and have already
     * validated that each id is an active employee.
     *
     * CALLERS MUST ALREADY HOLD THE TASK ROW LOCK (L1 in WorkRules' task lock
     * protocol) before the first plain read of their transaction. The pivot
     * and response reads here are plain REPEATABLE READ reads. They see the
     * latest committed rows only because no snapshot predates that lock and
     * every writer of those rows takes it too. UpdateTaskDetails opens its
     * transaction with WorkRules::lockedTask() (then the project row, P —
     * a locking read, so the snapshot is still unopened), CreateTask's
     * INSERT of the task row comes first, and ProjectSeeder runs with
     * nothing concurrent. A
     * caller that read anything plainly before locking could miss a response
     * committed in between, and a removed member's row would then survive.
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

            // Read the ids, then delete by primary key. A DELETE keyed on
            // (task, employee) would also take a gap lock on
            // `pts_task_employee_unq` for every removed member who never
            // submitted. That gap can reach into a NEIGHBOURING task's keys,
            // so a first submission there would wait on this transaction or
            // deadlock with it. Submissions on THIS task wait on the task row
            // lock anyway. The plain read is fresh only because the caller
            // already holds that lock (see the docblock).
            $responseIds = $this->submissions()->whereIn('employee_id', $removed)->pluck('id')->all();

            if ($responseIds !== []) {
                ProjectTaskSubmission::query()->whereKey($responseIds)->delete();
            }
        }

        // Whatever was loaded is stale now; the next read reloads.
        $this->unsetRelation('assignments');
        $this->unsetRelation('assignees');

        return ['added' => $added, 'removed' => $removed];
    }
}
