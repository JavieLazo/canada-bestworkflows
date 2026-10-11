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
 * `startDate` works the same way: null leaves the stored start date alone,
 * '' clears it, and a 'Y-m-d' key sets it. Appended last, and null by
 * default, so a caller that predates it can never clear a start date it
 * never saw.
 */
final readonly class TaskDetails
{
    /** @param array<array-key, mixed> $assigneeIds */
    public function __construct(public string $priority, public string $dueDate, public array $assigneeIds, public ?string $submissionMode = null, public ?string $startDate = null) {}
}
