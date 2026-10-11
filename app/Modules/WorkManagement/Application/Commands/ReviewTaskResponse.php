<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskSubmission;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A reviewer's decision on ONE member's response to an INDIVIDUAL task:
 * approve it, or return it for rework with a note.
 *
 * DELIBERATE DIVERGENCE: the reference reviews a whole task at once and has
 * no per-member response (copems prisma/schema.prisma:401-402). A GROUP task
 * is decided by ReviewTask as a whole. Here each response is decided on its
 * own, and the task completes later through ReviewTask once every current
 * assignee's response is approved.
 *
 * The screen posts the row id and the revision it rendered. A remove and
 * re-add of the member gives their next response a NEW row id, and a return
 * followed by a resubmission bumps the revision, so a decision made on a
 * response the reviewer never saw is refused instead of landing on it.
 *
 * Who decides: an all-data PROJECTS holder, or the project's Task Leader
 * (WorkRules::runsProject(); DELIBERATE DIVERGENCE, see ReviewTask), the
 * leader re-checked on the project row locked after the task.
 *
 * Nobody decides on work they share: an actor on the task's crew is refused,
 * read under the task-row lock like ReviewTask's refusal. `reviewed_by` comes
 * from the session. Nothing is posted to the task's thread — the note is
 * between the reviewer and that member — and only that member is told.
 */
final class ReviewTaskResponse
{
    private const STALE = 'That response changed since you opened it — reopen the task and review it again.';

    public function handle(Access $actor, string $projectId, string $taskId, string $responseId, string $decision, string $remarks, int $revision): void
    {
        // Who runs the task's project: an all-data PROJECTS holder, or the
        // project's Task Leader — re-checked on the locked project row below.
        if (! WorkRules::managesProjects($actor)) {
            $seen = WorkRules::task($actor, $projectId, $taskId);
            if ($seen === null || ! WorkRules::leadsProject($actor, $seen->project)) {
                throw new RuntimeException(WorkRules::forbiddenMessage());
            }
        }
        // Vocabulary only: whether the move is legal is the map's call.
        if (! in_array($decision, ['APPROVED', 'RETURNED'], true)) {
            throw new RuntimeException(TaskSubmissionPolicy::transitionError());
        }
        $remark = trim($remarks);
        if ($decision === 'RETURNED' && $remark === '') {
            throw new RuntimeException('Say what needs reworking before returning this response.');
        }
        if (mb_strlen($remark) > 8000) {
            throw new RuntimeException('review_remarks: must be at most 8000 characters');
        }
        /** @var array{0: ProjectTask, 1: ProjectTaskSubmission} $decided */
        $decided = DB::transaction(static function () use ($actor, $projectId, $taskId, $responseId, $decision, $remark, $revision): array {
            // L1.
            $task = WorkRules::lockedTask($taskId, $projectId);
            if ($task === null) {
                throw new RuntimeException('That task no longer exists.');
            }
            // P, after L1: the leader is re-read under lock.
            if (! WorkRules::managesProjects($actor) && ! WorkRules::leadsProject($actor, WorkRules::lockedProject($task->project_id))) {
                throw new RuntimeException(WorkRules::forbiddenMessage());
            }
            if (! TaskSubmissionPolicy::isIndividual($task->submission_mode)) {
                throw new RuntimeException(TaskPolicy::transitionError());
            }
            // L2: the committed crew. No self-review.
            $crew = ProjectTaskAssignee::query()->where('task_id', $task->id)->lockForUpdate()
                ->pluck('employee_id')->map(static fn ($id): string => (string) $id)->all();
            if ($actor->hasEmployeeLink() && in_array($actor->ownEmployeeId(), $crew, true)) {
                throw new RuntimeException(WorkRules::selfReviewMessage());
            }
            // By its id, pinned to this task: a row that is gone — or was
            // replaced by a re-added member's new one — is a stale click.
            $row = ProjectTaskSubmission::query()->whereKey($responseId)->where('task_id', $task->id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException(self::STALE);
            }
            // Defensive: rows are a subset of the crew (syncAssignees).
            if (! in_array($row->employee_id, $crew, true)) {
                throw new RuntimeException('That employee is no longer assigned to this task.');
            }
            if (! TaskSubmissionPolicy::reviewerCanMove($task->status, $row->status, $decision)) {
                throw new RuntimeException(TaskSubmissionPolicy::transitionError());
            }
            if ($row->revision !== $revision) {
                throw new RuntimeException(self::STALE);
            }
            // L4. `reviewed_by` is an actor column: set here from the
            // session, never mass-assigned.
            $count = ProjectTaskSubmission::query()->whereKey($row->id)
                ->where('status', $row->status)->where('revision', $revision)
                ->update([
                    'status' => $decision,
                    'review_remarks' => $remark === '' ? null : $remark,
                    'reviewed_by' => $actor->userId === null ? null : (int) $actor->userId,
                    'reviewed_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.v'),
                    'revision' => $revision + 1,
                ]);
            if ($count === 0) {
                throw new RuntimeException(self::STALE);
            }
            $member = app(AssignmentEmployees::class)->names([$row->employee_id])[$row->employee_id] ?? 'An assignee';
            AuditService::record($actor, AuditEvent::taskResponse($decision), 'project_task_submissions', $row->id,
                sprintf('Task %s "%s" — %s\'s response %s', $task->code, $task->title, $member, $decision === 'APPROVED' ? 'approved' : 'returned for rework'));

            return [$task, $row];
        });
        [$task, $row] = $decided;

        // Only the member whose response it is, on every account they sign
        // in with — never the reviewer, and never the rest of the crew.
        $label = WorkRules::taskLabel($task);
        Notifier::employees([$row->employee_id], [$actor->userId], 'TASK_RESPONSE_'.$decision,
            $decision === 'APPROVED' ? "Your response on {$label} was approved" : "Your response on {$label} was returned for rework",
            $decision === 'RETURNED' ? $remark : ($remark !== '' ? $remark : 'The task completes once every response is approved.'),
            '/my-tasks');
    }
}
