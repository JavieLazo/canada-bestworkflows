<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * One current assignee's response to an INDIVIDUAL task, as ONE viewer may
 * see it.
 *
 * THE PRIVACY RULE: a response's CONTENT — its row id, the member's note, the
 * reviewer's note and who wrote it, the timestamps and the revision token — is
 * visible only to that member and to the task's reviewer (a PROJECTS manager,
 * or the project's Task Leader, who is not on the crew —
 * WorkRules::reviewsTask()). Everyone else on the task
 * sees who is on it and each member's STATE, nothing more (the task's own
 * status rides along too; anyone who can see the task sees that anyway).
 *
 * So the content keys are not blanked but ABSENT when `content_visible` is
 * false: toArray() cannot hand a peer's note to a Blade loop, a JSON payload
 * or a Livewire property by accident. That is also why this view is built
 * through {@see self::make()} from explicit values and never through
 * Snapshot::of(), which copies every column a row happens to hold.
 *
 * @property-read string $employee_id
 * @property-read string $status NONE (no row), SUBMITTED, RETURNED or APPROVED
 * @property-read string $task_status the task's own status — content-free; with `status` it is what TaskSubmissionPolicy::responseAwaitsReview() reads
 * @property-read bool $is_self
 * @property-read bool $content_visible
 * @property-read string|null $id content — null while the member has no row
 * @property-read string|null $remarks content
 * @property-read string|null $review_remarks content
 * @property-read int|null $reviewed_by content
 * @property-read CarbonImmutable|null $submitted_at content
 * @property-read CarbonImmutable|null $reviewed_at content
 * @property-read int|null $revision content
 */
final readonly class TaskResponseView extends Record
{
    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        parent::__construct($values);
    }

    /**
     * The only way to build one: the constructor is private. `$content` is
     * the stored row's content, or null when the member has no row; it is
     * DROPPED unless `$contentVisible`, whatever the caller passed.
     *
     * @param  array{id: string, remarks: string|null, review_remarks: string|null, reviewed_by: int|null, submitted_at: CarbonImmutable|null, reviewed_at: CarbonImmutable|null, revision: int}|null  $content
     */
    public static function make(string $employeeId, ?string $status, string $taskStatus, bool $isSelf, bool $contentVisible, ?array $content): self
    {
        $values = [
            'employee_id' => $employeeId,
            'status' => $status ?? TaskSubmissionPolicy::NONE,
            'task_status' => $taskStatus,
            'is_self' => $isSelf,
            'content_visible' => $contentVisible,
        ];

        if ($contentVisible) {
            $values += [
                'id' => $content['id'] ?? null,
                'remarks' => $content['remarks'] ?? null,
                'review_remarks' => $content['review_remarks'] ?? null,
                'reviewed_by' => $content['reviewed_by'] ?? null,
                'submitted_at' => $content['submitted_at'] ?? null,
                'reviewed_at' => $content['reviewed_at'] ?? null,
                'revision' => $content['revision'] ?? null,
            ];
        }

        return new self($values);
    }

    public function label(): string
    {
        return TaskSubmissionPolicy::memberStatusLabel($this->status);
    }

    public function pill(): string
    {
        return TaskSubmissionPolicy::memberStatusPill($this->status);
    }
}
