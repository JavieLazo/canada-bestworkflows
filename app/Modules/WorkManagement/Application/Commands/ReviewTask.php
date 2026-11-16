<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Modules\WorkManagement\Application\WorkNotifications as Notifier;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Task review and its decision comment form one operation, independent of the project screen.
 *
 * DELIBERATE DIVERGENCE: copems lets only an all-data PROJECTS holder decide
 * a task. Here the project's own Task Leader (`projects.manager_id`) decides
 * its tasks too (WorkRules::runsProject()), re-checked against the project
 * row locked after the task (P, after L1).
 *
 * DELIBERATE DIVERGENCE: copems lets any all-data PROJECTS holder accept or
 * return a task, including one they are assigned to. Here an assignee may
 * NOT accept, return or complete their own task
 * ({@see WorkRules::reviewsTask()}); cancelling it is still allowed. The crew
 * is read under the task-row lock (L1, then a locking membership read), so an
 * assignee added concurrently is either seen or waits for this decision to
 * commit.
 *
 * DELIBERATE DIVERGENCE: the moves follow the task's submission mode
 * (TaskPolicy::managerCanMove()). An INDIVIDUAL task is never accepted or
 * returned as a whole — its members' responses are, by ReviewTaskResponse —
 * and goes IN_PROGRESS → COMPLETED only through THE COMPLETION GATE: every
 * current assignee's response APPROVED, and at least one assignee. The gate
 * reads the crew and the responses with locking reads in the same
 * transaction as the status write, so a newcomer or a return that commits
 * first blocks it, and one that commits after finds the task closed.
 */
final class ReviewTask
{
    public function handle(Access $actor, string $projectId, string $taskId, string $decision, string $remarks): void
    {
        // Who runs the task's project: an all-data PROJECTS holder, or the
        // project's Task Leader. A plain, scoped read for the early refusal;
        // the leader is re-checked against the locked project row below.
        $seen = WorkRules::task($actor, $projectId, $taskId);
        if (! WorkRules::managesProject($actor, $seen?->project)) {
            if ($seen === null || ! WorkRules::leadsProject($actor, $seen->project)) {
                throw new RuntimeException(WorkRules::forbiddenMessage());
            }
            // A leader on the crew decides nothing on it, Cancel included.
            if (! WorkRules::runsTask($actor, $seen)) {
                throw new RuntimeException(WorkRules::selfReviewMessage());
            }
        }
        $remark = trim($remarks);
        if ($decision === 'RETURNED' && $remark === '') {
            throw new RuntimeException('Say what needs reworking before returning the task.');
        }
        if (mb_strlen($remark) > 8000) {
            throw new RuntimeException('review_remarks: must be at most 8000 characters');
        }
        $task = DB::transaction(static function () use ($actor, $projectId, $taskId, $decision, $remark): ProjectTask {
            $task = WorkRules::lockedTask($actor, $taskId, $projectId);
            if ($task === null) {
                throw new RuntimeException('That task no longer exists.');
            }
            // P, after L1: a leader named away while this request waited is
            // refused here, not decided for.
            $project = WorkRules::lockedProject($actor, $task->project_id);
            if (! WorkRules::managesProject($actor, $project)) {
                if (! WorkRules::leadsProject($actor, $project)) {
                    throw new RuntimeException(WorkRules::forbiddenMessage());
                }
                // L2: a leader added to the crew while this waited decides
                // nothing on it — Cancel included, unlike a manager.
                if (WorkRules::stillAssigned($task, $actor)) {
                    throw new RuntimeException(WorkRules::selfReviewMessage());
                }
            }
            if (! in_array($decision, ['COMPLETED', 'RETURNED', 'CANCELLED'], true)
                || ! TaskPolicy::managerCanMove($task->status, $decision, $task->submission_mode)) {
                throw new RuntimeException(TaskPolicy::transitionError());
            }
            // No self-review: the crew read locks, so it is the committed crew.
            if (in_array($decision, ['COMPLETED', 'RETURNED'], true)) {
                $crew = array_values(ProjectTaskAssignee::query()->where('task_id', $task->id)->lockForUpdate()
                    ->pluck('employee_id')->map(static fn ($id): string => (string) $id)->all());
                if ($actor->employeeId !== null && in_array($actor->employeeId, $crew, true)) {
                    throw new RuntimeException(WorkRules::selfReviewMessage());
                }
                // The completion gate, under the same locks as the write.
                if ($decision === 'COMPLETED' && TaskSubmissionPolicy::isIndividual($task->submission_mode)) {
                    $states = ProjectTaskSubmission::query()->where('task_id', $task->id)->lockForUpdate()
                        ->pluck('status', 'employee_id')->map(static fn ($status): string => (string) $status)->all();
                    $gateError = TaskSubmissionPolicy::completeError($crew, $states);
                    if ($gateError !== null) {
                        throw new RuntimeException($gateError);
                    }
                }
            }
            $data = [
                'status' => $decision,
                'reviewed_by' => $actor->userId === null ? null : (int) $actor->userId,
                'review_remarks' => $remark === '' ? null : $remark,
            ];
            if ($decision === 'COMPLETED') {
                $data['completed_at'] = CarbonImmutable::now()->format('Y-m-d H:i:s.v');
            }
            if ($decision === 'RETURNED') {
                $data['submitted_at'] = null;
            }
            $count = ProjectTask::query()->whereKey($task->id)->where('status', $task->status)->update($data);
            if ($count === 0) {
                throw new RuntimeException('That task changed before this action completed.');
            }
            AuditService::record($actor, AuditEvent::taskStatus($decision), 'project_tasks', $task->id,
                sprintf('Task %s "%s" '."\u{2192}".' %s', $task->code, $task->title, mb_strtolower($decision)));
            if ($remark !== '' && in_array($decision, ['RETURNED', 'COMPLETED'], true)) {
                $comment = new TaskComment;
                $comment->fill(['task_id' => $task->id, 'side' => 'MANAGEMENT', 'body' => $remark, 'event' => $decision]);
                $comment->author_id = $actor->userId === null ? null : (int) $actor->userId;
                $comment->author_name = mb_substr($actor->fullName !== '' ? $actor->fullName : 'Unknown', 0, 200);
                $comment->save();
                AuditService::record($actor, AuditEvent::TASK_COMMENTED, 'task_comments', $comment->id, 'Comment added to '.WorkRules::taskLabel($task));
            }

            return $task;
        });

        $outcome = match ($decision) {
            'COMPLETED' => 'accepted as complete', 'RETURNED' => 'returned for rework', default => 'cancelled',
        };
        $crew = array_values(array_filter($task->assigneeIds(), static fn (string $id): bool => $id !== $actor->employeeId));
        $label = trim($task->code) !== '' ? $task->code : $task->title;
        Notifier::employees($actor, $task, $crew, [], 'TASK_'.$decision, $label.' '.$outcome,
            $decision === 'RETURNED' ? $remark : $task->title, '/my-tasks');
    }
}
