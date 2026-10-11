<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\ManualTaskStatus;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A task status set by hand by whoever runs its project — a Projects manager,
 * or the project's Task Leader (WorkRules::runsProject()).
 *
 * DELIBERATE DIVERGENCE: the reference has no such move; its reviewer only
 * accepts, returns or cancels, as ReviewTask does. The moves and their
 * safeguards are ManualTaskStatus's map, keyed by the CURRENT status and the
 * task's mode — never a flat target list.
 *
 * Every fact the move depends on is read under WorkRules' lock protocol: the
 * task row first (L1), then its project row (P: the leader, and whether the
 * project is completed), then the crew (self-review) and, on an INDIVIDUAL
 * task, the responses (hand-ins, and the completion gate) — all locking
 * reads, as ReviewTask takes them. `$expectedFrom` is the status the dialog
 * showed: a task that moved since is refused rather than moved on from a
 * state nobody saw.
 *
 * The write clears whatever the new status would leave stale, and a closing
 * move stamps `reviewed_by` from the session and replaces `review_remarks`
 * with the note, as ReviewTask does (ManualTaskStatus::writes()). A note also
 * goes on the thread as a MANAGEMENT entry. The crew — never the actor — is
 * told after the commit.
 */
final class ChangeTaskStatus
{
    public function handle(Access $actor, string $projectId, string $taskId, string $to, string $note, string $expectedFrom): void
    {
        // Plain and scoped, for the early refusal only: whoever does not run
        // the task's project learns nothing about it, not even that it is gone.
        $seen = WorkRules::task($actor, $projectId, $taskId);
        if ($seen === null && WorkRules::managesProjects($actor)) {
            throw new RuntimeException('That task no longer exists.');
        }
        if ($seen === null || ! WorkRules::runsProject($actor, $seen->project)) {
            throw new RuntimeException(WorkRules::forbiddenMessage());
        }
        if (! WorkRules::runsTask($actor, $seen)) {
            throw new RuntimeException(WorkRules::selfReviewMessage());
        }
        $remark = trim($note);
        if (mb_strlen($remark) > 8000) {
            throw new RuntimeException('review_remarks: must be at most 8000 characters');
        }
        $noteError = ManualTaskStatus::noteError($to, $remark);
        if ($noteError !== null) {
            throw new RuntimeException($noteError);
        }

        /** @var array{0: ProjectTask, 1: string, 2: list<string>} $moved */
        $moved = DB::transaction(static function () use ($actor, $projectId, $taskId, $to, $remark, $expectedFrom): array {
            $task = WorkRules::lockedTask($taskId, $projectId);
            if ($task === null) {
                throw new RuntimeException('That task no longer exists.');
            }
            if ($task->status !== $expectedFrom) {
                throw new RuntimeException('That task changed before this action completed.');
            }
            $project = WorkRules::lockedProject($task->project_id);
            if ($project === null || ! WorkRules::runsProject($actor, $project)) {
                throw new RuntimeException(WorkRules::forbiddenMessage());
            }
            $crew = array_values(ProjectTaskAssignee::query()->where('task_id', $task->id)->lockForUpdate()
                ->pluck('employee_id')->map(static fn ($id): string => (string) $id)->all());
            // No self-review. A Task Leader on the crew has no say over the
            // task at all (WorkRules::runsTask()); a manager on it keeps the
            // moves that decide nothing — but nobody on the crew completes or
            // returns the work, or undoes a hand-in or an acceptance of it.
            $onCrew = $actor->employeeId !== null && in_array($actor->employeeId, $crew, true);
            if ($onCrew && (! WorkRules::managesProjects($actor) || ManualTaskStatus::isReviewMove($task->status, $to))) {
                throw new RuntimeException(WorkRules::selfReviewMessage());
            }
            $individual = TaskSubmissionPolicy::isIndividual($task->submission_mode);
            $states = $individual
                ? ProjectTaskSubmission::query()->where('task_id', $task->id)->lockForUpdate()
                    ->pluck('status', 'employee_id')->map(static fn ($status): string => (string) $status)->all()
                : [];
            $handedIn = ManualTaskStatus::handedIn(
                $task->submission_mode,
                TaskSubmissionPolicy::groupHandedIn($task->submitted_by, $task->submitted_at, $task->remarks),
                count($states),
            );
            $completionError = $individual && $to === 'COMPLETED' ? TaskSubmissionPolicy::completeError($crew, $states) : null;
            $moveError = ManualTaskStatus::moveError($task->status, $to, $task->submission_mode, $handedIn, $project->status, $completionError);
            if ($moveError !== null) {
                throw new RuntimeException($moveError);
            }
            $from = $task->status;
            // `reviewed_by` is an actor column: from the session, never input.
            $data = ManualTaskStatus::writes($from, $to, $actor->userId === null ? null : (int) $actor->userId, $remark,
                CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.v'));
            $count = ProjectTask::query()->whereKey($task->id)->where('status', $from)
                ->where('submission_mode', $task->submission_mode)->update($data);
            if ($count === 0) {
                throw new RuntimeException('That task changed before this action completed.');
            }
            AuditService::record($actor, AuditEvent::taskStatus($to), 'project_tasks', $task->id,
                sprintf('Task %s "%s" '."\u{2192}".' %s (set by hand, from %s)', $task->code, $task->title, mb_strtolower($to), mb_strtolower($from)));
            if ($remark !== '') {
                $comment = new TaskComment;
                $comment->fill(['task_id' => $task->id, 'side' => 'MANAGEMENT', 'body' => $remark, 'event' => $to]);
                $comment->author_id = $actor->userId === null ? null : (int) $actor->userId;
                $comment->author_name = mb_substr($actor->fullName !== '' ? $actor->fullName : 'Unknown', 0, 200);
                $comment->save();
                AuditService::record($actor, AuditEvent::TASK_COMMENTED, 'task_comments', $comment->id, 'Comment added to '.WorkRules::taskLabel($task));
            }

            return [$task, $from, $crew];
        });
        [$task, , $crew] = $moved;

        $others = array_values(array_filter($crew, static fn (string $id): bool => $id !== $actor->employeeId));
        Notifier::employees($others, [], 'TASK_'.$to,
            WorkRules::taskLabel($task).' set to '.mb_strtolower(TaskPolicy::statusLabel($to)),
            $remark !== '' ? $remark : $task->title, '/my-tasks');
    }
}
