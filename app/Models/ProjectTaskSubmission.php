<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One assignee's own response to an INDIVIDUAL task — the per-member state a
 * GROUP task keeps on its own row.
 *
 * At most one row per (task, member), enforced by `pts_task_employee_unq`. It
 * holds the member's LATEST response and the latest decision on it: a
 * resubmission is to update the row in place and bump `revision`, the
 * optimistic token a decision must match. No row means "not submitted", so
 * `status` is only ever SUBMITTED, RETURNED or APPROVED (`pts_status_chk`).
 * Earlier rounds are not kept here, and a writer that replaces one may leave
 * only a content-free audit row behind.
 *
 * Rows are always a SUBSET of the task's membership: removing an assignee
 * deletes their row in the same call ({@see ProjectTask::syncAssignees()}),
 * so a re-added member starts again with no row and, on their next
 * submission, a new id.
 *
 * `employee_id` is fillable: like `project_tasks.submitted_by`, it is an
 * employee id the server resolves from the signed-in actor's membership,
 * never from a request body. `reviewed_by` is an ACTOR column — the account
 * that decided, session-supplied and never mass-assigned. ModelIntegrityTest
 * forbids it in $fillable; writers set it by property or in a guarded
 * builder update.
 *
 * NO `ScopesToActor`, deliberately, following {@see ProjectTaskAssignee}.
 * Reads go through the parent task, behind its gate, and who may see a
 * response's CONTENT is not "whose row is it" alone: the member sees their
 * own and a reviewer sees everyone's. EVERY WorkManagement read of this table
 * must apply that rule itself; no model scope does it for them.
 *
 * The READ side of that rule lives in WorkRecords: responses() drops a peer's
 * content, and files(), counts() and download() keep a peer's evidence to
 * that member and the reviewer. Every other read — the per-task counts, the
 * queues and counters, the workload and My Tasks statuses — touches only
 * `status`, never a row's content.
 *
 * The WRITERS, each under the task-row lock (L1 in WorkRules' lock
 * protocol): SubmitTask inserts a member's first response or resubmits a
 * returned one, ReviewTaskResponse approves or returns it and sets
 * `reviewed_by` from the session, and {@see ProjectTask::syncAssignees()}
 * deletes a removed member's row. The first two write a TASK_RESPONSE_*
 * audit row with no content; a removal leaves only a count of discarded
 * responses on the task's TASK_UPDATED row. The account-deletion guard
 * reads `reviewed_by`.
 *
 * Only `task()` is declared. There is no `employee()` relation on purpose:
 * `employee_profiles` belongs to Workforce, and WorkManagement reads employee
 * facts through that module's contracts, never through an Eloquent relation.
 *
 * @property string $id
 * @property string $task_id
 * @property string $employee_id
 * @property string $status
 * @property string|null $remarks
 * @property CarbonImmutable $submitted_at
 * @property int|null $reviewed_by
 * @property string|null $review_remarks
 * @property CarbonImmutable|null $reviewed_at
 * @property int $revision
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ProjectTask|null $task
 */
class ProjectTaskSubmission extends BaseModel
{
    /** @var string */
    protected $table = 'project_task_submissions';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        // An employee id, not an actor column: the writer resolves it from
        // the membership pivot, never from a request body.
        'employee_id',
        'status',
        'remarks',
        'submitted_at',
        'review_remarks',
        'reviewed_at',
        'revision',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reviewed_by' => 'integer',
            'revision' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProjectTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }
}
