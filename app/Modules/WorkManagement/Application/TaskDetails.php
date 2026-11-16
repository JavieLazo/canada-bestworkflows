<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

/**
 * Only the fields supported by the existing task-details editor.
 *
 * `submissionMode` is null unless the reviewer CHANGED it: null leaves the
 * stored mode alone, so an edit that never touched the mode can neither
 * revert another reviewer's switch nor be refused by the mode lock.
 *
 * A null date leaves the stored value alone; '' clears it and a calendar
 * key sets it. Optional seen dates protect fields the editor actually
 * changed without reverting a peer's untouched date.
 */
final readonly class TaskDetails
{
    /** @param array<array-key, mixed> $assigneeIds */
    public function __construct(
        public string $priority,
        public ?string $dueDate,
        public array $assigneeIds,
        public ?string $submissionMode = null,
        public ?string $startDate = null,
        public ?string $seenStartDate = null,
        public ?string $seenDueDate = null,
    ) {}
}
