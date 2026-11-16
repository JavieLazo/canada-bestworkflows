<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\WorkManagement\Domain\Tasks\TaskInbox;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Detached ProjectTask data, with no persistence or lazy relationships.
 *
 * The `responses_*` counts and `has_submission_files` are CONTENT-FREE facts
 * about an INDIVIDUAL task's responses — how many rows sit in each state, and
 * whether any evidence was handed in — so every viewer who may see the task
 * may see them. They are loaded on the task reads (WorkRecords::tasks(),
 * task(), tasksByIds()) and are null on My Tasks page rows. `my_status` is
 * the other way round: only My Tasks page rows (WorkRecords::ownPage()) carry
 * it, the status the signed-in assignee sees for their own task.
 *
 * @property-read Collection<int, DirectoryEmployee> $assignees
 * @property-read ProjectView|null $project
 * @property-read DirectoryEmployee|null $submitter
 * @property-read AccountLabel|null $assigner
 * @property-read AccountLabel|null $reviewer
 * @property-read string $id
 * @property-read string $code
 * @property-read string $project_id
 * @property-read string|null $group_id
 * @property-read string|null $group_name
 * @property-read string|null $effective_group_id
 * @property-read string|null $parent_id
 * @property-read TaskParentView|null $parent
 * @property-read bool|null $has_subtasks
 * @property-read int|null $subtasks_count
 * @property-read int|null $subtasks_completed
 * @property-read int|null $subtasks_cancelled
 * @property-read string $title
 * @property-read string|null $description
 * @property-read string $status
 * @property-read string $priority
 * @property-read string $submission_mode
 * @property-read CarbonImmutable|null $start_date
 * @property-read CarbonImmutable|null $due_date
 * @property-read int|null $assigned_by
 * @property-read CarbonImmutable $assigned_at
 * @property-read CarbonImmutable|null $submitted_at
 * @property-read string|null $submitted_by
 * @property-read CarbonImmutable|null $completed_at
 * @property-read int|null $reviewed_by
 * @property-read string|null $remarks
 * @property-read string|null $review_remarks
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read int|null $responses_submitted
 * @property-read int|null $responses_approved
 * @property-read int|null $responses_returned
 * @property-read bool|null $has_submission_files
 * @property-read string|null $my_status
 * @property-read string|null $my_response_status
 */
final readonly class ProjectTaskView extends Record
{
    public function inboxBucket(string $today): string
    {
        return TaskInbox::bucket($this->my_status ?? $this->status, $this->start_date?->format('Y-m-d'), $this->due_date?->format('Y-m-d'), $today);
    }

    public function ownAction(): string
    {
        $moves = TaskPolicy::employeeCanMoveTo($this->status, $this->submission_mode);
        if (in_array('IN_PROGRESS', $moves, true)) {
            return 'start';
        }

        return ($this->isIndividual() ? TaskSubmissionPolicy::memberCanMove($this->status, $this->my_response_status, 'SUBMITTED') : in_array('SUBMITTED', $moves, true)) ? 'respond' : 'view';
    }

    public function isSubtask(): bool
    {
        return $this->parent_id !== null;
    }

    /** @return array{total: int, completed: int, percent: int} */
    public function subtaskProgress(): array
    {
        return ProjectPolicy::progressCounts((int) $this->subtasks_count - (int) $this->subtasks_cancelled, (int) $this->subtasks_completed);
    }

    /** @return list<string> */
    public function assigneeIds(): array
    {
        $ids = $this->assignees->pluck('id')->all();
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function hasAssignee(?string $id): bool
    {
        return $id !== null && $id !== '' && in_array($id, $this->assigneeIds(), true);
    }

    public function isIndividual(): bool
    {
        return TaskSubmissionPolicy::isIndividual($this->submission_mode);
    }

    /** Whether the task waits on a reviewer — the PHP twin of WorkScope::NEEDS_REVIEW_SQL. */
    public function awaitsReview(): bool
    {
        return TaskSubmissionPolicy::awaitsReview($this->submission_mode, $this->status, (int) $this->responses_submitted);
    }

    /** How many response rows the task holds, whatever their state. */
    public function responsesTotal(): int
    {
        return (int) $this->responses_submitted + (int) $this->responses_approved + (int) $this->responses_returned;
    }

    /** @return array{assignees: int, approved: int, submitted: int, returned: int, not_submitted: int} */
    public function responseProgress(): array
    {
        return TaskSubmissionPolicy::progress(
            count($this->assignees),
            (int) $this->responses_submitted,
            (int) $this->responses_approved,
            (int) $this->responses_returned,
        );
    }

    /** "2 of 3 approved · 1 awaiting review" — "1 submitted" once the task is closed. */
    public function responseSummary(): string
    {
        return TaskSubmissionPolicy::progressSentence($this->responseProgress(), $this->status);
    }

    /** The signed-in assignee's own status label — the task's where no `my_status` was read. */
    public function myStatusLabel(): string
    {
        return TaskSubmissionPolicy::myStatusLabel($this->my_status ?? $this->status);
    }

    public function myStatusPill(): string
    {
        return TaskSubmissionPolicy::myStatusPill($this->my_status ?? $this->status);
    }
}
