<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

/**
 * Draft task values; assignment ids are normalized and resolved by the operation.
 *
 * `submissionMode` is what the reviewer asked for (GROUP or INDIVIDUAL).
 * CreateTask validates it and stores GROUP whatever was asked when fewer than
 * two people are assigned; a caller that does not choose gets GROUP.
 *
 * `startDate` is the planned start, 'Y-m-d' or '' for none. Optional
 * grouping and parent context follow it to keep existing callers compatible.
 */
final readonly class TaskDraft
{
    /** @param array<array-key, mixed> $assigneeIds */
    public function __construct(
        public string $title,
        public string $description,
        public string $priority,
        public string $dueDate,
        public array $assigneeIds,
        public string $submissionMode = 'GROUP',
        public string $startDate = '',
        public ?string $groupId = null,
        public ?string $parentId = null,
    ) {}
}
