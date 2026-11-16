<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\TaskDiscussion;
use App\Modules\WorkManagement\Application\WorkNotifications as Notifier;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Task operation with server-derived ownership, transition guards and side effects.
 *
 * A GROUP task is handed in once, for the whole crew, on the task row. On an
 * INDIVIDUAL task the actor hands in THEIR OWN response instead
 * ({@see self::submitResponse()}): the task stays IN_PROGRESS, nothing is
 * posted to the shared thread. Current reviewers and the eligible assigner
 * hear about the hand-in without receiving a private response note.
 */
final class SubmitTask
{
    public function handle(Access $access, ?string $taskId, string $remarks): bool
    {
        abort_unless($access->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS), 403);
        $note = trim($remarks);
        if ($note === '') {
            throw new RuntimeException('Say what you did before submitting.');
        }
        if (mb_strlen($note) > 8000) {
            throw new RuntimeException('remarks: must be at most 8000 characters.');
        }
        $task = WorkRules::ownTask($access, $taskId);
        if ($task === null) {
            return false;
        }
        if (TaskSubmissionPolicy::isIndividual($task->submission_mode)) {
            return $this->submitResponse($access, $task, $note);
        }
        if (! TaskPolicy::employeeCanMove($task->status, 'SUBMITTED')) {
            throw new RuntimeException(TaskPolicy::transitionError());
        }
        $updated = ProjectTask::query()->whereKey($task->id)->where('status', $task->status)
            // A task switched to INDIVIDUAL since it was read is refused here
            // rather than handed in for everyone.
            ->where('submission_mode', TaskPolicy::MODE_GROUP)
            ->update([
                'status' => 'SUBMITTED',
                // Pre-formatted so the DATETIME(3) column keeps its
                // milliseconds — a raw builder update bypasses the model's
                // $dateFormat and the base grammar would write whole seconds.
                'submitted_at' => now()->format('Y-m-d H:i:s.v'),
                // Which ASSIGNEE submitted — resolved from the session, and
                // WorkRules::ownTask() has already proven the actor is on the
                // pivot.
                'submitted_by' => $access->ownEmployeeId(),
                'remarks' => $note,
            ]);
        if ($updated === 0) {
            throw new RuntimeException('That task changed before this action completed.');
        }
        AuditService::record($access, AuditEvent::taskStatus('SUBMITTED'), 'project_tasks', $task->id, sprintf('Task %s "%s" → submitted', $task->code, $task->title));
        // The thread copy of the note — fail-soft, exactly as the dialog's
        // try/catch around createTaskComment (task-detail-dialog.tsx:214-231).
        try {
            $comment = TaskDiscussion::append($access, $task, $note, 'SUBMITTED', mb_substr($access->fullName, 0, 200));
            AuditService::record($access, AuditEvent::TASK_COMMENTED, 'task_comments', $comment->id, 'Comment added to '.WorkRules::taskLabel($task));
        } catch (Throwable $e) {
            Log::error('submission comment write failed', ['task' => $task->id, 'error' => $e->getMessage()]);
        }
        $told = Notifier::submitted($access, $task, $note);
        Notifier::employees($access, $task, WorkRules::crewExcept($task, $access), [$access->userId, ...$told], 'TASK_SUBMITTED', WorkRules::actorName($task, $access).' submitted '.WorkRules::taskLabel($task).' for review', $note, '/my-tasks');

        return true;
    }

    /**
     * The actor's own response on an INDIVIDUAL task: the first hand-in
     * inserts their row at revision 1, a resubmission after a return updates
     * it in place and bumps the revision (keeping the last review until the
     * next decision replaces it). The task row is not written, so the task
     * stays IN_PROGRESS for everyone else.
     */
    private function submitResponse(Access $access, ProjectTask $task, string $note): bool
    {
        $employeeId = $access->ownEmployeeId();
        $row = DB::transaction(static function () use ($access, $task, $note, $employeeId): ProjectTaskSubmission {
            // L1: the task row first, then everything else is fresh.
            $locked = WorkRules::lockedTask($access, $task->id);
            if ($locked === null || ! TaskSubmissionPolicy::isIndividual($locked->submission_mode)) {
                throw new RuntimeException('That task changed before this action completed.');
            }
            // Stamped once the lock is held, so evidence that committed
            // while this request waited is always older than the hand-in.
            $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.v');
            // L2: a removal that committed while this request waited is seen.
            if (! WorkRules::stillAssigned($locked, $access)) {
                throw new RuntimeException('You are no longer assigned to this task.');
            }
            // L3 — a PLAIN read, on purpose. The row may not exist yet, and a
            // locking read of a missing key takes a gap lock on
            // pts_task_employee_unq: two members handing in their first
            // response on two different tasks whose keys share that gap
            // would then each wait on the other's insert and deadlock (1213).
            // It is still fresh — nothing was read before L1, and every
            // writer of this row holds L1 — and the unique index refuses a
            // second insert whatever happens.
            $mine = ProjectTaskSubmission::query()->where('task_id', $locked->id)->where('employee_id', '=', $employeeId)->first();
            if (! TaskSubmissionPolicy::memberCanMove($locked->status, $mine?->status, 'SUBMITTED')) {
                throw new RuntimeException(TaskSubmissionPolicy::transitionError());
            }
            if ($mine === null) {
                $row = new ProjectTaskSubmission;
                $row->fill(['task_id' => $locked->id, 'employee_id' => $employeeId, 'status' => 'SUBMITTED', 'remarks' => $note, 'submitted_at' => $now, 'revision' => 1]);
                try {
                    $row->save();
                } catch (QueryException $e) {
                    // pts_task_employee_unq already holds this member's row:
                    // a conflict, not a failure. Anything else propagates.
                    if (($e->errorInfo[0] ?? null) !== '23000') {
                        throw $e;
                    }
                    throw new RuntimeException('That task changed before this action completed.');
                }
            } else {
                // L4: the row must still be in the state and revision read.
                $count = ProjectTaskSubmission::query()->whereKey($mine->id)
                    ->where('status', $mine->status)->where('revision', $mine->revision)
                    ->update(['status' => 'SUBMITTED', 'remarks' => $note, 'submitted_at' => $now, 'revision' => $mine->revision + 1]);
                if ($count === 0) {
                    throw new RuntimeException('That task changed before this action completed.');
                }
                $row = $mine;
            }
            // Content-free: the note is the member's and the reviewers', and
            // the audit trail is neither.
            AuditService::record($access, AuditEvent::taskResponse('SUBMITTED'), 'project_task_submissions', $row->id, sprintf('Task %s "%s" — response submitted', $locked->code, $locked->title));

            return $row;
        });
        // A private response never enters the shared thread or notification body.
        Notifier::submitted($access, $task);

        return true;
    }
}
