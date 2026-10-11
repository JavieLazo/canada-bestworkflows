<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

/**
 * Untrusted form values; project commands validate every field and reference.
 *
 * On an EDIT, `status` and `startDate` may be null: "not touched". The
 * project's Task Leader changes those two concurrently with a manager's edit
 * dialog (ChangeProjectStatus, ScheduleProject), so the dialog posts them only
 * when the manager changed them — an untouched field keeps whatever the row
 * holds when the save lands, instead of reverting the leader's change.
 * CreateProject always gets both.
 */
final readonly class ProjectDraft
{
    public function __construct(
        public string $name,
        public string $description,
        public string $siteId,
        public ?string $status,
        public ?string $startDate,
        public string $dueDate,
        public string $managerId,
    ) {}
}
